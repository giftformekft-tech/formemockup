const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const source = fs.readFileSync(path.join(__dirname, '..', 'includes', 'class-purchase-recovery.php'), 'utf8');
const recovery = source.split('public static function output_recovery_script() {')[1].split('/* ---------------------------------------------------------------------')[0];
const prelude = recovery.split('?>')[0];
assert(!/read_entries|\$_COOKIE/.test(prelude), 'Cached recovery HTML must not depend on the visitor cookie.');
assert(prelude.includes('is_order_received_page()'), 'Only the never-cached thank-you page may get a different script.');
assert(/function ajax_pending_conversions\(\)\s*\{\s*nocache_headers\(\);/.test(source), 'Even empty responses must disable caching.');
const template = recovery.match(/<script>([\s\S]*?)<\/script>/)[1];
assert.strictEqual((template.match(/<\?php/g) || []).length, 2, 'The script has exactly two server-filled values.');

function build(thankYou) {
    return template
        .replace("<?php echo esc_js(self::FLAG_COOKIE); ?>", 'mg_pending_conv_f')
        .replace("<?php echo $thank_you ? 'true' : 'false'; ?>", thankYou ? 'true' : 'false');
}

async function run(responses, { consent = true, cookie = 'mg_pending_conv_f=1', thankYou = false, rendered } = {}) {
    const requests = [];
    const google = [];
    const meta = [];
    const confirmations = [];
    const intervals = new Map();
    const timeouts = [];
    let nextTimer = 0;
    const fetch = async (url, options) => {
        requests.push({url, options});
        const data = responses[Math.min(requests.length, responses.length) - 1];
        return {json: async () => ({success: true, data})};
    };
    const window = {
        fetch,
        mgConvAjaxUrl: 'https://shop.test/wp-admin/admin-ajax.php',
        mgGadsConsentDecided: true,
        mgGadsConsentGranted: consent,
        mgFbConsentGranted: consent,
        gtag: (...args) => google.push(args),
        fbq: (...args) => meta.push(args),
        mgConvFired: (...args) => confirmations.push(args),
    };
    if (rendered) window.mgConvRendered = rendered;
    vm.runInNewContext(build(thankYou), {
        window,
        document: {cookie},
        fetch,
        setInterval(fn) { intervals.set(++nextTimer, fn); return nextTimer; },
        clearInterval(id) { intervals.delete(id); },
        setTimeout(fn, delay) { timeouts.push({fn, delay}); },
    });
    const settle = () => new Promise(resolve => setImmediate(resolve));
    const tick = () => { for (let i = 0; i < 20; i++) for (const fn of [...intervals.values()]) fn(); };
    await settle();
    tick();
    // Minden ütemezett újrakérdezést lefuttatunk, amíg el nem fogynak.
    const delays = [];
    while (timeouts.length) {
        const next = timeouts.shift();
        delays.push(next.delay);
        next.fn();
        await settle();
        tick();
    }
    assert.strictEqual(intervals.size, 0);
    return {requests, google, meta, confirmations, delays};
}

const entry = {
    order_id: 123,
    order_key: 'wc_order_test',
    google: {event: {transaction_id: '123', value: 5000, currency: 'HUF'}, user_data: {email: 'test@example.test'}},
    meta: {data: {value: 5000, currency: 'HUF'}, event_id: '123'},
};
const none = {pending: [], waiting: false};
const purchases = result => result.google.filter(args => args[0] === 'event').length;

(async () => {
    // A legtöbb látogatónak nincs megjegyzett rendelése: náluk kérés sem indul.
    const visitor = await run([none], {cookie: 'mg_gads_consent=granted; other=1'});
    assert.strictEqual(visitor.requests.length, 0, 'No lookup without the flag cookie.');

    // The exact same cached script serves a visitor whose order is returned by
    // the uncached endpoint.
    const empty = await run([none]);
    assert.strictEqual(empty.requests.length, 1);
    assert.strictEqual(empty.requests[0].url, 'https://shop.test/wp-admin/admin-ajax.php');
    const options = empty.requests[0].options;
    assert.strictEqual(options.method, 'POST');
    assert.strictEqual(options.credentials, 'same-origin', 'The browser must send its current HttpOnly cookie.');
    assert.strictEqual(options.cache, 'no-store');
    assert.strictEqual(options.headers['Content-Type'], 'application/x-www-form-urlencoded');
    assert.strictEqual(options.body, 'action=mg_pending_conversions');
    assert.strictEqual(purchases(empty) + empty.meta.length + empty.confirmations.length, 0);

    const purchaser = await run([{pending: [entry], waiting: false}]);
    assert.strictEqual(purchases(purchaser), 1);
    assert.strictEqual(purchaser.google.find(args => args[0] === 'event')[2].transaction_id, '123');
    assert.strictEqual(purchaser.meta.length, 1);
    assert.strictEqual(purchaser.meta[0][3].eventID, '123');
    assert.strictEqual(purchaser.confirmations.length, 2);

    const denied = await run([{pending: [entry], waiting: false}], {consent: false});
    assert.strictEqual(denied.meta.length, 0, 'Recovery must still respect Meta consent.');
    assert.strictEqual(denied.google.filter(args => args[0] === 'set').length, 0, 'No enhanced conversion data without consent.');

    // Máshol egyetlen lekérdezés van, akkor is, ha a rendelés még fizetésre vár.
    const elsewhere = await run([{pending: [], waiting: true}]);
    assert.strictEqual(elsewhere.requests.length, 1, 'Only the thank-you page polls.');

    // Köszönőoldal: a fizetés visszaigazolásáig újrakérdez, utána elküld és megáll.
    const paidLater = await run([{pending: [], waiting: true}, {pending: [], waiting: true}, {pending: [entry], waiting: false}], {thankYou: true});
    assert.strictEqual(paidLater.requests.length, 3);
    assert.deepStrictEqual(paidLater.delays, [3000, 5000]);
    assert.strictEqual(purchases(paidLater), 1);
    assert.strictEqual(paidLater.meta.length, 1);

    // Az egymást követő válaszok ugyanazt a rendelést adják vissza, amíg a
    // visszaigazolás meg nem érkezik a szerverre: ettől még egyszer megy ki.
    const repeated = await run([{pending: [entry], waiting: true}, {pending: [entry], waiting: false}], {thankYou: true});
    assert.strictEqual(repeated.requests.length, 2);
    assert.strictEqual(purchases(repeated), 1, 'A repeated answer must not send the purchase twice.');
    assert.strictEqual(repeated.meta.length, 1);
    assert.strictEqual(repeated.confirmations.length, 2);

    // A soha ki nem fizetett rendelésnél az újrakérdezés véges.
    const never = await run([{pending: [], waiting: true}], {thankYou: true});
    assert.strictEqual(never.requests.length, 10, 'Polling must end.');
    assert(never.delays.reduce((sum, delay) => sum + delay, 0) <= 150000, 'Polling stays around two minutes.');

    // Amit a köszönőoldal saját tagje kiírt, azt a pótlás nem duplikálja.
    const own = await run([{pending: [entry], waiting: false}], {thankYou: true, rendered: {'google:123': true, 'meta:123': true}});
    assert.strictEqual(purchases(own) + own.meta.length + own.confirmations.length, 0, 'The thank-you tag keeps its own purchase.');
    const half = await run([{pending: [entry], waiting: false}], {thankYou: true, rendered: {'google:123': true}});
    assert.strictEqual(purchases(half), 0);
    assert.strictEqual(half.meta.length, 1, 'A channel the page did not render is still recovered.');

    console.log('Purchase recovery: flag-gated lookup, cache-independent script, both channels, consent, thank-you polling and duplicate guards passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
