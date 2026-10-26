document.addEventListener('click', (event) => {
    const sidebar = event.target.closest('[data-sidebar-toggle]');
    if (sidebar) {
        const expanded = document.documentElement.dataset.sidebar !== 'expanded';
        document.documentElement.dataset.sidebar = expanded ? 'expanded' : 'collapsed';
        document.querySelectorAll('[data-sidebar-toggle]').forEach((button) => {
            button.setAttribute('aria-expanded', String(expanded));
            button.setAttribute('aria-label', expanded ? 'Collapse sidebar' : 'Expand sidebar');
        });
    }
    if (event.target.closest('[data-theme-toggle]')) document.documentElement.dataset.theme = document.documentElement.dataset.theme === 'light' ? 'dark' : 'light';
    const toggle = event.target.closest('[data-password-toggle]');
    if (toggle) {
        const input = toggle.closest('.input-wrap')?.querySelector('input') || document.getElementById(toggle.dataset.passwordToggle);
        const visible = input.type === 'password';
        input.type = visible ? 'text' : 'password';
        toggle.setAttribute('aria-label', visible ? 'Hide password' : 'Show password');
        toggle.setAttribute('aria-pressed', String(visible));
    }
});
document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!form.matches('[data-submit-form]') || !form.checkValidity()) return;
    const button = form.querySelector('[type=submit]');
    if (button) { button.disabled = true; button.textContent = 'Signing in…'; }
});
