// node tests/ai-duplicate-ui-test.js
// Requires Playwright + Chromium, PHP and jQuery (Debian's libjs-jquery or the
// jquery npm package). Drives the "AI duplikátum" modal (assets/js/ai-duplicate.js,
// rendered by MG_Dashboard_Page) against a fake admin-ajax: planning, at most two
// drawings at once, a redraw with an edited prompt, kept names while cards
// refresh, creating the kept ones with the server's upscale model and palette,
// and dropping a variant.
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const http = require('node:http'); const fs = require('node:fs'); const path = require('node:path');
const { execFileSync } = require('node:child_process');
const os = require('node:os');
const ROOT = path.join(__dirname, '..');
const DIR = fs.mkdtempSync(path.join(os.tmpdir(), 'mg-dup-ui-'));
function jqueryPath() {
    if (fs.existsSync('/usr/share/javascript/jquery/jquery.min.js')) return '/usr/share/javascript/jquery/jquery.min.js';
    try { return require.resolve('jquery/dist/jquery.min.js'); } catch (e) { return ''; }
}
const JQUERY = jqueryPath();
if (!JQUERY) {
    console.log('This test requires jQuery (libjs-jquery or the jquery npm package).');
    process.exit(0);
}
fs.writeFileSync(path.join(DIR, 'modal.html'), execFileSync('php', ['-r', `
define('ABSPATH', '/');
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
class MG_AI_Redesign { public static function get_intensities() { return array('strong' => 'Új stílus', 'medium' => 'Finom', 'subtle' => 'Extra finom', 'full' => 'Teljesen új'); } }
class MG_AI_Duplicate {}
require '${ROOT}/admin/class-dashboard-page.php';
$m = new ReflectionMethod('MG_Dashboard_Page', 'render_ai_duplicate_modal');
$m->setAccessible(true);
$m->invoke(null);
`]));
const log = { calls: [], maxParallel: 0, created: [], uploads: 0 };
let active = 0, slots = {}, order = [], seq = 0;
const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');
function slot(id, extra) { return Object.assign({ id, status: 'planned', name: 'Név ' + id, new_style: 'Más ' + id, intensity: 'strong', prompt: 'PROMPT ' + id, attachment_id: 0, upscale: 'none' }, slots[id] || {}, extra || {}); }
function list() { return order.map(id => slots[id]); }
function parse(req) { return new Promise(r => { const c = []; req.on('data', d => c.push(d)); req.on('end', () => r(Buffer.concat(c))); }); }
const server = http.createServer(async (req, res) => {
  const url = req.url.split('?')[0];
  const send = (code, obj) => { res.writeHead(code, { 'content-type': 'application/json' }); res.end(JSON.stringify(obj)); };
  if (url === '/') { res.writeHead(200, { 'content-type': 'text/html' }); return res.end(fs.readFileSync(path.join(DIR, 'page.html'))); }
  if (url === '/img.png') { res.writeHead(200, { 'content-type': 'image/png' }); return res.end(png); }
  if (url === '/jquery.js') { res.writeHead(200, { 'content-type': 'text/javascript' }); return res.end(fs.readFileSync(JQUERY)); }
  if (url === '/ai-duplicate.js') { res.writeHead(200, { 'content-type': 'text/javascript' }); return res.end(fs.readFileSync(path.join(ROOT, 'assets/js/ai-duplicate.js'))); }
  if (url !== '/ajax') { res.writeHead(404); return res.end(); }
  const body = await parse(req);
  let p;
  if ((req.headers['content-type'] || '').startsWith('multipart')) { p = { action: /name="action"\r\n\r\n([^\r]+)/.exec(body.toString('latin1'))[1], slot: /name="slot"\r\n\r\n([^\r]+)/.exec(body.toString('latin1'))[1] }; }
  else { p = Object.fromEntries(new URLSearchParams(body.toString())); }
  log.calls.push(p.action + (p.slot ? ':' + p.slot : ''));
  const a = p.action.replace('mg_ai_duplicate_', '');
  if (a === 'info') return send(200, { success: true, data: { design_url: '/img.png', design_error: '', analyzers: { openai: 'OpenAI' }, analyzer: 'openai', quality: 'low', resolution: 'economy', sizes: [{ width: 816, height: 816, edge: 816 }], slots: list(), plan: { status: 'none' } } });
  if (a === 'plan') {
    for (let i = 0; i < Number(p.count); i++) { const id = (++seq).toString(16).padStart(12, '0'); slots[id] = slot(id); order.push(id); }
    log.plan = p;
    return send(200, { success: true, data: { plan: { status: 'done' }, slots: list() } });
  }
  if (a === 'generate') {
    active++; log.maxParallel = Math.max(log.maxParallel, active);
    await new Promise(r => setTimeout(r, 400));
    active--;
    slots[p.slot] = slot(p.slot, { status: 'done', attachment_id: ++seq, full: '/img.png?v=' + seq, preview: '/img.png?v=' + seq, upscale: 'none', width: 816, height: 816, generated_size: '816×816', prompt: p.custom_prompt || slots[p.slot].prompt });
    if (p.custom_prompt) log.customPrompt = p.custom_prompt;
    return send(200, { success: true, data: { slot: slots[p.slot] } });
  }
  if (a === 'status') return send(200, { success: true, data: { plan: { status: 'done' }, slots: list() } });
  if (a === 'prepare') return send(200, { success: true, data: Object.assign({}, slots[p.slot], { prepared: true, upscale_factor: 3, upscale_engine: 'waifu2x', palette_finish: true }) });
  if (a === 'upscaled') { log.uploads++; slots[p.slot].upscale = 'ai3x'; return send(200, { success: true, data: slots[p.slot] }); }
  if (a === 'create') { log.created.push({ slot: p.slot, name: p.name }); order = order.filter(x => x !== p.slot); delete slots[p.slot]; return send(200, { success: true, data: { job_id: 'j', name: p.name, message: '„' + p.name + '” sorba állítva.' } }); }
  if (a === 'discard') { order = order.filter(x => x !== p.slot); delete slots[p.slot]; return send(200, { success: true, data: { deleted: 1, slots: list() } }); }
  send(400, { success: false });
});
(async () => {
  await new Promise(r => server.listen(0, r));
  const html = '<!doctype html><meta charset="utf-8"><body><button class="mg-ai-duplicate-trigger" data-product-id="5" data-product-title="Apa cirkusz">open</button>'
    + fs.readFileSync(path.join(DIR, 'modal.html'), 'utf8')
    + '<script src="/jquery.js"></script><script>window.MG_AJAX={ajax_url:"/ajax",nonce:"n"};window.MG_AI_UPSCALE={palette:true};'
    + 'window.MGAIUpscale={upscale:async function(u,p,f,e,pal){window.upscaleArgs=[f,e,pal];p&&p(0.5);p&&p(1);return new Blob([new Uint8Array([1,2,3])],{type:"image/png"});}};</script>'
    + '<script src="/ai-duplicate.js"></script></body>';
  fs.writeFileSync(path.join(DIR, 'page.html'), html);
  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage();
  const errors = []; page.on('pageerror', e => errors.push(e.message));
  page.on('dialog', d => d.accept());
  await page.goto(`http://localhost:${server.address().port}/`);
  await page.click('.mg-ai-duplicate-trigger');
  await page.waitForFunction(() => document.querySelector('#mg-ai-duplicate-analyzer').options.length === 1);
  await page.selectOption('#mg-ai-duplicate-count', '3');
  await page.fill('#mg-ai-duplicate-hint', 'vidám');
  await page.click('#mg-ai-duplicate-submit');
  await page.waitForFunction(() => document.querySelectorAll('.mg-ai-duplicate__slot img').length === 3, null, { timeout: 15000 });
  const cards = await page.$$eval('.mg-ai-duplicate__slot', els => els.map(e => [e.dataset.slot, e.querySelector('.mg-ai-duplicate-name').value]));
  // Redraw the third with an edited prompt; keep the first two, rename the second.
  await page.click(`[data-slot="${cards[2][0]}"] .mg-ai-duplicate-edit`);
  await page.fill(`[data-slot="${cards[2][0]}"] .mg-ai-duplicate__prompt textarea`, 'EDITED PROMPT');
  await page.click(`[data-slot="${cards[2][0]}"] .mg-ai-duplicate-redraw-prompt`);
  await page.click(`[data-slot="${cards[0][0]}"] .mg-ai-duplicate-approve`);
  await page.fill(`[data-slot="${cards[1][0]}"] .mg-ai-duplicate-name`, 'Saját név');
  await page.click(`[data-slot="${cards[1][0]}"] .mg-ai-duplicate-approve`);
  await page.waitForFunction(() => !document.querySelector('#mg-ai-duplicate-create').disabled);
  const createLabel = await page.textContent('#mg-ai-duplicate-create');
  await page.waitForFunction(() => document.querySelectorAll('.mg-ai-duplicate__slot img').length === 3 && !/rajzolja/.test(document.body.textContent), null, { timeout: 15000 });
  const nameKept = await page.inputValue(`[data-slot="${cards[1][0]}"] .mg-ai-duplicate-name`);
  await page.click('#mg-ai-duplicate-create');
  await page.waitForFunction(() => document.querySelectorAll('.mg-ai-duplicate__made').length === 2, null, { timeout: 15000 });
  const status = await page.textContent('#mg-ai-duplicate-status');
  const upscaleArgs = await page.evaluate(() => window.upscaleArgs);
  const left = await page.$$eval('.mg-ai-duplicate__slot', els => els.length);
  // Drop the last one.
  await page.click(`[data-slot="${cards[2][0]}"] .mg-ai-duplicate-discard`);
  await page.waitForFunction(() => document.querySelectorAll('.mg-ai-duplicate__slot').length === 0);
  assert.deepEqual(errors, []);
  assert.deepEqual(log.plan && [log.plan.count, log.plan.style_hint], ['3', 'vidám'], 'the count and the idea go to the plan');
  assert.equal(log.maxParallel, 2, 'at most two drawings at once');
  assert.equal(cards.length, 3);
  assert.equal(cards[0][1], 'Név 000000000001', 'the suggested name on the card');
  assert.equal(createLabel, 'Jóváhagyottak létrehozása új termékként (2)');
  assert.equal(nameKept, 'Saját név', 'a typed name survives the cards refreshing');
  assert.equal(log.customPrompt, 'EDITED PROMPT', 'a redraw with the edited prompt');
  assert.deepEqual(log.created, [{ slot: cards[0][0], name: 'Név 000000000001' }, { slot: cards[1][0], name: 'Saját név' }], 'the kept ones, in order, with their names');
  assert.equal(log.uploads, 2);
  assert.deepEqual(upscaleArgs, [3, 'waifu2x', true], "the server's factor, model and palette");
  assert.equal(left, 1, 'the redrawn one stays');
  assert.match(status, /2 új termék sorba állítva/);
  assert.ok(log.calls.indexOf('mg_ai_duplicate_discard:' + cards[2][0]) !== -1, 'dropped');
  console.log('AI duplicate UI test passed (plan, two at a time, edited prompt, kept names, create with upscale, discard).');
  await browser.close(); server.close();
})().catch(e => { console.error(e); process.exit(1); });
