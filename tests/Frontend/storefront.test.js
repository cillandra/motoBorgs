import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';
import { webcrypto } from 'node:crypto';

const source = (await readFile(new URL('../../resources/js/storefront.js', import.meta.url), 'utf8')).replace("import './dialogs.js';", '');

function harness(responses, defaults = {}) {
    const node = () => ({ hidden: true, value: '', textContent: '', dataset: {}, handlers: {}, classList: { toggle() {} }, focus() {}, setAttribute() {}, removeAttribute() {}, addEventListener(type, handler) { this.handlers[type] = handler; } });
    const fields = Object.fromEntries(['name', 'email', 'phone', 'message', 'website', 'submission_key', 'topic', 'variant_id', 'service_name', 'subject_label', '_token'].map(name => [name, { ...node(), name, value: defaults[name] || '' }]));
    Object.defineProperty(fields, 'namedItem', { value: name => fields[name] });
    const feedback = node(), send = node(), another = node(), count = node(), subject = node(), trigger = node();
    trigger.dataset = {};
    const form = { ...node(), action: 'http://moto.test/inquiries', dataset: { tokenUrl: 'http://moto.test/' }, elements: fields,
        reset() { for (const [name, field] of Object.entries(fields)) field.value = defaults[name] || ''; },
        reportValidity: () => true,
        querySelector(selector) { return selector.startsWith('[data-field-error=') ? node() : ({ '[data-inquiry-feedback]': feedback, '[type=submit]': send, '[data-new-inquiry]': another, '[data-message-count]': count, '[data-inquiry-subject]': subject })[selector]; },
        querySelectorAll(selector) { return selector.startsWith('input:') ? ['name', 'email', 'phone', 'website', 'message'].map(name => fields[name]) : []; },
    };
    const dialog = { ...node(), open: false, showModal() { this.open = true; } };
    const requests = [];
    const context = { document: { querySelector(selector) { return ({ '#shop-inquiry': dialog, '[data-inquiry-form]': form })[selector] || null; }, querySelectorAll(selector) { return selector === '[data-inquire]' ? [trigger] : []; } }, crypto: webcrypto, CSS: { escape: value => value }, AbortController, setTimeout, clearTimeout,
        FormData: class { constructor() { this.data = new Map(Object.entries(fields).map(([key, field]) => [key, field.value])); } get(key) { return this.data.get(key); } set(key, value) { this.data.set(key, value); } },
        DOMParser: class { parseFromString() { return { querySelector: () => ({ content: 'renewed-token' }) }; } },
        fetch: async (url, options) => { requests.push({ url, data: options?.body && new Map(options.body.data) }); const response = responses.shift(); if (response instanceof Error) throw response; return { ok: response.status < 300, status: response.status, json: async () => response.body, text: async () => '<meta name="csrf-token" content="renewed-token">' }; },
    };
    vm.runInNewContext(source, context);
    fields.name.value = 'Demo visitor'; fields.email.value = 'demo@example.test'; fields.message.value = 'Does this part fit my motorcycle?'; fields.submission_key.value = 'fixed-key'; fields._token.value = 'old-token';
    return { fields, feedback, send, another, trigger, requests, submit: () => form.handlers.submit({ preventDefault() {} }) };
}

test('lost response followed by throttling preserves the original payload and reference', async () => {
    const ui = harness([new Error('lost response'), { status: 429, body: {} }, { status: 200, body: { reference: 'INQ-001', message: 'Saved.' } }]);
    await ui.submit(); await ui.submit();
    assert.equal(ui.fields.message.readOnly, true);
    await ui.trigger.handlers.click();
    assert.equal(ui.fields.submission_key.value, 'fixed-key');
    await ui.submit();
    assert.deepEqual(ui.requests[0].data, ui.requests[2].data);
    assert.match(ui.feedback.textContent, /INQ-001/);
});

test('known CSRF rejection followed by validation errors allows correcting the draft', async () => {
    const ui = harness([{ status: 419, body: {} }, { status: 200 }, { status: 422, body: { errors: { phone: ['Enter a valid phone number.'] } } }]);
    ui.fields.phone.value = 'invalid';
    await ui.submit(); await ui.submit();
    assert.equal(ui.fields.phone.readOnly, false);
    assert.equal(ui.send.textContent, 'Send inquiry');
});

test('expired session renews only the token and keeps uncertain inquiry data frozen', async () => {
    const ui = harness([new Error('lost response'), { status: 419, body: {} }, { status: 200 }, { status: 200, body: { reference: 'INQ-001', message: 'Saved.' } }]);
    await ui.submit(); await ui.submit();
    assert.equal(ui.fields.message.readOnly, true);
    assert.equal(ui.fields._token.value, 'renewed-token');
    await ui.submit();
    assert.equal(ui.requests[3].data.get('submission_key'), 'fixed-key');
    assert.equal(ui.requests[3].data.get('_token'), 'renewed-token');
    assert.equal(ui.requests[0].data.get('message'), ui.requests[3].data.get('message'));
    assert.match(ui.feedback.textContent, /INQ-001/);
    await ui.another.handlers.click();
    assert.equal(ui.fields._token.value, 'renewed-token');
});

test('starting a new inquiry clears server-restored personal details', async () => {
    const ui = harness([{ status: 201, body: { reference: 'INQ-001', message: 'Saved.' } }], { name: 'Previous visitor', message: 'Previous question', email: 'previous@example.test' });
    await ui.submit(); await ui.another.handlers.click();
    for (const name of ['name', 'email', 'phone', 'message', 'website']) assert.equal(ui.fields[name].value, '');
    assert.notEqual(ui.fields.submission_key.value, 'fixed-key');
});
