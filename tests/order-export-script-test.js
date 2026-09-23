// node tests/order-export-script-test.js — exercise export UI with fake HTTP/timers.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../assets/js/order-export.js'), 'utf8');

function setup() {
    const elements = new Map();
    const requests = [];
    const timers = [];
    const responses = [];
    const makeElement = () => ({
        hidden: true, style: {}, handlers: {}, children: [], attributes: {},
        classList: { add() {}, toggle() {} },
        addEventListener(event, fn) { this.handlers[event] = fn; },
        appendChild(child) { this.children.push(child); },
        setAttribute(key, value) { this.attributes[key] = value; },
        set textContent(value) { this.text = value; this.children = []; },
        get textContent() { return this.text; },
        focus() {},
    });
    const element = (name) => {
        if (!elements.has(name)) elements.set(name, makeElement());
        return elements.get(name);
    };
    const overlay = { ...makeElement(), querySelector: element, remove() { this.removed = true; } };
    const window = {
        MG_ORDER_EXPORT: { ajax_url: '/admin-ajax.php', nonce: 'nonce', order_ids: [90], i18n: { processing: 'Feldolgozás', done: 'Kész' } },
        setTimeout(fn, delay) { const timer = { fn, delay }; timers.push(timer); return timer; },
        clearTimeout(timer) { const index = timers.indexOf(timer); if (index !== -1) timers.splice(index, 1); },
    };
    vm.runInNewContext(source, {
        window,
        AbortController,
        document: { addEventListener(name, fn) { fn(); }, createElement(tag) { return tag === 'div' ? overlay : makeElement(); }, body: { appendChild() {} } },
        fetch(url, request) {
            requests.push(request.body);
            const response = responses.shift();
            if (!response) throw new Error('Unexpected request');
            if (response === 'timeout') return new Promise((resolve, reject) => request.signal.addEventListener('abort', () => reject(new Error('Timed out'))));
            if (response instanceof Error) return Promise.reject(response);
            return Promise.resolve({ json: () => Promise.resolve(response) });
        },
    });
    return { element, requests, responses, timers, overlay };
}
const flush = () => new Promise(resolve => setImmediate(resolve));
async function main() {
    const reviewPayload = (items = []) => ({ success: true, data: { review_id: 'mgr_review', items, total: 4 } });
    const items = [
        { key: '90_11', order_id: 90, item_id: 11, quantity: 2, product_name: '<script>name</script>', fields: [{ label: 'Név', value: '<Anna>' }, { label: 'Év', value: '1995' }] },
        { key: '90_12', order_id: 90, item_id: 12, quantity: 1, product_name: 'Második', fields: [{ label: 'Hónap', value: 'május' }] },
    ];
    const wizard = setup();
    const click = (selector) => wizard.element(selector).handlers.click();
    const loaded = () => wizard.element('.mg-order-export-image').handlers.load();
    assert.equal(wizard.requests.length, 0, 'opening the modal does not start any work');
    wizard.responses.push(reviewPayload(items));
    click('.mg-order-export-choice-normal');
    click('.mg-order-export-choice-strip');
    await flush();
    assert.equal(wizard.requests.length, 1, 'double click requests only one review');
    assert.match(wizard.requests[0], /action=mg_design_export_review/);
    assert.equal(wizard.element('.mg-order-export-next').disabled, true, 'no default decision');
    assert.equal(wizard.element('.mg-order-export-use-original').disabled, true, 'must wait for the image');
    assert.equal(wizard.element('.mg-order-export-fields').children.length, 4, 'both values appear together');
    assert.equal(wizard.element('.mg-order-export-fields').children[1].textContent, '<Anna>', 'customer text is assigned as text');
    assert.match(wizard.element('.mg-order-export-image').src, /item_key=90_11/);
    loaded();
    click('.mg-order-export-use-original');
    click('.mg-order-export-next');
    assert.match(wizard.element('.mg-order-export-image').src, /item_key=90_12/);
    wizard.element('.mg-order-export-image').handlers.error();
    assert.equal(wizard.element('.mg-order-export-next').disabled, true, 'broken images block review completion');
    loaded();
    click('.mg-order-export-use-ai');
    click('.mg-order-export-prev');
    loaded();
    assert.equal(wizard.element('.mg-order-export-use-original').attributes['aria-pressed'], 'true', 'back navigation retains the decision');
    click('.mg-order-export-next');
    loaded();
    click('.mg-order-export-next');
    assert.equal(wizard.requests.length, 1, 'finishing all reviews still waits for final confirmation');
    assert.equal(wizard.element('.mg-order-export-summary').hidden, false);
    assert.match(wizard.element('.mg-order-export-summary-counts').textContent, /1 tétel AI/);
    wizard.element('.mg-order-export-summary-list').children[0].children[0].handlers.click();
    loaded();
    click('.mg-order-export-next');
    loaded();
    click('.mg-order-export-next');
    wizard.responses.push({ success: false, data: { message: 'Átmeneti indítási hiba' } });
    click('.mg-order-export-confirm');
    await flush();
    assert.equal(wizard.element('.mg-order-export-confirm').disabled, false, 'start failure permits explicit retry');
    wizard.responses.push({ success: true, data: { job_id: 'reviewed' } }, { success: true, data: { completed: 4, total: 4, done: true } });
    click('.mg-order-export-confirm');
    click('.mg-order-export-confirm');
    await flush();
    const submitted = new URLSearchParams(wizard.requests[2]);
    assert.equal(submitted.get('review_id'), 'mgr_review');
    assert.deepEqual(JSON.parse(submitted.get('decisions')), { '90_11': 'original', '90_12': 'generate' });
    assert.equal(wizard.requests.length, 4, 'duplicate confirmation cannot create another start request');
    assert.equal(wizard.element('.mg-order-export-download').hidden, false);

    const ui = setup();
    ui.responses.push(
        reviewPayload(),
        { success: true, data: { job_id: 'one' } },
        { success: true, data: { completed: 0, total: 2, percent: 0, waiting: true, message: 'AI nyomat készül' } },
    );
    ui.element('.mg-order-export-choice-normal').handlers.click();
    ui.element('.mg-order-export-choice-strip').handlers.click();
    await flush();
    assert.equal(ui.requests.length, 3, 'ordinary export proceeds after empty review and starts only one job');
    assert.ok(ui.requests[0].includes('strip_black=0'));
    assert.equal(ui.timers[0].delay, 2000, 'AI polling is throttled');
    assert.ok(ui.element('.mg-order-export-status').textContent.includes('AI nyomat készül'));
    assert.equal(ui.element('.mg-order-export-download').hidden, true, 'no download while generation is pending');
    ui.responses.push({ success: true, data: { completed: 2, total: 2, percent: 100, done: true } });
    ui.timers.shift().fn();
    await flush();
    assert.equal(ui.element('.mg-order-export-download').hidden, false);
    assert.ok(ui.element('.mg-order-export-download').href.includes('job_id=one'));
    assert.equal(ui.timers.length, 0, 'completion stops polling');

    const failure = setup();
    failure.responses.push(reviewPayload(), { success: true, data: { job_id: 'bad' } }, { success: false, data: { message: 'Tétel #12: HTTP 429' } });
    failure.element('.mg-order-export-choice-normal').handlers.click();
    await flush();
    assert.equal(failure.element('.mg-order-export-error').textContent, 'Tétel #12: HTTP 429');
    assert.equal(failure.element('.mg-order-export-download').hidden, true);
    assert.equal(failure.timers.length, 0, 'API error does not retry automatically');
    assert.equal(failure.element('.mg-order-export-retry').hidden, false, 'failed export offers an explicit retry');
    assert.match(failure.element('.mg-order-export-status').textContent, /export megállt/);
    failure.responses.push({ success: true, data: { job_id: 'bad' } }, { success: true, data: { completed: 2, total: 2, done: true } });
    failure.element('.mg-order-export-retry').handlers.click();
    failure.element('.mg-order-export-retry').handlers.click();
    await flush();
    assert.equal(failure.requests.filter(body => body.includes('action=mg_design_export_retry')).length, 1, 'double retry click is deduplicated');
    assert.match(failure.requests[3], /job_id=bad/, 'retry resumes the existing job');
    assert.equal(failure.element('.mg-order-export-retry').hidden, true);
    assert.equal(failure.element('.mg-order-export-download').hidden, false);

    const stalled = setup();
    const queued = { success: true, data: { completed: 0, total: 1, waiting: true, ai_worker_key: 'worker1' } };
    stalled.responses.push(reviewPayload(), { success: true, data: { job_id: 'stalled' } }, queued, { success: true });
    stalled.element('.mg-order-export-choice-normal').handlers.click();
    await flush();
    assert.match(stalled.requests[3], /action=mg_design_export_run_ai/, 'waiting queue triggers a separate worker request');
    stalled.responses.push(queued);
    stalled.timers.shift().fn();
    await flush();
    assert.equal(stalled.requests.filter(body => body.includes('action=mg_design_export_run_ai')).length, 1, 'polling does not repeatedly dispatch the same worker');
    stalled.responses.push({ success: true, data: { completed: 1, total: 1, done: true } });
    stalled.timers.shift().fn();
    await flush();
    assert.equal(stalled.element('.mg-order-export-download').hidden, false);

    const timedOut = setup();
    timedOut.responses.push(reviewPayload(), { success: true, data: { job_id: 'timeout' } }, 'timeout');
    timedOut.element('.mg-order-export-choice-normal').handlers.click();
    await flush();
    assert.equal(timedOut.timers[0].delay, 30000, 'short requests have a bounded timeout');
    timedOut.timers.shift().fn();
    await flush();
    assert.equal(timedOut.element('.mg-order-export-retry').hidden, false, 'network timeout leaves a usable recovery button');
    timedOut.responses.push({ success: false, data: { message: 'A feladat lejárt. Indíts új exportot.' } });
    timedOut.element('.mg-order-export-retry').handlers.click();
    await flush();
    assert.equal(timedOut.element('.mg-order-export-retry').disabled, false, 'failed recovery remains interactive');
    assert.match(timedOut.element('.mg-order-export-error').textContent, /lejárt/);

    const closed = setup();
    closed.responses.push(reviewPayload(), { success: true, data: { job_id: 'closed' } }, { success: true, data: { completed: 0, total: 1, waiting: true } });
    closed.element('.mg-order-export-choice-normal').handlers.click();
    await flush();
    closed.element('.mg-order-export-close').handlers.click();
    closed.timers.shift().fn();
    await flush();
    assert.equal(closed.requests.length, 3, 'closing the modal stops subsequent work dispatch');
    const closedDuringReview = setup();
    closedDuringReview.responses.push(reviewPayload(items));
    closedDuringReview.element('.mg-order-export-choice-normal').handlers.click();
    closedDuringReview.element('.mg-order-export-close').handlers.click();
    await flush();
    assert.equal(closedDuringReview.requests.length, 1, 'closing during review loading never starts export');
    console.log('Order export UI tests passed (HTTP and DOM mocked).');
}
main().catch(error => { console.error(error); process.exitCode = 1; });
