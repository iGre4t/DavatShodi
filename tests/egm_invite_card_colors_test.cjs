const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const {chromium} = require('playwright');
const root = path.resolve(__dirname, '..');
const source = fs.readFileSync(path.join(root, 'assets/egm-invite-card.js'), 'utf8');
const php = process.env.PHP_BINARY || 'C:/xampp/php/php.exe';

function save(payload) {
  const script = 'require ' + JSON.stringify(path.join(root, 'api/lib/egm-invite-card-store.php').replaceAll('\\', '/')) +
    '; $p=json_decode(stream_get_contents(STDIN),true);' +
    ' $draft=egmInviteCardMergeDraft(null,$p+["sections"=>["content"]]);' +
    ' $saved=egmInviteCardNormalizeConfig($p);' +
    ' echo json_encode(["draft"=>$draft,"saved"=>$saved]);';
  return JSON.parse(execFileSync(php, ['-r', script], {input:JSON.stringify(payload), encoding:'utf8'}));
}

(async () => {
  const browser = await chromium.launch({headless:true, channel:'msedge'});
  try {
    const page = await browser.newPage();
    const editorFunctions = source.slice(source.indexOf('  function normalizedEditorColor('), source.indexOf('  function plainTextToEditorHtml('));
    const renderingFunctions = source.slice(source.indexOf('  function richTextBlocks('), source.indexOf('  function setCanvasRunFont('));
    await page.addScriptTag({content:editorFunctions + renderingFunctions + '\nfunction replaceInviteeMergeTags(text) { return text; }'});
    const html = await page.evaluate(() => sanitizeEditorHtml('<p>معمولی <span style="color:#0066cc">آبی</span> <strong><span style="color:#fff">سفید</span></strong></p>'));
    assert(html.includes('rgb(0, 102, 204)'), 'Browser must serialize actual CSSOM colors');
    const rect = {x:10,y:10,width:50,height:20};
    const result = save({textHtml:html,
      imageData:'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
      qrRect:rect, textRect:rect, textAreas:[{id:'extra', textHtml:html, rect, fontFamily:'Tahoma', color:'#ffffff', fontSize:14, bold:true, align:'right'}]});
    for (const config of [result.draft, result.saved]) {
      assert.equal(config.textAreas[0].fontFamily, 'Tahoma');
      assert.equal(config.textAreas[0].fontSize, 14);
      assert.equal(config.textAreas[0].color, '#ffffff');
      assert.equal(config.textAreas[0].bold, true);
      assert.equal(config.textAreas[0].align, 'right');
      for (const savedHtml of [config.textHtml, config.textAreas[0].textHtml]) {
        assert(savedHtml.includes('color:#0066cc'));
        assert(savedHtml.includes('color:#ffffff'));
        const runs = await page.evaluate(html => richTextBlocks(html, {}).flatMap(block => block.runs), savedHtml);
        assert.equal(runs.find(run => run.text === 'آبی').color, '#0066cc');
        assert.equal(runs.find(run => run.text === 'سفید').color, '#ffffff');
        assert.equal(runs.find(run => run.text === 'سفید').bold, true);
      }
    }
    await page.addScriptTag({content:"const DEFAULT_INVITE_FONT_FAMILY = 'Arial';\n" + source.slice(source.indexOf('  function setCanvasRunFont('), source.indexOf('  async function renderInviteCardImage('))});
    const draws = await page.evaluate(settings => {
      const draws = [];
      const context = {save(){}, restore(){}, beginPath(){},rect(){},clip(){},measureText(text){return {width:text.length*6};},fillText(text,x,y){draws.push({text,x,y,font:this.font,color:this.fillStyle});}};
      drawInviteText(context, '<p>متن مستقل</p>', {}, {x:0,y:0,width:300,height:100}, [], 'Arial', settings);
      return draws;
    }, result.saved.textAreas[0]);
    assert(draws.length > 0);
    assert(draws.every(draw => draw.font === '700 14px Tahoma' && draw.color === '#ffffff'));
    const unsafe = save({...result.saved, textHtml:'<p><span style="color:rgb(999,0,0);background:url(javascript:bad)" onclick="bad()">متن</span></p>'});
    assert.equal(unsafe.saved.textHtml, '<p><span>متن</span></p>');
    console.log('PASS: browser RGB colors survive autosave, full save, reload and rendering, including extra text areas; unsafe styles removed.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
