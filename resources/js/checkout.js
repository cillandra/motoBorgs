const form = document.querySelector('[data-checkout-form]');
const receipt = document.querySelector('[data-receipt]');
if (receipt) {
    const storageKey = 'moto.checkout.' + receipt.dataset.user;
    try {
        const attempt = JSON.parse(sessionStorage.getItem(storageKey));
        if (attempt?.payload?.request_key === receipt.dataset.requestKey) sessionStorage.removeItem(storageKey);
    } catch {}
    receipt.querySelector('[data-print-receipt]')?.addEventListener('click', () => {
        try { window.print(); }
        catch { receipt.querySelector('[data-print-message]').textContent = 'Printing could not open. This sale is saved. Reopen a copy to print again.'; }
    });
}
if (form) {
    const dialog = form.closest('dialog');
    const pos = form.closest('[data-pos]');
    const storageKey = 'moto.checkout.' + form.dataset.user;
    const errorBox = form.querySelector('[data-checkout-error]');
    const recovery = form.querySelector('[data-checkout-recovery]');
    const submit = form.querySelector('[data-checkout-submit]');
    let attempt = null;
    let busy = false;
    let uncertain = false;
    try { attempt = JSON.parse(sessionStorage.getItem(storageKey)); } catch {}
    const save = () => { try { sessionStorage.setItem(storageKey, JSON.stringify(attempt)); } catch {} };
    const message = (text) => { errorBox.textContent = text; errorBox.hidden = false; };
    const cents = (value) => {
        if (!/^\d{1,7}(\.\d{1,2})?$/.test(value)) return null;
        const [whole, part = ''] = value.split('.');
        return Number(whole) * 100 + Number(part.padEnd(2, '0'));
    };
    const currency = (value) => '₱' + (value / 100).toFixed(2);
    function updatePayment() {
        const cash = form.elements.method.value === 'cash';
        form.querySelector('[data-external-fields]').hidden = cash;
        form.elements.reference.required = !cash;
        form.elements.verified.required = !cash;
        form.querySelector('[data-change-row]').hidden = !cash;
        form.querySelector('[data-payment-guidance]').textContent = cash ? 'Count the cash before completing the sale.' : 'Confirm the exact amount with the shop’s provider or terminal before recording it.';
        const received = cents(form.elements.received.value);
        const total = Number(form.dataset.total);
        form.querySelector('[data-change]').textContent = received === null ? '—' : received < total ? 'Short by ' + currency(total - received) : currency(received - total);
        submit.disabled = busy || uncertain || received === null || (cash ? received < total : received !== total || !form.elements.reference.value.trim() || !form.elements.verified.checked);
    }
    function lock(processing) {
        busy = processing;
        if (processing || uncertain) {
            pos.dataset.checkoutPending = 'true';
            dialog.dataset.processing = 'true';
        } else {
            delete pos.dataset.checkoutPending;
            delete dialog.dataset.processing;
        }
        [...form.elements].forEach((control) => {
            if (control.name && !['version', 'request_key', '_token'].includes(control.name)) control.disabled = processing || uncertain;
        });
        dialog.querySelectorAll('[data-dialog-close]').forEach((button) => { button.disabled = processing || uncertain; });
        recovery.querySelectorAll('button').forEach((button) => { button.disabled = processing; });
        updatePayment();
    }
    async function checkStatus() {
        if (!attempt || busy) return;
        lock(true);
        try {
            const response = await fetch(form.dataset.statusUrl.replace('ATTEMPT', encodeURIComponent(attempt.payload.request_key)), { headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error('Status is unavailable. Keep this attempt and retry the status check.');
            const data = await response.json();
            if (data.status === 'completed') { window.location.assign(data.receipt_url); return; }
            message('No completed sale was found for this attempt yet. Retry the same attempt; do not collect payment again.');
        } catch (error) { message(error.message); }
        finally { lock(false); }
    }
    async function send(payload) {
        if (busy) return;
        attempt = { cart_id: form.dataset.cart, payload, status: 'pending' };
        save();
        recovery.hidden = true;
        lock(true);
        let knownFailure = false;
        try {
            const response = await fetch(form.getAttribute('action'), { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }, body: JSON.stringify(payload) });
            const data = await response.json().catch(() => null);
            if (!response.ok) {
                if (data && response.status < 500) {
                    knownFailure = true;
                    uncertain = false;
                    attempt.status = 'rejected'; save();
                    form.querySelector('[data-checkout-review]').hidden = false;
                    message(Object.values(data.errors || {}).flat()[0] || data.message || 'Review this order before retrying.');
                    const quoteResponse = await fetch('/pos/carts/' + form.dataset.cart, { headers: { Accept: 'application/json' } });
                    if (quoteResponse.ok) {
                        const quote = await quoteResponse.json();

                        if (Number(quote.total) !== Number(form.dataset.total) || quote.warnings?.length) {
                            message(errorBox.textContent + ' Reload the cart and review updated prices, quantities or approvals. Your payment details remain saved for this attempt.');
                        }
                    }
                    return;
                }
                throw new Error(data?.message || 'The checkout result is unconfirmed.');
            }
            if (!data?.receipt_url) throw new Error('The checkout result is unconfirmed.');
            window.location.assign(data.receipt_url);
        } catch (error) {
            if (!knownFailure) {
                uncertain = true;
                attempt.status = 'uncertain'; save();
                recovery.hidden = false;
                message(error.message + ' Check this attempt before doing anything else.');
            }
        } finally { lock(false); }
    }
    form.addEventListener('input', (event) => { if (['received','reference'].includes(event.target.name)) form.elements.verified.checked = false; updatePayment(); });
    form.addEventListener('change', (event) => { if (event.target.name === 'method') form.elements.verified.checked = false; updatePayment(); });
    form.addEventListener('submit', (event) => {
        event.preventDefault();
        if (uncertain || busy || !form.reportValidity()) return;
        const payload = Object.fromEntries(new FormData(form));
        payload.verified = form.elements.verified.checked;
        if (attempt?.payload?.request_key) payload.request_key = attempt.payload.request_key;
        send(payload);
    });
    form.querySelector('[data-checkout-status]').addEventListener('click', checkStatus);
    form.querySelector('[data-checkout-retry]').addEventListener('click', () => { if (attempt) send(attempt.payload); });
    dialog.addEventListener('cancel', (event) => { if (busy || uncertain) event.preventDefault(); });
    if (attempt?.payload) {
        if (String(attempt.cart_id) !== form.dataset.cart && attempt.status !== 'rejected') {
            window.location.assign('/pos?cart=' + encodeURIComponent(attempt.cart_id));
        } else if (String(attempt.cart_id) === form.dataset.cart) {
            for (const name of ['method', 'received', 'reference', 'request_key']) if (attempt.payload[name] !== undefined) form.elements[name].value = attempt.payload[name];
            form.elements.verified.checked = attempt.payload.verified === true;
            uncertain = attempt.status !== 'rejected';
            if (uncertain) {
                recovery.hidden = false;
                dialog.showModal();
                message('A previous checkout result is unconfirmed. Check its status or retry the same attempt. Do not collect payment again.');
                lock(false);
                checkStatus();
            }
        }
    }
    updatePayment();
}
