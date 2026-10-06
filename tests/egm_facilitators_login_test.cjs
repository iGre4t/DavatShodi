const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const net = require('node:net');
const {spawn} = require('node:child_process');
const {request} = require('playwright');
const root = path.resolve(__dirname,'..').replaceAll('\\','/');
const temp = fs.mkdtempSync(path.join(os.tmpdir(),'egm-facilitator-login-'));
const dataFile = path.join(temp,'accounts.json');
const account = {id:'f'.repeat(32),username:'ref01',fullname:'تسهیلگر آزمایشی',password:'  001234  ',auth_version:'v1'};
fs.writeFileSync(dataFile,JSON.stringify([account]));
const quote = value=>"'"+value.replaceAll('\\','/').replaceAll("'","\\'")+"'";
const fixture = `
class LoginFixturePdo extends PDO {
 public function __construct() {}
 public function exec(string $query):int|false {echo 'Warning: simulated host startup output';throw new EgmRefPushSetupException('اعلان گوشی به PHP 8.2 یا بالاتر نیاز دارد؛ تنظیمات هاست را بررسی کنید.');}
 public function prepare(string $query,array $options=[]): PDOStatement|false {return new LoginFixtureStatement($query);}
 public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs):PDOStatement|false {return new LoginFixtureStatement($query);}
}
class LoginFixtureStatement extends PDOStatement {
 private string $sql; private array $params=[];
 public function __construct(string $sql){$this->sql=$sql;}
 public function execute(?array $params=null):bool{$this->params=$params??[];return true;}
 public function fetchColumn(int $column=0):mixed {
  if(str_contains($this->sql,'data_key')&&($this->params[':data_key']??'')==='refmonitor_facilitators'){
   $rows=json_decode(file_get_contents(${quote(dataFile)}),true);
   foreach($rows as &$row){$row['password_hash']=password_hash($row['password'],PASSWORD_DEFAULT);unset($row['password']);}
   return json_encode($rows);
  }
  return false;
 }
 public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{return str_starts_with($this->sql,'SHOW COLUMNS')?[['Field'=>'permissions']]:[];}
 public function fetch(int $mode=PDO::FETCH_DEFAULT,int $orientation=PDO::FETCH_ORI_NEXT,int $offset=0):mixed {
  if(str_contains($this->sql,'FROM \`users\`')&&(($this->params[':username']??'')==='admin'||($this->params[':code']??'')==='admin1'))return ['code'=>'admin1','username'=>'admin','fullname'=>'مدیر آزمایشی','password_hash'=>password_hash('admin-pass',PASSWORD_DEFAULT),'permissions'=>['event-guest-manager']];
  return false;
 }
}
`;
let source=fs.readFileSync(path.join(root,'mini apps/Event Guest Manager/RefMonitor.php'),'utf8');
source=source.replace('declare(strict_types=1);','declare(strict_types=1);\n'+fixture)
 .replace('$projectRoot = realpath(__DIR__);','$projectRoot = '+quote(root)+';')
 .replace('$pdo = connectDatabase($config);',"$pdo = new LoginFixturePdo(); $cache = &egmInstanceEnsureCache(); $cache[spl_object_id($pdo).':12345'] = true;")
 .replace('findEgmRegistryByDirectory($pdo, $relativeDirectory)',"['code'=>'12345','name'=>'Test']");
fs.writeFileSync(path.join(temp,'RefMonitor.php'),source);
(async()=>{
 const port=await new Promise(resolve=>{const socket=net.createServer();socket.listen(0,'127.0.0.1',()=>{const p=socket.address().port;socket.close(()=>resolve(p));});});
 const server=spawn('C:/xampp/php/php.exe',['-S',`127.0.0.1:${port}`,'-t',temp],{windowsHide:true,stdio:'ignore'});
 const client=await request.newContext(); const url=`http://127.0.0.1:${port}/RefMonitor.php`;
 try {
  let response;
  for(let i=0;i<30;i++){try{response=await client.get(url);break;}catch{await new Promise(r=>setTimeout(r,100));}}
  assert.ok(response,'PHP test server did not start');
  async function csrf() {return (await (await client.get(url)).text()).match(/name="csrf" value="([a-f0-9]+)"/)[1];}
  async function login(username,password) {return client.post(url,{form:{action:'login',username,password,csrf:await csrf()}});}
  let html=await (await login('ref01','wrong')).text();assert.match(html,/نام کاربری یا رمز عبور معتبر نیست/);
  html=await (await login('ref01','  001234  ')).text();assert.match(html,/class="brand-name">تسهیلگر آزمایشی/);assert.match(html,/data-ref-view="home"/);
  fs.writeFileSync(dataFile,JSON.stringify([{...account,auth_version:'v2'}]));
  html=await (await client.get(url)).text();assert.match(html,/name="username"/);assert.ok(!html.includes('data-ref-view="home"'));
  await login('ref01','  001234  ');fs.writeFileSync(dataFile,'[]');
  const denied=await client.post(url,{form:{action:'team_detail',csrf:await csrf()}});assert.equal(denied.status(),403);
  html=await (await login('admin','admin-pass')).text();assert.match(html,/class="brand-name">مدیر آزمایشی/);
  const pushFailure=await client.post(url,{form:{action:'push_config',csrf:await csrf()}});
  assert.equal(pushFailure.status(),503);const pushError=await pushFailure.json();
  assert.equal(pushError.status,'error');assert.match(pushError.message,/PHP 8.2/);
  assert.ok(!(await pushFailure.text()).includes('Warning:'),'Startup output corrupted the JSON response');
  await client.post(url,{form:{action:'logout',csrf:await csrf()}});
  html=await (await client.get(url)).text();assert.match(html,/name="username"/);
  console.log('Real RefMonitor PHP request flow: facilitator login, wrong password, credential-change revocation, removal, administrator login and logout passed with an isolated PDO fixture.');
 } finally {await client.dispose();server.kill();}
})().catch(error=>{console.error(error);process.exitCode=1}).finally(()=>{
 for(const name of ['accounts.json','RefMonitor.php'])fs.unlinkSync(path.join(temp,name));
 fs.rmdirSync(temp);
});
