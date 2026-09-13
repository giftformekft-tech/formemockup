const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const source = fs.readFileSync(path.join(__dirname, '..', 'includes', 'class-purchase-recovery.php'), 'utf8');
const recovery = source.split('public static function output_recovery_script() {')[1].split('/* ---------------------------------------------------------------------')[0];
const prelude = recovery.split('?>')[0];
assert(!/read_entries|\$_COOKIE/.test(prelude), 'Cached recovery HTML must not depend on the visitor cookie.');
assert(prelude.includes('is_order_received_page()'), 'The thank-you page must keep its own purchase sender.');
assert(/function ajax_pending_conversions\(\)\s*\{\s*nocache_headers\(\);/.test(source), 'Even empty responses must disable caching.');
const script = recovery.match(/<script>([\s\S]*?)<\/script>/)[1];

async function run(pending, consent = true) {
    const requests = [];
    const google = [];
    const meta = [];
    const confirmations = [];
    const timers = new Map();
    let nextTimer = 0;
    const fetch = async (url, options) => {
        requests.push({url, options});
        return {json: async () => ({success: true, data: {pending}})};
    };
    vm.runInNewContext(script, {
        window: {
            fetch,
            mgConvAjaxUrl: 'https://shop.test/wp-admin/admin-ajax.php',
            mgGadsConsentDecided: true,
            mgGadsConsentGranted: consent,
            mgFbConsentGranted: consent,
            gtag: (...args) => google.push(args),
            fbq: (...args) => meta.push(args),
            mgConvFired: (...args) => confirmations.push(args),
        },
        fetch,
        setInterval(fn) { timers.set(++nextTimer, fn); return nextTimer; },
        clearInterval(id) { timers.delete(id); },
    });
    await new Promise(resolve => setImmediate(resolve));
    for (let tick = 0; tick < 20; tick++) {
        for (const fn of [...timers.values()]) fn();
    }
    assert.strictEqual(requests.length, 1);
    assert.strictEqual(requests[0].url, 'https://shop.test/wp-admin/admin-ajax.php');
    const options = requests[0].options;
    assert.strictEqual(options.method, 'POST');
    assert.strictEqual(options.credentials, 'same-origin', 'The browser must send its current HttpOnly cookie.');
    assert.strictEqual(options.cache, 'no-store');
    assert.strictEqual(options.headers['Content-Type'], 'application/x-www-form-urlencoded');
    assert.strictEqual(options.body, 'action=mg_pending_conversions');
    assert.strictEqual(timers.size, 0);
    return {google, meta, confirmations};
}

(async () => {
    // The exact same cached script is used for a visitor without an order,
    // then for a purchaser whose order is returned by the uncached endpoint.
    const empty = await run([]);
    assert.strictEqual(empty.google.length + empty.meta.length + empty.confirmations.length, 0);

    const entry = {
        order_id: 123,
        order_key: 'wc_order_test',
        google: {event: {transaction_id: '123', value: 5000, currency: 'HUF'}, user_data: {email: 'test@example.test'}},
        meta: {data: {value: 5000, currency: 'HUF'}, event_id: '123'},
    };
    const purchaser = await run([entry]);
    assert.strictEqual(purchaser.google.filter(args => args[0] === 'event').length, 1);
    assert.strictEqual(purchaser.google.find(args => args[0] === 'event')[2].transaction_id, '123');
    assert.strictEqual(purchaser.meta.length, 1);
    assert.strictEqual(purchaser.meta[0][3].eventID, '123');
    assert.strictEqual(purchaser.confirmations.length, 2);

    const denied = await run([entry], false);
    assert.strictEqual(denied.meta.length, 0, 'Recovery must still respect Meta consent.');
    assert.strictEqual(denied.google.filter(args => args[0] === 'set').length, 0, 'No enhanced conversion data without consent.');
    console.log('Purchase recovery: cache-independent lookup, empty response, both channels and consent checks passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
