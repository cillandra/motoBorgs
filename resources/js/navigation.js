const sidebarBreakpoint = window.matchMedia('(max-width: 800px)');
const compactBreakpoint = window.matchMedia('(max-width: 1100px)');
let sidebarTrigger = null;
function preferredSidebar() {
    if (compactBreakpoint.matches) return false;
    try { return localStorage.getItem('moto-sidebar') !== 'collapsed'; } catch { return true; }
}
function setSidebar(expanded, { remember = false, restoreFocus = false } = {}) {
    const root = document.documentElement;
    root.dataset.sidebar = expanded ? 'expanded' : 'collapsed';
    const drawerOpen = expanded && sidebarBreakpoint.matches;
    document.querySelectorAll('[data-sidebar-toggle]').forEach((button) => {
        button.setAttribute('aria-expanded', String(expanded));
        button.setAttribute('aria-label', expanded ? 'Collapse sidebar' : 'Expand sidebar');
        const label = button.querySelector('[data-sidebar-toggle-label]');
        if (label) label.textContent = sidebarBreakpoint.matches ? 'Close navigation' : 'Collapse sidebar';
    });
    const backdrop = document.querySelector('[data-sidebar-dismiss]');
    if (backdrop) backdrop.hidden = !drawerOpen;
    const workspace = document.querySelector('[data-main-workspace]');
    if (workspace) workspace.inert = drawerOpen;
    if (remember && !compactBreakpoint.matches) {
        try { localStorage.setItem('moto-sidebar', expanded ? 'expanded' : 'collapsed'); } catch {}
    }
    if (restoreFocus && sidebarTrigger?.isConnected) sidebarTrigger.focus();
}
setSidebar(preferredSidebar());
function resetSidebarForViewport() {
    const focusWasInside = document.activeElement?.closest('#main-sidebar');
    setSidebar(preferredSidebar());
    if (sidebarBreakpoint.matches && focusWasInside) document.querySelector('.topbar [data-sidebar-toggle]')?.focus();
}
compactBreakpoint.addEventListener('change', resetSidebarForViewport);
sidebarBreakpoint.addEventListener('change', resetSidebarForViewport);
document.addEventListener('keydown', (event) => {
    if (!sidebarBreakpoint.matches || document.documentElement.dataset.sidebar !== 'expanded') return;
    if (event.key === 'Escape') {
        event.preventDefault();
        setSidebar(false, { restoreFocus: true });
    } else if (event.key === 'Tab') {
        const controls = [...document.querySelectorAll('#main-sidebar a[href], #main-sidebar button:not(:disabled)')].filter((element) => element.getClientRects().length);
        const first = controls[0], last = controls.at(-1);
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
    }
});
document.addEventListener('click', (event) => {
    const sidebar = event.target.closest('[data-sidebar-toggle]');
    if (sidebar) {
        const expanded = document.documentElement.dataset.sidebar !== 'expanded';
        if (expanded) sidebarTrigger = sidebar;
        setSidebar(expanded, { remember: true, restoreFocus: !expanded && sidebarBreakpoint.matches });
        if (expanded && sidebarBreakpoint.matches) (document.querySelector('#main-sidebar a[aria-current="page"]') || document.querySelector('#main-sidebar a'))?.focus();
    }
    if (event.target.closest('[data-sidebar-dismiss]')) setSidebar(false, { restoreFocus: true });
    if (event.target.closest('#main-sidebar a[href]') && sidebarBreakpoint.matches) setSidebar(false);
    if (event.target.closest('[data-theme-toggle]')) document.dispatchEvent(new CustomEvent('moto:toggle-theme'));
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
    if (button) { button.disabled = true; button.textContent = form.dataset.submitLabel || 'Signing in…'; }
});
function initializeNavigation(root = document) {
    root.querySelectorAll('.tabs .tab.active, .view-switch a.active').forEach((link) => link.setAttribute('aria-current', 'page'));
    if (root !== document) root.querySelectorAll('.tabs .tab:not(.active), .view-switch a:not(.active)').forEach((link) => link.removeAttribute('aria-current'));
    root.querySelectorAll('.table-scroll').forEach((region) => {
        region.tabIndex = 0;
        region.setAttribute('role', 'region');
        region.setAttribute('aria-label', region.getAttribute('aria-label') || 'Table · scroll horizontally for more columns');
    });
}
initializeNavigation();
document.addEventListener('moto:content-ready', (event) => initializeNavigation(event.detail.root));
