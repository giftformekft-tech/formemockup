// node tests/custom-fields-cart-confirm-test.js
// Runs the actual confirmation script with a small DOM and native-submit model.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../assets/js/custom-fields-cart-confirm.js'), 'utf8');

function setup(fields, { legacy = false, buttons = true } = {}) {
    const submissions = [];
    let valid = true;
    let blockSubmission = false;
    function element(tag) {
        return {
            tagName: tag.toUpperCase(), children: [], handlers: {}, style: {}, dataset: {},
            classList: { add() {}, remove() {} },
            appendChild(child) { this.children.push(child); child.parentNode = this; if (this === form) child.form = form; },
            removeChild(child) { this.children.splice(this.children.indexOf(child), 1); child.parentNode = null; },
            setAttribute(name, value) { this[name] = value; },
            addEventListener(name, fn) { (this.handlers[name] ||= []).push(fn); },
            emit(name, event = {}) { for (const fn of this.handlers[name] || []) fn(event); },
            focus() {}, showModal() { this.open = true; }, close() { this.open = false; },
            click() { if (this.disabled) return; this.emit('click'); if (this.type === 'submit' && this.form) dispatch(this); },
        };
    }
    const form = element('form');
    const primary = Object.assign(element('button'), { type: 'submit', name: 'add-to-cart', value: '42', form });
    const alternate = Object.assign(element('button'), { type: 'submit', name: 'add-to-cart', value: '99', form });
    const blocks = fields.map(field => ({
        querySelector(selector) {
            if (selector === 'input, select, textarea') {
                return {
                    tagName: field.select ? 'SELECT' : 'INPUT', type: field.linked ? 'hidden' : 'text',
                    value: field.value, options: [{ text: field.value || 'Válassz…' }], selectedIndex: 0,
                };
            }
            if (field.linked ? selector.includes('.mg-custom-field__label') : selector.includes('label')) {
                return { textContent: field.label || 'Felirat' };
            }
            return null;
        },
    }));
    form.querySelectorAll = () => blocks;
    form.querySelector = selector => buttons ? (selector === '.single_add_to_cart_button' ? primary : alternate) : null;
    form.submit = () => { throw new Error('Direct form.submit() loses the WooCommerce submitter and bypasses validation'); };
    function dispatch(submitter, exposeSubmitter = true) {
        if (!valid || submitter?.disabled) return;
        const event = {
            currentTarget: form, target: form, submitter: exposeSubmitter ? submitter : undefined,
            defaultPrevented: false, stopped: false,
            preventDefault() { this.defaultPrevented = true; },
            stopImmediatePropagation() { this.stopped = true; },
        };
        for (const fn of form.handlers.submit || []) { fn(event); if (event.stopped) break; }
        if (!event.stopped && blockSubmission) event.preventDefault(); // e.g. variant size guard
        if (!event.defaultPrevented) submissions.push(submitter ? { name: submitter.name, value: submitter.value } : {});
    }
    if (!legacy) form.requestSubmit = submitter => dispatch(submitter);
    const body = element('body');
    const document = {
        readyState: 'complete', body, head: element('head'), createElement: element,
        getElementById() { return null; }, addEventListener() {}, removeEventListener() {},
        querySelectorAll() { return [form]; },
    };
    vm.runInNewContext(source, { document, window: {}, WeakSet });
    function find(root, predicate) {
        if (predicate(root)) return root;
        for (const child of root.children || []) { const match = find(child, predicate); if (match) return match; }
    }
    function clickModal(className) {
        const button = find(body, node => node.className === className);
        assert.ok(button, 'Expected confirmation button');
        button.click();
    }
    return {
        form, primary, alternate, submissions,
        submit: (submitter = primary, exposeSubmitter = true) => dispatch(submitter, exposeSubmitter),
        confirm: () => clickModal('mgcc-btn mgcc-btn--ok'),
        cancel: () => clickModal('mgcc-btn mgcc-btn--cancel'),
        modal: () => find(body, node => node.tagName === 'DIALOG'),
        texts: () => { const out = []; (function walk(node) { if (node.textContent) out.push(node.textContent); node.children.forEach(walk); })(body); return out; },
        setValid: value => { valid = value; },
        setBlocked: value => { blockSubmission = value; },
    };
}

for (const fields of [[], [{ value: '' }], [{ value: '', select: true }]]) {
    const ui = setup(fields);
    ui.submit();
    assert.deepEqual(ui.submissions, [{ name: 'add-to-cart', value: '42' }], 'Empty fields preserve the original submission');
    assert.equal(ui.modal(), undefined, 'Empty values need no confirmation');
}

for (const legacy of [false, true]) {
    const ui = setup([{ label: 'Hónap *', value: 'Szeptember', linked: true }], { legacy });
    ui.submit(ui.alternate);
    assert.equal(ui.submissions.length, 0);
    assert.ok(ui.texts().includes('Hónap'), 'The linked selector span label is included');
    assert.ok(ui.texts().includes('Szeptember'), 'The populated hidden value is included');
    ui.confirm();
    assert.deepEqual(ui.submissions, [{ name: 'add-to-cart', value: '99' }], 'Confirmation preserves the actual submitter');
    assert.equal(ui.modal(), undefined, 'Confirmation closes once');
}

const cancelled = setup([{ value: 'Anna' }]);
cancelled.submit();
cancelled.cancel();
assert.equal(cancelled.submissions.length, 0, 'Cancel does not add to cart');
cancelled.submit();
assert.ok(cancelled.modal(), 'A new attempt still requires confirmation');
cancelled.confirm();
assert.equal(cancelled.submissions.length, 1);

const invalid = setup([{ value: 'Anna' }]);
invalid.submit();
invalid.setValid(false);
invalid.confirm();
assert.equal(invalid.submissions.length, 0, 'Native validation is rerun after confirmation');
invalid.setValid(true);
invalid.submit();
assert.ok(invalid.modal(), 'Failed validation does not leave a stale confirmation');
invalid.confirm();
assert.equal(invalid.submissions.length, 1);

const guarded = setup([{ value: 'Anna' }]);
guarded.submit();
guarded.setBlocked(true);
guarded.confirm();
assert.equal(guarded.submissions.length, 0, 'Other submit handlers can still reject a missing variant size');

const disabled = setup([{ value: 'Anna' }]);
disabled.submit();
disabled.primary.disabled = true;
disabled.confirm();
assert.equal(disabled.submissions.length, 0, 'A disabled submitter is not submitted');

const oldEvent = setup([{ value: 'Anna' }], { legacy: true });
oldEvent.submit(oldEvent.primary, false);
oldEvent.confirm();
assert.deepEqual(oldEvent.submissions, [{ name: 'add-to-cart', value: '42' }], 'Without SubmitEvent.submitter the Woo button is preferred');

for (const legacy of [false, true]) {
    const noButton = setup([{ value: 'Anna' }], { buttons: false, legacy });
    noButton.submit(null);
    noButton.confirm();
    assert.equal(noButton.submissions.length, 1, 'Buttonless forms use eventful submission');
    assert.equal(noButton.form.children.length, 0, 'Temporary fallback button is removed');
}

console.log('Custom-field cart confirmation regression tests passed.');
