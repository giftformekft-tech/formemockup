const assert = require('assert');
const crypto = require('crypto');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const adminSource = fs.readFileSync(
  path.join(__dirname, '..', 'admin', 'class-google-ads-product-performance-page.php'),
  'utf8'
);
const templateMatch = adminSource.match(/\$template = <<<'JS'\r?\n([\s\S]*?)\r?\nJS;/);
assert(templateMatch, 'The generated Google Ads script template must be extractable.');

const script = templateMatch[1]
  .replaceAll('__ENDPOINT__', JSON.stringify('https://example.test/wp-json/mg-ads/v1/performance'))
  .replaceAll('__SECRET__', JSON.stringify('test-secret'))
  .replaceAll('__START_DATE__', JSON.stringify('2026-01-01'))
  .replaceAll('__LAG_DAYS__', '3')
  .replaceAll('__ACTION_NAME__', JSON.stringify('Purchase'))
  .replaceAll('__CAMPAIGN_IDS__', JSON.stringify([111, 222]))
  .replaceAll('__IMPORT_SCOPE__', JSON.stringify('test-scope'));

// Parse the exact generated script before exercising its resumability protocol.
new vm.Script(script);

function createHarness() {
  const properties = {};
  let uuid = 0;
  let remainingTimes = [1000];
  const scriptProperties = {
    getProperty(key) { return Object.prototype.hasOwnProperty.call(properties, key) ? properties[key] : null; },
    setProperty(key, value) { properties[key] = String(value); },
    deleteProperty(key) { delete properties[key]; },
  };
  const sandbox = {
    console,
    Date,
    JSON,
    Math,
    Number,
    Object,
    String,
    Error,
    PropertiesService: { getScriptProperties() { return scriptProperties; } },
    Utilities: {
      getUuid() { uuid += 1; return `attempt-${uuid}`; },
      formatDate() { return '2026-08-28'; },
      computeHmacSha256Signature() { return [1, 2, 3]; },
      computeDigest(algorithm, value) {
        return Array.from(crypto.createHash('sha256').update(String(value), 'utf8').digest());
      },
      DigestAlgorithm: { SHA_256: 'SHA-256' },
      Charset: { UTF_8: 'UTF-8' },
    },
    AdsApp: {
      currentAccount() {
        return {
          getCustomerId() { return '123-456-7890'; },
          getCurrencyCode() { return 'HUF'; },
          getTimeZone() { return 'Europe/Budapest'; },
        };
      },
      getExecutionInfo() {
        return { getRemainingTime() { return remainingTimes.length ? remainingTimes.shift() : 1000; } };
      },
    },
    UrlFetchApp: {},
  };
  vm.runInNewContext(script, sandbox);
  return {
    sandbox,
    properties,
    setRemainingTimes(values) { remainingTimes = values.slice(); },
  };
}

function acknowledgmentFor(payload) {
  const parsed = JSON.parse(payload);
  return {
    success: true,
    batch_index: parsed.batch_index,
    batch_count: parsed.batch_count,
    range_complete: parsed.batch_index + 1 === parsed.batch_count,
  };
}

{
  const harness = createHarness();
  const sent = [];
  harness.sandbox.sendPayload = (payload) => {
    sent.push(JSON.parse(payload));
    return acknowledgmentFor(payload);
  };
  assert.strictEqual(harness.sandbox.sendRows([], '2026-01-01', '2026-01-07', 'initial'), true);
  assert.strictEqual(sent.length, 1, 'An empty range must still send one authoritative batch.');
  assert.strictEqual(sent[0].rows.length, 0);
  assert.strictEqual(sent[0].batch_count, 1);
}

{
  const harness = createHarness();
  let firstAttempt = '';
  harness.sandbox.sendPayload = (payload) => {
    firstAttempt = JSON.parse(payload).attempt_id;
    throw new Error('simulated network failure');
  };
  assert.throws(() => harness.sandbox.sendRows([], '2026-02-01', '2026-02-07', 'initial'));
  assert.strictEqual(harness.properties.MG_ACTIVE_RANGE_ATTEMPT, firstAttempt, 'A failed request must retain its attempt ID.');

  let retriedAttempt = '';
  harness.sandbox.sendPayload = (payload) => {
    retriedAttempt = JSON.parse(payload).attempt_id;
    return acknowledgmentFor(payload);
  };
  assert.strictEqual(harness.sandbox.sendRows([], '2026-02-01', '2026-02-07', 'initial'), true);
  assert.strictEqual(retriedAttempt, firstAttempt, 'A network retry must reuse the same attempt ID.');
  assert.strictEqual(harness.properties.MG_ACTIVE_RANGE_ATTEMPT, undefined);
}

