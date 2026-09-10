const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../includes/class-custom-feed-manager.php'), 'utf8');
const script = source.match(/<script>([\s\S]*?)<\/script>/)[1]
  .replace(/<\?php echo wp_json_encode\(wp_create_nonce\('mg_custom_feed_progress'\)\); \?>/, '"test-nonce"')
  .replace(/<\?php echo wp_json_encode\(admin_url\('admin-ajax.php'\)\); \?>/, '"/wp-admin/admin-ajax.php"');

async function run() {
  const rows = ['one', 'two'].map(slug => ({dataset: {slug}, textContent: ''}));
  const timers = [];
  const requests = [];
  const replies = [
    {status: 'running', message: '10 processed'},
    new Error('Network failure'),
    {status: 'complete', message: 'Done'},
    {status: 'failed', message: 'Disk full'},
  ];
  vm.runInNewContext(script, {
    URLSearchParams,
    document: {querySelectorAll: () => rows},
    window: {setTimeout: fn => timers.push(fn)},
    fetch: async (url, options) => {
      assert.equal(url, '/wp-admin/admin-ajax.php');
      assert.equal(options.method, 'POST');
      assert.equal(options.credentials, 'same-origin');
      assert.equal(options.body.get('nonce'), 'test-nonce');
      assert.equal(options.body.get('action'), 'mg_custom_feed_progress');
      requests.push(options.body.get('slug'));
      const reply = replies.shift();
      if (reply instanceof Error) throw reply;
      return {ok: true, json: async () => ({success: true, data: reply})};
    },
  });
  assert.equal(timers.length, 1);
  await timers.shift()();
  assert.equal(rows[0].textContent, '10 processed');
  await timers.shift()();
  assert.match(rows[1].textContent, /Újrapróbálkozás/);
  await timers.shift()();
  await timers.shift()();
  assert.deepEqual(requests, ['one', 'two', 'one', 'two'], 'jobs advance sequentially and retry failed requests');
  assert.equal(rows[0].textContent, 'Done');
  assert.equal(rows[1].textContent, 'Disk full');
  assert.equal(timers.length, 0, 'completed and failed jobs stop polling');
  console.log('Custom feed progress tests passed.');
}
run().catch(error => { console.error(error); process.exitCode = 1; });
