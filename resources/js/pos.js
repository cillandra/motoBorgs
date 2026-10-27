const initializedPos = new WeakSet();
function initializePos(root = document) {
const pos = root.querySelector('[data-pos]');
if (pos && initializedPos.has(pos)) return;
if (pos) initializedPos.add(pos);
if (pos?.dataset.openHeld === '1') { pos.querySelector('#held-carts')?.showModal(); const url = new URL(window.location.href); url.searchParams.delete('held'); history.replaceState(null, '', url); }
if (pos) {
    let pending = false;
    const showError = (message, form) => {
        const element = form?.closest('dialog')?.querySelector('[data-form-error]') || pos.querySelector('[data-pos-error]');
        element.textContent = message;
        element.hidden = false;
        element.scrollIntoView({ block: 'nearest' });
    };
    async function write(url, payload, form) {
        if (pending || pos.dataset.checkoutPending) return;
        pending = true;
        const controls = [...(form || pos).querySelectorAll('button')];
        controls.forEach((button) => { button.dataset.previousDisabled = String(button.disabled); button.disabled = true; });
        const dialog = form?.closest('dialog');
        if (dialog) dialog.dataset.processing = 'true';
        try {
            const response = await fetch(url, { method: 'POST', headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }, body: JSON.stringify(payload) });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(Object.values(data.errors || {}).flat()[0] || data.message || 'The request could not be completed. Reload and try again.');
            const next = new URL(data.next || window.location.href, window.location.href);
            if (!data.next) next.searchParams.set('cart', data.cart_id);
            if (window.motoModal) await window.motoModal.refresh(next.href, true);
            else window.location.assign(next.href);
        } catch (error) {
            showError(error.message || 'Connection lost. Reload to check the saved cart.', form);
            pending = false;
            controls.forEach((button) => { button.disabled = button.dataset.previousDisabled === 'true'; });
            if (dialog) delete dialog.dataset.processing;
        }
    }
    pos.querySelectorAll('[data-pos-form]').forEach((form) => {
        const error = document.createElement('div');
        error.className = 'inline-alert error'; error.dataset.formError = ''; error.hidden = true; error.setAttribute('role', 'alert');
        form.prepend(error);
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            const values = Object.fromEntries(new FormData(form));
            if (values.manager_password) form.querySelector('[name="manager_password"]').value = '';
            write(form.getAttribute('action'), values, form);
        });
    });
    pos.querySelectorAll('[data-quantity-step]').forEach((button) => button.addEventListener('click', () => {
        const form = button.closest('form');
        const input = form.elements.quantity;
        input.value = String(Math.max(1, Math.min(Number(input.max), Number(input.value) + Number(button.dataset.quantityStep))));
        form.requestSubmit();
    }));
    pos.querySelector('[data-barcode-form]')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (pending || pos.dataset.checkoutPending) return;
        try {
            const code = event.target.querySelector('input').value.trim();
            const response = await fetch(pos.dataset.lookup + '?code=' + encodeURIComponent(code), { headers: { Accept: 'application/json' } });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(response.status === 404 ? 'No active variant matches this exact barcode or SKU.' : data.message || 'Lookup failed.');
            await write(pos.dataset.mutate, { version: Number(pos.dataset.version), action: 'add', variant_id: data.variant_id, quantity: 1 }, event.target);
        } catch (error) { showError(error.message, event.target); }
    });
    pos.querySelectorAll('dialog').forEach((dialog) => {
        dialog.addEventListener('cancel', (event) => { if (dialog.dataset.processing) event.preventDefault(); });
        dialog.addEventListener('close', () => dialog.querySelectorAll('[name="manager_password"]').forEach((input) => { input.value = ''; }));
    });
}

}
initializePos();
document.addEventListener('moto:content-ready', (event) => initializePos(event.detail.root));
