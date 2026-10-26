document.addEventListener('click', (event) => {
    const close = event.target.closest('[data-dialog-close]');
    if (close) {
        const dialog = close.closest('dialog');
        if (!dialog.dataset.processing) {
            if (dialog.matches('[data-workspace-modal]')) window.motoModal?.close();
            else dialog.close();
        }
    }
    const opener = event.target.closest('[data-dialog-open]');
    if (opener) {
        const scope = opener.closest('[data-workspace-content]') || document;
        const target = scope.querySelector('#' + CSS.escape(opener.dataset.dialogOpen)) || document.getElementById(opener.dataset.dialogOpen);
        target?.showModal();
    }
    const dismiss = event.target.closest('[data-dismiss-toast]');
    if (dismiss) dismiss.closest('.toast').remove();
});
document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') document.querySelectorAll('details[open]').forEach((item) => item.removeAttribute('open'));
});
document.addEventListener('click', (event) => {
    document.querySelectorAll('details.profile-menu[open]').forEach((item) => { if (!item.contains(event.target)) item.removeAttribute('open'); });
});