{
  const harness = createHarness();
  const rows = Array.from({ length: 600 }, (_, index) => ({
    date: '2026-03-01',
    offer_id: `SKU_${String(index).padStart(4, '0')}`,
  }));
  const sentBatches = [];
  harness.sandbox.sendPayload = (payload) => {
    const parsed = JSON.parse(payload);
    sentBatches.push({ index: parsed.batch_index, attempt: parsed.attempt_id });
    return acknowledgmentFor(payload);
  };

  harness.setRemainingTimes([1000, 0]);
  assert.strictEqual(harness.sandbox.sendRows(rows, '2026-03-01', '2026-03-07', 'initial'), false);
  assert.deepStrictEqual(sentBatches.map((batch) => batch.index), [0]);
  assert.strictEqual(harness.properties.MG_ACTIVE_RANGE_NEXT_BATCH, '1');
  const attempt = harness.properties.MG_ACTIVE_RANGE_ATTEMPT;

  harness.setRemainingTimes([1000]);
  assert.strictEqual(harness.sandbox.sendRows(rows, '2026-03-01', '2026-03-07', 'initial'), true);
  assert.deepStrictEqual(sentBatches.map((batch) => batch.index), [0, 1], 'The next execution must resume at the first unacknowledged batch.');
  assert.strictEqual(sentBatches[1].attempt, attempt);
  assert.strictEqual(harness.properties.MG_ACTIVE_RANGE_NEXT_BATCH, undefined);
}

{
  const harness = createHarness();
  const originalRows = Array.from({ length: 600 }, (_, index) => ({ date: '2026-04-01', offer_id: `SKU_${index}`, clicks: 1 }));
  const changedRows = originalRows.map((row, index) => index === 0 ? { ...row, clicks: 2 } : row);
  const sent = [];
  harness.sandbox.sendPayload = (payload) => {
    const parsed = JSON.parse(payload);
    sent.push({ batch: parsed.batch_index, snapshot: parsed.snapshot_id, attempt: parsed.attempt_id });
    return acknowledgmentFor(payload);
  };
  harness.setRemainingTimes([1000, 0]);
  assert.strictEqual(harness.sandbox.sendRows(originalRows, '2026-04-01', '2026-04-07', 'initial'), false);
  const firstAttempt = harness.properties.MG_ACTIVE_RANGE_ATTEMPT;
  const firstSnapshot = harness.properties.MG_ACTIVE_RANGE_SNAPSHOT;

  harness.setRemainingTimes([1000, 1000]);
  assert.strictEqual(harness.sandbox.sendRows(changedRows, '2026-04-01', '2026-04-07', 'initial'), true);
  assert.deepStrictEqual(sent.map((entry) => entry.batch), [0, 0, 1], 'A changed Ads snapshot must restart the range at batch zero.');
  assert.strictEqual(sent[1].attempt, firstAttempt, 'A snapshot restart must retain the attempt lease.');
  assert.notStrictEqual(sent[1].snapshot, firstSnapshot);
}

{
  const harness = createHarness();
  const rows = Array.from({ length: 600 }, (_, index) => ({ date: '2026-05-01', offer_id: `SKU_${index}` }));
  let call = 0;
  harness.sandbox.sendPayload = (payload) => {
    const parsed = JSON.parse(payload);
    call += 1;
    if (call === 2) {
      return { success: true, batch_index: 1, batch_count: 2, range_complete: false, restart_range: true };
    }
    return acknowledgmentFor(payload);
  };
  harness.setRemainingTimes([1000, 1000]);
  assert.strictEqual(harness.sandbox.sendRows(rows, '2026-05-01', '2026-05-07', 'initial'), false);
  assert.strictEqual(harness.properties.MG_ACTIVE_RANGE_NEXT_BATCH, '0', 'A server restart request must reset the local batch cursor.');
  harness.setRemainingTimes([1000, 1000]);
  assert.strictEqual(harness.sandbox.sendRows(rows, '2026-05-01', '2026-05-07', 'initial'), true);
}

console.log('Google Ads generated script tests passed.');
