const fs=require('node:fs'),path=require('node:path'),os=require('node:os'),net=require('node:net'),assert=require('node:assert/strict');
const {spawn,execFileSync}=require('node:child_process');const {request}=require('playwright');
const root=path.resolve(__dirname,'..').replaceAll('\\','/'),php='C:/xampp/php/php.exe';
const stamp=require('node:crypto').randomBytes(6).toString('hex'),db='winapp_fac_'+stamp;
const temp=fs.mkdtempSync(path.join(os.tmpdir(),'winapp-fac-'));
const instanceRoot=path.join(root,'mini apps/EGMs');fs.mkdirSync(instanceRoot,{recursive:true});
const folder='__winapp-test-'+stamp;const folderPath=path.join(instanceRoot,folder);fs.mkdirSync(folderPath);
const q=value=>"'"+String(value).replaceAll('\\','/').replaceAll("'","\\'")+"'";
const dsn=`mysql:host=127.0.0.1;port=3306;dbname=${db};charset=utf8mb4`;
const connection=`new PDO(${q(dsn)},'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION])`;
const bootstrap=`require ${q(root+'/api/lib/egm-winapp-access.php')};require ${q(root+'/api/lib/egm-staff-roles.php')}; $pdo=${connection};$GLOBALS['panelPermissionPdo']=$pdo; $cache=&egmInstanceEnsureCache();foreach(['00000','12345'] as $code)$cache[spl_object_id($pdo).':'.$code]=true;`;
function run(code){return execFileSync(php,['-r',code],{encoding:'utf8',windowsHide:true});}
function update(code,changes){run(bootstrap+`$rows=egmFacilitatorsRead($pdo,${q(code)});$rows[0]=array_merge($rows[0],json_decode(${q(JSON.stringify(changes))},true));egmInstanceWriteData($pdo,${q(code)},EGM_FACILITATORS_KEY,$rows);`);}
let server;
(async()=>{
 run(`$pdo=new PDO('mysql:host=127.0.0.1;port=3306','root','');$pdo->exec('CREATE DATABASE ${db} CHARACTER SET utf8mb4');`);
 run(bootstrap+`
 ensureEgmRegistryTable($pdo);
 $insert=$pdo->prepare('INSERT INTO egm(code,name,directory) VALUES(?,?,?)');
 $insert->execute(['00000','رویداد اول','mini apps/Event Guest Manager']);
 $insert->execute(['12345','رویداد دیگر',${q('mini apps/EGMs/'+folder)}]);
 foreach(['00000','12345'] as $code){$pdo->exec("CREATE TABLE egm_$code (data_key VARCHAR(128) PRIMARY KEY,payload LONGTEXT,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
 $row=['id'=>str_repeat($code==='00000'?'a':'b',32),'username'=>'helper','fullname'=>'تسهیلگر آزمایشی','password_hash'=>password_hash($code==='00000'?'test-pass':'other-pass',PASSWORD_DEFAULT),'auth_version'=>'v1','win_app_access_level'=>'scan_and_details'];
 egmInstanceWriteData($pdo,$code,EGM_FACILITATORS_KEY,[$row]);egmInstanceWriteData($pdo,$code,'development_instance', ['enabled'=>true]);}
 $pdo->exec('CREATE TABLE users(code VARCHAR(64),username VARCHAR(128),fullname VARCHAR(128),phone VARCHAR(32),email VARCHAR(128),id_number VARCHAR(32),work_id VARCHAR(32),password_hash VARCHAR(255),permissions LONGTEXT)');
 $insert=$pdo->prepare('INSERT INTO users VALUES(?,?,?,?,?,?,?,?,?)');$insert->execute(['admin','admin','مدیر','phone','','','',password_hash('admin-pass',PASSWORD_DEFAULT),'["event-guest-manager"]']);
 `);
 run(bootstrap+`
 $event=findEgmRegistryByCode($pdo,'12345');
 if(egmStaffPanelCanAccess(['permissions'=>['home','settings']],$event))throw new Exception('Simple panel user gained event access');
 if(egmStaffPanelCanAccess(['permissions'=>['event-guest-manager-mission-12345','event-guest-manager-mission-12345:main']],findEgmRegistryByCode($pdo,'00000')))throw new Exception('Role eligibility leaked across events');
 if(!egmStaffPanelCanAccess(['permissions'=>['event-guest-manager-mission-12345','event-guest-manager-mission-12345:main']],$event))throw new Exception('Scoped panel user missing');
 if(egmStaffPanelUsers($pdo,'12345')[0]['event_role']!=='referee')throw new Exception('Default panel role invalid');
 egmStaffSavePanelRole($pdo,'12345','admin','system_manager');
 if(egmStaffPanelRole($pdo,'12345','admin')!=='system_manager'||egmStaffPanelRole($pdo,'00000','admin')!=='referee')throw new Exception('Panel roles not event scoped');
 try{egmStaffSavePanelRole($pdo,'12345','absent','head_referee');throw new Exception('Unknown user accepted');}catch(InvalidArgumentException $ok){}
 $pdo->exec("UPDATE users SET permissions='[\\\"home\\\",\\\"settings\\\"]' WHERE code='admin'");
 if(egmStaffPanelUsers($pdo,'12345'))throw new Exception('Revoked panel user still eligible');
 try{egmStaffSavePanelRole($pdo,'12345','admin','head_referee');throw new Exception('Revoked user role accepted');}catch(InvalidArgumentException $ok){}
 $pdo->exec("UPDATE users SET permissions='[\\\"event-guest-manager\\\"]' WHERE code='admin'");
 `);
 // Real endpoint, isolated DB/branding/context; production authentication, policies and API routing remain unchanged.
 let source=fs.readFileSync(path.join(root,'api/winapp.php'),'utf8');
 source=source.replaceAll('__DIR__',q(root+'/api'))
  .replace(`$pdo = connectDatabase(loadConfig(${q(root+'/api')} . '/config.php'));`,`$pdo = ${connection}; $GLOBALS['panelPermissionPdo']=$pdo; $cache=&egmInstanceEnsureCache();foreach(['00000','12345'] as $code)$cache[spl_object_id($pdo).':'.$code]=true;`)
  .replace('$pdo = connectDatabase($config);',`$pdo = ${connection};`)
  .replace('$context = egmCheckInContext(dirname('+q(root+'/api')+'), $missionDirectory);',"$context = ['pdo'=>$pdo,'code'=>$eventCode];");
 fs.writeFileSync(path.join(temp,'winapp.php'),source);
 const port=await new Promise(resolve=>{const socket=net.createServer();socket.listen(0,'127.0.0.1',()=>{const p=socket.address().port;socket.close(()=>resolve(p));});});
 server=spawn(php,['-S',`127.0.0.1:${port}`,'-t',temp],{windowsHide:true,stdio:['ignore','ignore','pipe']});
 server.stderr.on('data',data=>{if(String(data).includes('WinApp API failed:'))process.stderr.write(data);});
 const client=await request.newContext();const url=`http://127.0.0.1:${port}/winapp.php`;
 try {
  for(let i=0;i<30;i++){try{await client.get(url);break;}catch{await new Promise(r=>setTimeout(r,100));}}
  const call=async(action,fields={})=>{const response=await client.post(url,{data:{action,...fields}});const body=await response.text();try{return JSON.parse(body);}catch{throw new Error(body);}};
  assert.equal((await call('login',{username:'helper',password:'bad'})).status,'error');
  let login=await call('login',{username:'helper',password:'test-pass'});assert.equal(login.status,'ok',JSON.stringify(login));assert.match(login.user.code,/^facilitator:/);
  let state=await client.storageState();assert.ok(state.cookies.some(c=>c.name==='EGMWINAPPSESSID'));assert.ok(!state.cookies.some(c=>c.name==='PHPSESSID'),'Desktop login must not replace panel authentication');
  let events=await call('events');assert.equal(events.status,'ok',JSON.stringify(events));assert.deepEqual(events.events.map(e=>e.code),['00000'],'Only credential-proven EGM is listed');
  assert.equal(events.events[0].access.level,'scan_and_details');assert.equal(events.events[0].access.can_manage_settings,false);assert.equal(events.events[0].access.can_use_printer,false);
  assert.equal(events.events[0].event_role,'referee');
  update('00000',{event_role:'head_referee'});events=await call('events');assert.equal(events.events[0].event_role,'head_referee');assert.equal(events.events[0].access.level,'scan_and_details','Event role must not override Windows access');
  let denied=await call('save_print_settings',{event_code:'00000',csrf:login.csrf});assert.equal(denied.http_status,403,'Details role cannot change print settings');
  denied=await call('event_status',{event_code:'12345'});assert.equal(denied.http_status,403,'Guessing another event code cannot bypass membership');
  denied=await call('pending_invitees',{event_code:'12345'});assert.equal(denied.http_status,403);
  for(const level of ['scan_only','scan_and_event','scan_and_details','full_access']){
   update('00000',{win_app_access_level:level});events=await call('events');const access=events.events[0].access;
   assert.equal(access.level,level,'Permission changes apply without relogin');assert.equal(access.can_view_user_info,['full_access','scan_and_details'].includes(level));assert.equal(access.can_manage_settings,level==='full_access');assert.equal(access.can_use_printer,level==='full_access');assert.equal(access.can_view_event_info,level!=='scan_only');
  }
  update('00000',{auth_version:'v2'});assert.equal((await call('session')).code,'unauthenticated','Credential version change revokes current login');
  login=await call('login',{username:'helper',password:'test-pass'});assert.equal(login.status,'ok');
  // Same account details in a second event with a different password remain inaccessible.
  events=await call('events');assert.equal(events.events.length,1);
  update('12345',{password_hash:run("echo password_hash('test-pass',PASSWORD_DEFAULT);")});
  login=await call('login',{username:'helper',password:'test-pass'});events=await call('events');assert.deepEqual(events.events.map(e=>e.code),['00000','12345'],'Valid credentials can access their separate EGM memberships');
  run(bootstrap+"egmInstanceWriteData($pdo,'00000',EGM_FACILITATORS_KEY,[]);");events=await call('events');assert.deepEqual(events.events.map(e=>e.code),['12345'],'Deleted membership is immediately removed');
  run(bootstrap+"egmInstanceWriteData($pdo,'12345',EGM_FACILITATORS_KEY,[]);");assert.equal((await call('session')).code,'unauthenticated','Removing final membership revokes login');
  login=await call('login',{username:'admin',password:'admin-pass'});assert.equal(login.status,'ok','Existing panel-user login still works');
  assert.equal((await call('logout',{csrf:login.csrf})).status,'ok');assert.equal((await call('session')).code,'unauthenticated');
  console.log('PASS: real Windows API facilitator/password login, all four live roles, cross-event denial, separate cookies, credential/deletion revocation, multiple memberships and panel-user regression');
 } finally {await client.dispose();}
})().catch(error=>{console.error(error);process.exitCode=1;}).finally(()=>{
 if(server)server.kill();
 try{run(`$pdo=new PDO('mysql:host=127.0.0.1;port=3306','root','');$pdo->exec('DROP DATABASE IF EXISTS ${db}');`);}finally{
  const file=path.join(temp,'winapp.php');if(fs.existsSync(file))fs.unlinkSync(file);fs.rmdirSync(temp);
  assert.ok(path.resolve(folderPath).startsWith(path.resolve(instanceRoot)+path.sep));fs.rmdirSync(folderPath);
 }
});




