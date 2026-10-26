document.querySelectorAll('[data-auto-filter]').forEach((select) => select.addEventListener('change', () => select.form.requestSubmit()));
const form = document.querySelector('[data-product-form]');
if (form) {
    let index = form.querySelectorAll('.variant-fields').length + 1;
    const extra = document.getElementById('additional-variants');
    form.querySelector('[data-add-variant]')?.addEventListener('click', () => {
        const template = document.getElementById('variant-template');
        const holder = document.createElement('div');
        holder.innerHTML = template.innerHTML.replaceAll('__INDEX__', String(index++));
        extra.append(...holder.children);
        extra.lastElementChild?.querySelector('input:not([type=hidden])')?.focus();
    });
    form.addEventListener('click', (event) => {
        const button = event.target.closest('[data-remove-variant]');
        if (button) button.closest('.variant-fields').remove();
    });
    const image = form.querySelector('[data-image-input]');
    const drop = form.querySelector('[data-image-drop]');
    let previewUrl = null;
    const preview = () => {
        const file = image.files[0];
        if (!file) return;
        const feedback = document.getElementById('image-feedback');
        if (file.size > 5 * 1024 * 1024 || !['image/jpeg','image/png','image/webp'].includes(file.type)) {
            feedback.textContent = 'Choose a JPG, PNG or WebP image up to 5 MB.';
            image.value = '';
            return;
        }
        if (previewUrl) URL.revokeObjectURL(previewUrl);
        previewUrl = URL.createObjectURL(file);
        const element = document.getElementById('image-preview');
        element.src = previewUrl;
        element.className = 'selected-image';
        feedback.textContent = file.name;
    };
    image?.addEventListener('change', preview);
    drop?.addEventListener('dragover', (event) => event.preventDefault());
    drop?.addEventListener('drop', (event) => { event.preventDefault(); image.files = event.dataTransfer.files; preview(); });
    let dirty = false;
    form.addEventListener('input', () => { dirty = true; });
    form.addEventListener('change', () => { dirty = true; });
    form.addEventListener('submit', () => { dirty = false; });
    window.addEventListener('beforeunload', (event) => { if (dirty) { event.preventDefault(); event.returnValue = ''; } });
}
document.querySelector("[data-image-choose]")?.addEventListener("click", () => document.querySelector("[data-image-input]").click());
