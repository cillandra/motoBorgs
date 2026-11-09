import './dialogs.js';

const menu = document.querySelector('[data-shop-menu]');
const navigation = document.querySelector('#shop-navigation');
if (menu && navigation) {
    menu.hidden = false;
    document.querySelector('.shop-header').dataset.menuReady = 'true';
    const closeMenu = () => { navigation.classList.remove('is-open'); menu.setAttribute('aria-expanded', 'false'); };
    menu.addEventListener('click', () => {
        const open = menu.getAttribute('aria-expanded') !== 'true';
        menu.setAttribute('aria-expanded', String(open));
        navigation.classList.toggle('is-open', open);
    });
    navigation.addEventListener('click', event => { if (event.target.closest('a,button')) closeMenu(); });
    document.addEventListener('keydown', event => { if (event.key === 'Escape' && menu.getAttribute('aria-expanded') === 'true') { closeMenu(); menu.focus(); } });
}

document.querySelectorAll('[data-catalog-image]').forEach(img => {
    const fallback = () => { img.hidden = true; img.parentElement.querySelector('.shop-product-placeholder').hidden = false; };
    img.addEventListener('error', fallback);
    if (img.complete && !img.naturalWidth) fallback();
});

const dialog = document.querySelector('#shop-inquiry');
const form = document.querySelector('[data-inquiry-form]');
const feedback = form?.querySelector('[data-inquiry-feedback]');
const send = form?.querySelector('[type=submit]');
const another = form?.querySelector('[data-new-inquiry]');
let busy = false;
let completed = false;
let pending = null;
let uncertain = false;
let inquiryTrigger = null;
const requestKey = () => crypto.randomUUID?.() || '10000000-1000-4000-8000-100000000000'.replace(/[018]/g, c => (Number(c) ^ crypto.getRandomValues(new Uint8Array(1))[0] & 15 >> Number(c) / 4).toString(16));
const updateCount = () => { if (form) form.querySelector('[data-message-count]').textContent = `${form.elements.message.value.length.toLocaleString()} / 2,000`; };
const readOnly = value => form?.querySelectorAll('input:not([type=hidden]),textarea').forEach(field => { field.readOnly = value; });
function announce(message, success = false) {
    feedback.hidden = false;
    feedback.textContent = message;
    feedback.classList.toggle('is-success', success);
}
function clearErrors() {
    form.querySelectorAll('[data-field-error]').forEach(error => { error.textContent = ''; error.hidden = true; });
    form.querySelectorAll('[aria-invalid]').forEach(field => { field.removeAttribute('aria-invalid'); field.removeAttribute('aria-errormessage'); });
    feedback.hidden = true;
}
function fieldErrors(errors) {
    let first = null;
    for (const [name, messages] of Object.entries(errors)) {
        const field = form.elements.namedItem(name);
        const label = form.querySelector(`[data-field-error="${CSS.escape(name)}"]`);
        if (field && label) {
            label.textContent = messages[0]; label.hidden = false;
            field.setAttribute('aria-invalid', 'true'); field.setAttribute('aria-errormessage', label.id);
            first ||= field;
        }
    }
    (first || feedback).focus();
}
function resetInquiry() {
    const token = form.elements._token.value;
    form.reset();
    form.elements._token.value = token;
    for (const name of ['name', 'email', 'phone', 'message', 'website']) form.elements[name].value = '';
    completed = false; pending = null; uncertain = false;
    readOnly(false); clearErrors();
    form.elements.submission_key.value = requestKey();
    form.elements.topic.value = 'general';
    form.elements.variant_id.value = '';
    form.elements.service_name.value = '';
    form.elements.subject_label.value = 'General inquiry';
    form.querySelector('[data-inquiry-subject]').textContent = 'General inquiry';
    send.disabled = false; send.textContent = 'Send inquiry'; another.hidden = true;
    updateCount();
}
function openInquiry({topic = 'general', variant = '', subject = 'General inquiry'} = {}, trigger = null) {
    if (!form || !dialog) return;
    if (completed) resetInquiry();
    inquiryTrigger = trigger;
    if (!busy && !pending) {
        const changed = form.elements.subject_label.value !== subject && form.elements.message.value.trim();
        form.elements.submission_key.value = requestKey();
        form.elements.topic.value = topic;
        form.elements.variant_id.value = variant;
        form.elements.service_name.value = topic === 'service' ? subject : '';
        form.elements.subject_label.value = subject;
        form.querySelector('[data-inquiry-subject]').textContent = subject;
        clearErrors();
        if (changed) announce('Your draft is still here. The selected topic changed, so check your question before sending.');
    }
    if (!dialog.open) dialog.showModal();
    updateCount();
}
document.querySelectorAll('[data-inquire]').forEach(button => button.addEventListener('click', () => openInquiry({topic: button.dataset.topic || 'general', variant: button.dataset.variant || '', subject: button.dataset.subject || 'General inquiry'}, button)));
dialog?.addEventListener('close', () => {
    if (inquiryTrigger?.isConnected && inquiryTrigger.getClientRects().length) inquiryTrigger.focus({preventScroll: true});
    else if (menu && !menu.hidden && menu.getClientRects().length) menu.focus({preventScroll: true});
});
another?.addEventListener('click', () => { if (!busy) { resetInquiry(); form.elements.name.focus(); } });
form?.elements.message.addEventListener('input', updateCount);
form?.addEventListener('submit', async event => {
    event.preventDefault();
    if (busy || completed) return;
    clearErrors();
    if (!pending) {
        for (const name of ['name', 'email', 'phone', 'message']) form.elements[name].value = form.elements[name].value.trim();
        const errors = {};
        if (!form.elements.name.value) errors.name = ['Enter your name.'];
        if (!form.elements.phone.value && !form.elements.email.value) errors.phone = ['Enter a phone number or email so the shop can follow up.'];
        if (form.elements.message.value.length < 5) errors.message = ['Write a question with at least 5 characters.'];
        if (Object.keys(errors).length) { fieldErrors(errors); updateCount(); return; }
        if (!form.reportValidity()) return;
        pending = new FormData(form);
    }
    busy = true;
    form.setAttribute('aria-busy', 'true');
    readOnly(true); send.disabled = true; send.textContent = 'Sending…';
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 15000);
    try {
        const response = await fetch(form.action, {method: 'POST', body: pending, headers: {Accept: 'application/json'}, signal: controller.signal});
        const data = await response.json().catch(() => null);
        if (response.ok && data?.reference) {
            completed = true; pending = null; uncertain = false;
            announce(`Inquiry received · ${data.reference}\n${data.message}`, true);
            send.textContent = 'Inquiry received'; another.hidden = false;
            feedback.focus();
        } else if (response.status >= 500 || (response.ok && !data?.reference)) {
            throw new Error('unconfirmed');
        } else if (response.status === 419) {
            // Renew CSRF without discarding the idempotency key or storing personal data.
            try {
                const fresh = await fetch(form.dataset.tokenUrl, {headers: {Accept: 'text/html'}, cache: 'no-store', signal: controller.signal});
                const token = fresh.ok && new DOMParser().parseFromString(await fresh.text(), 'text/html').querySelector('meta[name="csrf-token"]')?.content;
                if (!token) throw new Error('token unavailable');
                form.elements._token.value = token;
                pending.set('_token', token);
                announce('Your page session has been renewed. Retry this inquiry to confirm the same request. Your question is safely kept here.');
            } catch {
                announce('We couldn’t renew your page session. Keep this page open and retry when the connection is available. Your question is still here.');
            }
            feedback.focus();
        } else if (uncertain) {
            // A rejected retry cannot establish whether the original request was saved.
            announce(response.status === 429 ? 'Please wait a minute, then retry this inquiry. We’ll check the same request without sending a duplicate.' : 'We still couldn’t confirm the original inquiry. Keep this page open and retry the same request.');
            feedback.focus();
        } else {
            pending = null; readOnly(false);
            const message = response.status === 429 ? 'You’ve sent several inquiries. Please wait a minute before trying again.' : Object.values(data?.errors || {}).flat()[0] || data?.message || 'We couldn’t save the inquiry. Check your details and try again.';
            announce(message);
            if (data?.errors) fieldErrors(data.errors); else feedback.focus();
        }
    } catch {
        uncertain = true;
        announce('We couldn’t confirm whether your inquiry was saved. Your message is still here. Retry to check the same inquiry without sending a duplicate.');
        feedback.focus();
    } finally {
        clearTimeout(timeout);
        busy = false; form.setAttribute('aria-busy', 'false');
        send.disabled = completed;
        if (!completed) send.textContent = pending ? 'Retry this inquiry' : 'Send inquiry';
    }
});
if (feedback && !feedback.hidden && feedback.textContent.trim()) dialog.showModal();
updateCount();

const detail = document.querySelector('#shop-product-detail');
document.querySelectorAll('[data-product-open]').forEach(trigger => trigger.addEventListener('click', () => {
    const template = document.getElementById(`shop-product-${trigger.dataset.productOpen}`);
    if (!template || !detail) return;
    const body = detail.querySelector('[data-product-detail-body]');
    body.replaceChildren(template.content.cloneNode(true));
    const option = body.querySelector('[data-product-option]');
    const ask = body.querySelector('[data-selected-inquiry]');
    const update = () => {
        const selected = option.selectedOptions[0];
        ask.disabled = !option.value;
        body.querySelector('[data-product-option-info]').textContent = option.value ? `${selected.dataset.sku} · ${selected.dataset.availability}` : 'Choose an option to see its availability and ask a question.';
    };
    option.addEventListener('change', update);
    ask.addEventListener('click', () => { if (option.value) { const selected = option.selectedOptions[0]; detail.close(); openInquiry({topic: 'product', variant: option.value, subject: selected.dataset.subject}, trigger); } });
    if (option.options.length === 2) option.selectedIndex = 1;
    update();
    detail.showModal();
}));
