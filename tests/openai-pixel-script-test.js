const assert = require('assert');
const fs = require('fs');
const vm = require('vm');
const source = fs.readFileSync(require('path').join(__dirname, '../assets/js/openai-pixel.js'), 'utf8');
const flush = () => new Promise(resolve => setImmediate(resolve));

function fixture(cookie = '', storage = new Map()) {
    const listeners = {}, scripts = [], calls = [], requests = [], timers = new Map();
    let result = {success: true, data: {events: [], pending: false}};
    const document = {cookie, readyState: 'loading', body: {},
        querySelector: () => ({value: 'shirt'}),
        addEventListener: (name, callback) => { listeners[name] = callback; },
        createElement: () => ({}), head: {appendChild: script => scripts.push(script)}};
    const window = {mgOpenAIConfig: {pixelId: 'test', endpoint: '/events', checkout: true, orderId: 9,
        product: {id: '7'}, productIds: {shirt: 'SKU7_shirt'}, debug: false}, location: {search: '?key=secret'},
        localStorage: {getItem: key => storage.get(key), setItem: (key, value) => storage.set(key, value)},
        setTimeout: callback => { timers.set(timers.size + 1, callback); return timers.size; },
        clearTimeout: id => timers.delete(id),
        fetch: async (url, options) => { requests.push(options); return {ok: true, json: async () => result}; }};
    vm.runInNewContext(source, {window, document, URLSearchParams});
    return {window, scripts, calls, requests, timers, storage,
        result: value => { result = value; },
        load: () => listeners.DOMContentLoaded(),
        consent: granted => listeners.mg_gads_consent({detail: {granted}}),
        added: () => listeners['wc-blocks_added_to_cart'](),
        sdk: () => { calls.push(...window.oaiq.q.map(args => Array.from(args))); window.oaiq = (...args) => calls.push(args); scripts[0].onload(); }};
}
const commerce = {success: true, data: {events: [
    {name: 'checkout_started', id: '', data: {type: 'contents'}},
    {name: 'order_created', id: 'order-9', data: {type: 'contents', amount: 399000, currency: 'HUF'}},
    {name: 'items_added', id: 'cart-1', data: {type: 'contents'}}
], pending: false}};
(async () => {
    const f = fixture(); f.load();
    assert.equal(f.scripts.length, 0, 'Unknown consent must not load SDK');
    assert.equal(f.requests.length, 0, 'Unknown consent must not fetch commerce data');
    f.consent(false); assert.equal(f.scripts.length, 0);
    f.result(commerce); f.consent(true);
    assert.equal(f.scripts.length, 1);
    assert.equal(f.storage.size, 0, 'Blocked SDK must not mark purchases as sent');
    f.sdk(); await flush();
    assert.deepEqual(f.calls[0], ['consent', false]);
    assert.equal(f.calls[1][0], 'init');
    assert.deepEqual(f.calls[2], ['consent', true]);
    assert.equal(f.calls.filter(c => c[0] === 'measure').length, 5);
    assert.equal(f.calls.find(c => c[1] === 'contents_viewed')[2].contents[0].id, 'SKU7_shirt', 'Product view ID matches the selected feed offer');
    assert(f.requests[0].body.includes('order_key=secret'));
    f.consent(true); f.added(); await flush();
    assert.equal(f.calls.filter(c => c[0] === 'measure').length, 5, 'Repeated AJAX/consent must not duplicate events');
    assert(!f.requests[1].body.includes('checkout='));
    f.consent(false); const requestCount = f.requests.length; f.added();
    assert.equal(f.requests.length, requestCount, 'Revocation stops lookups');
    assert.deepEqual(f.calls.at(-1), ['consent', false]);
    const reload = fixture('mg_gads_consent=granted', f.storage);
    reload.result(commerce); reload.load(); reload.sdk(); await flush();
    assert.equal(reload.calls.filter(c => c[1] === 'order_created').length, 0, 'Refresh must not duplicate purchase');
    const pending = fixture('mg_gads_consent=granted');
    pending.result({success: true, data: {events: [], pending: true}});
    pending.load(); pending.sdk(); await flush();
    assert.equal(pending.timers.size, 1); pending.consent(false);
    assert.equal(pending.timers.size, 0, 'Revocation cancels pending purchase polling');
    console.log('OpenAI Pixel: consent, SDK blocking, funnel events, deduplication, order key and polling passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
