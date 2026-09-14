// Runs the real admin script with a minimal jQuery adapter; no browser or network.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const handlers = new Map();
const document = {};
const empty = {
    length: 0,
    on(event, selector, callback) {
        if (typeof selector === 'string') handlers.set(event + ':' + selector, callback);
        return this;
    },
    ready(callback) { callback(); },
};
function $(target) { return target && target.testNode ? target : empty; }
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../assets/js/custom-fields-admin.js'), 'utf8'), { jQuery: $, document });
const apply = handlers.get('click:.mgcf-apply-ai-prompt');
assert.equal(typeof apply, 'function');
function field() {
    const state = { prompt: 'Saját, mentett utasítás', enabled: false, status: '', selected: null, events: [] };
    const form = { find(selector) {
        if (selector === '.mgcf-ai-prompt-template option:selected') return {
            attr: () => state.selected && state.selected.prompt,
            text: () => state.selected.label,
        };
        if (selector === '.mgcf-ai-prompt-status') return { text(value) { state.status = value; } };
        if (selector === '[name="field_ai_print_prompt"]') return {
            val(value) { state.prompt = value; return this; },
            trigger(event) { state.events.push(event); },
        };
        if (selector === '[name="field_ai_print_enabled"]') return {
            prop(name, value) { assert.equal(name, 'checked'); state.enabled = value; return this; },
            trigger(event) { state.events.push(event); },
        };
        throw new Error('Unexpected selector: ' + selector);
    } };
    return { state, button: { testNode: true, closest(selector) { assert.equal(selector, 'form'); return form; } } };
}
const first = field();
const second = field();
apply.call(first.button);
assert.equal(first.state.prompt, 'Saját, mentett utasítás');
assert.equal(first.state.enabled, false);
assert.match(first.state.status, /Előbb válassz/);
first.state.selected = { label: 'Név + évszám — Név mező', prompt: 'A nevet cseréld erre: {{ertek}}.' };
assert.equal(first.state.prompt, 'Saját, mentett utasítás');
apply.call(first.button);
assert.equal(first.state.prompt, first.state.selected.prompt);
assert.equal(first.state.enabled, true);
assert.match(first.state.status, /mentsd a mezőt/);
assert.deepEqual(first.state.events, ['input', 'change']);
assert.equal(second.state.prompt, 'Saját, mentett utasítás');
assert.equal(second.state.enabled, false);
assert.equal(second.state.status, '');
console.log('PASS: explicit template apply, empty selection, editable prompt, AI toggle and field isolation.');
