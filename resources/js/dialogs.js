document.addEventListener('click', (event) => {
    const close = event.target.closest('[data-dialog-close]');
    if (close) close.closest('dialog').close();
    const opener = event.target.closest('[data-dialog-open]');
    if (opener) document.getElementById(opener.dataset.dialogOpen)?.showModal();
    const dismiss = event.target.closest('[data-dismiss-toast]');
    if (dismiss) dismiss.closest('.toast').remove();
});
document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') document.querySelectorAll('details[open]').forEach((item) => item.removeAttribute('open'));
});
document.addEventListener('click', (event) => {
    document.querySelectorAll('details.profile-menu[open]').forEach((item) => { if (!item.contains(event.target)) item.removeAttribute('open'); });
});
