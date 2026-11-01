const inventoryInitialized = new WeakSet();
function initializeInventory(root = document) {
    root.querySelectorAll('[data-product-form]').forEach((form) => {
        if (inventoryInitialized.has(form)) return;
        inventoryInitialized.add(form);
        let index = Math.max(0, ...[...form.querySelectorAll('[name^="variants["]')].map((input) => Number(input.name.match(/^variants\[(\d+)\]/)?.[1] || 0))) + 1;
        const extra = form.querySelector('#additional-variants');
        const markDirty = () => { form.dataset.unsaved = 'true'; form.dispatchEvent(new Event('moto:dirty', { bubbles: true })); };
        form.querySelector('[data-add-variant]')?.addEventListener('click', () => {
            const holder = document.createElement('div');
            holder.innerHTML = form.querySelector('#variant-template').innerHTML.replaceAll('__INDEX__', String(index++));
            extra.append(...holder.children);
            markDirty();
            extra.lastElementChild?.querySelector('input:not([type=hidden])')?.focus();
        });
        form.addEventListener('click', (event) => {
            const button = event.target.closest('[data-remove-variant]');
            if (button) { button.closest('.variant-fields').remove(); markDirty(); }
        });
        const image = form.querySelector('[data-image-input]');
        const drop = form.querySelector('[data-image-drop]');
        const preview = () => {
            const file = image.files[0];
            if (!file) return;
            const feedback = form.querySelector('#image-feedback');
            if (file.size > Number(form.dataset.imageLimit || 5) * 1024 * 1024 || !['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
                feedback.textContent = 'Choose a JPG, PNG or WebP image up to ' + (form.dataset.imageLimit || 5) + ' MB.';
                image.value = '';
                return;
            }
            const previewUrl = URL.createObjectURL(file);
            const element = form.querySelector('#image-preview');
            element.onload = () => URL.revokeObjectURL(previewUrl);
            element.src = previewUrl;
            element.className = 'selected-image';
            feedback.textContent = file.name;
            markDirty();
        };
        form.querySelector('[data-image-choose]')?.addEventListener('click', () => image.click());
        image?.addEventListener('change', preview);
        drop?.addEventListener('dragover', (event) => event.preventDefault());
        drop?.addEventListener('drop', (event) => { event.preventDefault(); image.files = event.dataTransfer.files; preview(); });
        form.addEventListener('input', markDirty);
        form.addEventListener('change', markDirty);
        form.addEventListener('submit', () => { if (!form.closest('[data-workspace-modal]')) form.dataset.unsaved = 'false'; });
        window.addEventListener('beforeunload', (event) => {
            if (form.isConnected && form.dataset.unsaved === 'true' && form.dataset.saved !== 'true') { event.preventDefault(); event.returnValue = ''; }
        });
    });
    root.querySelectorAll('[data-stock-form]').forEach((form) => {
        if (inventoryInitialized.has(form)) return;
        inventoryInitialized.add(form);
        const quantity = form.querySelector('#quantity');
        const mode = form.querySelector('#adjustment_mode');
        const preview = () => {
            if (quantity.value === '') { form.querySelector('[data-stock-preview]').textContent = 'Enter a quantity to preview the resulting balance.'; return; }
            const value = Number(quantity.value);
            const balance = Number(form.dataset.balance);
            const result = !mode || mode.value === 'increase' ? balance + value : mode.value === 'decrease' ? balance - value : value;
            form.querySelector('[data-stock-preview]').textContent = Number.isInteger(value) && result >= 0 && result <= 1000000 ? 'New balance after saving: ' + result : 'Enter a valid whole quantity. Stock cannot be negative.';
        };
        quantity.addEventListener('input', preview);
        mode?.addEventListener('change', preview);
        preview();
    });
}
initializeInventory();
document.addEventListener('moto:content-ready', (event) => initializeInventory(event.detail.root));
