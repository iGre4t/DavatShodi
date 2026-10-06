const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync(require('node:path').join(__dirname,'../mini apps/Event Guest Manager/RefMonitor-sw.js'),'utf8');
const handlers={},shown=[],messages=[],opened=[];let focused=false;
const client={url:'https://example.test/event/RefMonitor.php',get focused(){return focused},postMessage(message){messages.push(message)},async focus(){focused=true}};
const context={URL,self:{location:{origin:'https://example.test'},registration:{scope:'https://example.test/event/',async showNotification(title,options){shown.push({title,options})}},clients:{async matchAll(){return [client]},async openWindow(url){opened.push(url)}},addEventListener(type,callback){handlers[type]=callback}}};
vm.runInNewContext(source,context);
async function dispatch(type,event){let work;handlers[type]({...event,waitUntil(promise){work=promise}});await work;}
(async()=>{
 const payload={title:'اتاق آماده شد',body:'تیم امید · آفتاب',url:'https://example.test/event/RefMonitor.php?room_team=1',tag:'room-1',game_id:'123',period_code:'001',team_id:1};
 await dispatch('push',{data:{json:()=>payload}});
 assert.equal(shown.length,1);assert.equal(shown[0].options.silent,undefined);assert.equal(shown[0].options.data.teamId,1);
 focused=true;await dispatch('push',{data:{json:()=>payload}});
 assert.equal(messages[0].type,'ref-room-ready');assert.equal(shown[1].options.silent,true);
 await dispatch('push',{data:{json:()=>({...payload,url:'https://evil.test/'})}});assert.equal(shown.length,2);
 let closed=false;await dispatch('notificationclick',{notification:{data:shown[0].options.data,close(){closed=true}}});
 assert.equal(closed,true);assert.equal(messages.at(-1).type,'ref-room-open');assert.equal(messages.at(-1).payload.teamId,1);
 console.log('Background visible notification, foreground silent notification/update, safe URLs and notification-click team routing passed.');
})().catch(error=>{console.error(error);process.exitCode=1});
