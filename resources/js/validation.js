export function clearValidation(form) {
    form.querySelectorAll('[data-validation-description]').forEach((field) => {
        const description = field.dataset.validationDescription;
        const ids = (field.getAttribute('aria-describedby') || '').split(/\s+/).filter((id) => id && id !== description);
        if (ids.length) field.setAttribute('aria-describedby', ids.join(' '));
        else field.removeAttribute('aria-describedby');
        field.removeAttribute('aria-invalid');
        delete field.dataset.validationDescription;
    });
    form.querySelectorAll('[data-validation-message]').forEach((message) => message.remove());
}

export function showValidation(form, errors, prefix = 'moto-validation') {
    clearValidation(form);
    let first = null;
    Object.entries(errors || {}).forEach(([key, messages], index) => {
        const parts = key.split('.');
        const name = parts.shift() + parts.map((part) => '[' + part + ']').join('');
        const fields = [...form.elements].filter((field) => field.name === name && field.type !== 'hidden' && field.tagName !== 'BUTTON');
        if (!fields.length) return;
        const message = document.createElement('p');
        message.id = `${prefix}-${index}`;
        message.className = 'field-error';
        message.dataset.validationMessage = '';
        message.textContent = (Array.isArray(messages) ? messages : [messages]).join(' ');
        const field = fields[0];
        (field.closest('.field, .setting-toggle-row, .setting-value') || field.parentElement).append(message);
        fields.forEach((control) => {
            control.setAttribute('aria-invalid', 'true');
            control.setAttribute('aria-describedby', [control.getAttribute('aria-describedby'), message.id].filter(Boolean).join(' '));
            control.dataset.validationDescription = message.id;
        });
        first ||= field;
    });
    return first;
}

export function revealField(field) {
    if (!field?.isConnected) return;
    let details = field.closest('details');
    while (details) {
        details.open = true;
        details = details.parentElement.closest('details');
    }
    field.focus();
}
