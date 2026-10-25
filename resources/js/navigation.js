for (const button of document.querySelectorAll('[data-sidebar-toggle]')) {
    button.addEventListener('click', () => {
        const expanded = document.documentElement.dataset.sidebar !== 'expanded';
        document.documentElement.dataset.sidebar = expanded ? 'expanded' : 'collapsed';
        for (const toggle of document.querySelectorAll('[data-sidebar-toggle]')) {
            toggle.setAttribute('aria-expanded', String(expanded));
            toggle.setAttribute('aria-label', expanded ? 'Collapse sidebar' : 'Expand sidebar');
        }
    });
}
for (const button of document.querySelectorAll('[data-theme-toggle]')) {
    button.addEventListener('click', () => {
        document.documentElement.dataset.theme = document.documentElement.dataset.theme === 'light' ? 'dark' : 'light';
    });
}
for (const button of document.querySelectorAll('[data-password-toggle]')) {
    button.addEventListener('click', () => {
        const input = document.getElementById(button.dataset.passwordToggle);
        const visible = input.type === 'password';
        input.type = visible ? 'text' : 'password';
        button.setAttribute('aria-label', visible ? 'Hide password' : 'Show password');
        button.setAttribute('aria-pressed', String(visible));
    });
}
for (const form of document.querySelectorAll('[data-submit-form]')) {
    form.addEventListener('submit', () => {
        if (!form.checkValidity()) return;
        const button = form.querySelector('[type=submit]');
        if (button) { button.disabled = true; button.textContent = 'Signing in…'; }
    });
}
