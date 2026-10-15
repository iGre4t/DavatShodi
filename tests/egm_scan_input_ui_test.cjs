const fs=require('node:fs'),assert=require('node:assert/strict');const {chromium}=require('playwright');
(async()=>{const browser=await chromium.launch({headless:true,channel:'msedge'});try{
 const page=await browser.newPage();const errors=[],scans=[];page.on('pageerror',e=>errors.push(e.message));
 const source=fs.readFileSync('api/lib/egm-check-in.php','utf8');
 const html=source.slice(source.indexOf('<main class="guest-shell"'),source.indexOf('<script nonce="<?= $nonce ?>" src="<?= $printCardRendererUrl ?>">')).replace(/<\?[\s\S]*?\?>/g,'');
 const marker='<script nonce="<?= $nonce ?>">',start=source.indexOf(marker)+marker.length;const script=source.slice(start,source.indexOf('</script>',start));
 await page.route('http://egm.test/**',async route=>{
  if(route.request().method()==='POST'){
   const body=route.request().postDataJSON();scans.push(body);
   const data={status:'ok',result:body.scan_input?.method==='typed'?'not_found':'success',message:'نتیجه',logs:body.scan_input?.method==='typed'?[]:[{status:'success',national_id:body.guest_code}],stats:{},suggested_guests:body.scan_input?.method==='typed'?[{national_id:'0012345678',name:'مهمان دعوت‌شده',already_entered:false}]:[]};
   return route.fulfill({contentType:'application/json',body:JSON.stringify(data)});
  }
  if(new URL(route.request().url()).searchParams.has('action'))return route.fulfill({contentType:'application/json',body:JSON.stringify({status:'ok',required:false,logs:[],stats:{},print_profile:{auto_print:false}})});
  return route.fulfill({contentType:'text/html',body:'<html lang="fa" dir="rtl">'+html+'</html>'});
 });
 await page.goto('http://egm.test/scan');await page.addScriptTag({content:script});
 const input=page.locator('[data-national-id]');await input.click();await page.keyboard.type('0012345679',{delay:110});assert.equal(scans.length,0,'Typed input submitted before Enter');await page.keyboard.press('Enter');
 await page.locator('dialog[open]').waitFor();assert.equal(scans[0].scan_input.method,'typed');assert.equal(scans[0].scan_input.key_count,10);
 await page.getByRole('button',{name:/مهمان دعوت‌شده/}).click();await page.waitForFunction(()=>document.querySelector('[data-result]').classList.contains('success'));
 assert.equal(scans[1].scan_input.method,'selection');assert.equal(scans[1].scan_input.corrected_from,'0012345679');assert.equal(scans[1].guest_code,'0012345678');
 await input.click();await page.keyboard.type('0012345678',{delay:5});await page.waitForTimeout(250);assert.equal(scans[2].scan_input.method,'scanner');assert.equal(scans[2].scan_input.key_count,10);
 await input.evaluate(node=>{node.dispatchEvent(new Event('paste'));node.value='0012345678';node.dispatchEvent(new InputEvent('input',{bubbles:true,inputType:'insertFromPaste'}));});await page.keyboard.press('Enter');await page.waitForTimeout(250);assert.equal(scans[3].scan_input.method,'paste');
 assert.deepEqual(errors,[]);console.log('PASS: real web typing/scanner/paste classification, queue metadata and explicit suggested-guest correction');
 }finally{await browser.close();}})().catch(error=>{console.error(error);process.exitCode=1;});
