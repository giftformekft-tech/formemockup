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
    const element = (name) => {
        if (!elements.has(name)) elements.set(name, { hidden: true, style: {}, handlers: {}, addEventListener(event, fn) { this.handlers[event] = fn; } });
        return elements.get(name);
    };
    const overlay = { querySelector: element, remove() { this.removed = true; } };
    const window = { MG_ORDER_EXPORT: { ajax_url: '/admin-ajax.php', nonce: 'nonce', order_ids: [90], i18n: { processing: 'Feldolgozás', done: 'Kész' } }, setTimeout(fn, delay) { timers.push({ fn, delay }); } };
    vm.runInNewContext(source, {
        window,
        document: { addEventListener(name, fn) { fn(); }, createElement() { return overlay; }, body: { appendChild() {} } },
        fetch(url, request) {
            requests.push(request.body);
            const response = responses.shift();
            if (!response) throw new Error('Unexpected request');
            return Promise.resolve({ json: () => Promise.resolve(response) });
        },
    });
    return { element, requests, responses, timers, overlay };
}
const flush = () => new Promise(resolve => setImmediate(resolve));
async function main() {
    const ui = setup();
    ui.responses.push(
        { success: true, data: { job_id: 'one' } },
        { success: true, data: { completed: 0, total: 2, percent: 0, waiting: true, message: 'AI nyomat készül' } },
    );
    ui.element('.mg-order-export-choice-normal').handlers.click();
    ui.element('.mg-order-export-choice-strip').handlers.click();
    await flush();
    assert.equal(ui.requests.length, 2, 'double click starts only one job');
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
    failure.responses.push({ success: true, data: { job_id: 'bad' } }, { success: false, data: { message: 'Tétel #12: HTTP 429' } });
    failure.element('.mg-order-export-choice-normal').handlers.click();
    await flush();
    assert.equal(failure.element('.mg-order-export-error').textContent, 'Tétel #12: HTTP 429');
    assert.equal(failure.element('.mg-order-export-download').hidden, true);
    assert.equal(failure.timers.length, 0, 'API error does not retry automatically');

    const closed = setup();
    closed.responses.push({ success: true, data: { job_id: 'closed' } }, { success: true, data: { completed: 0, total: 1, waiting: true } });
    closed.element('.mg-order-export-choice-normal').handlers.click();
    await flush();
    closed.element('.mg-order-export-close').handlers.click();
    closed.timers.shift().fn();
    await flush();
    assert.equal(closed.requests.length, 2, 'closing the modal stops subsequent work dispatch');
    console.log('Order export UI tests passed (HTTP and DOM mocked).');
}
main().catch(error => { console.error(error); process.exitCode = 1; });
