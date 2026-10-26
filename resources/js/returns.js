const returnsInitialized = new WeakSet();
function initializeReturns(root = document) {
    root.querySelectorAll('[data-return-request-form]').forEach((form) => {
        if (returnsInitialized.has(form)) return;
        returnsInitialized.add(form);
        const update = () => {
            let amount = 0, units = 0, valid = true;
            form.querySelectorAll('[data-return-quantity]').forEach((input) => {
                const quantity = Number(input.value);
                if (!Number.isInteger(quantity) || quantity < 0 || quantity > Number(input.max)) { valid = false; return; }
                const sold = Number(input.dataset.sold), price = Number(input.dataset.price), discount = Number(input.dataset.discount), accepted = Number(input.dataset.accepted);
                const base = Math.floor(discount / sold), extra = discount % sold;
                amount += quantity * (price - base) - (Math.min(accepted + quantity, extra) - Math.min(accepted, extra));
                units += quantity;
            });
            form.querySelector('[data-return-units]').textContent = String(units);
            form.querySelector('[data-return-estimate]').textContent = valid && units > 0 ? '₱' + (amount / 100).toFixed(2) : '—';
            form.querySelector('[data-return-submit]').disabled = !valid || units === 0;
        };
        form.addEventListener('input', update);
        update();
    });
    root.querySelectorAll('[data-refund-form]').forEach((form) => {
        if (returnsInitialized.has(form)) return;
        returnsInitialized.add(form);
        const update = () => {
            const paid = form.elements.status.value === 'paid';
            form.querySelector('[data-payout-confirmation]').hidden = !paid;
            form.elements.confirmed.required = paid;
            if (!paid) form.elements.confirmed.checked = false;
        };
        form.elements.status.addEventListener('change', update);
        update();
    });
}
initializeReturns();
document.addEventListener('moto:content-ready', (event) => initializeReturns(event.detail.root));
