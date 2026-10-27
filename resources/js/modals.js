const modal = document.querySelector('[data-workspace-modal]');
if (modal) {
    const content = modal.querySelector('[data-workspace-content]');
    const title = modal.querySelector('#moto-workspace-title');
    const description = modal.querySelector('#moto-workspace-description');
    const discard = document.getElementById('moto-discard-modal');
    let dirty = false;
    let busy = false;
    let trigger = null;
    let currentUrl = null;
    const contextualPath = /^(?:\/inventory\/\d+(?:\/stock)?|\/products\/(?:create|\d+\/edit)|\/sales\/\d+(?:\/receipt|\/returns)?|\/returns\/\d+|\/employees\/(?:create|\d+(?:\/edit)?)|\/activity\/\d+)\/?$/;
    const announce = (root) => document.dispatchEvent(new CustomEvent('moto:content-ready', { detail: { root } }));
    const setBusy = (value) => {
        busy = value;
        modal.setAttribute('aria-busy', String(value));
        modal.querySelector('[data-workspace-close]').disabled = value;
        if (value) modal.dataset.processing = 'true';
        else delete modal.dataset.processing;
    };
    async function mayDiscard() {
        if (!dirty) return true;
        discard.returnValue = '';
        return new Promise((resolve) => {
            discard.addEventListener('close', () => resolve(discard.returnValue === 'discard'), { once: true });
            discard.showModal();
        });
    }
    function closeNow() {
        modal.close();
        content.replaceChildren();
        dirty = false;
        currentUrl = null;
        if (trigger?.isConnected) trigger.focus();
        else document.querySelector('#main-content h1')?.focus({ preventScroll: true });
    }
    async function close() {
        if (busy || !(await mayDiscard())) return;
        closeNow();
    }
    function failure(message, url = null) {
        document.dispatchEvent(new CustomEvent('moto:feedback',{detail:{tone:'error'}}));
        const alert = document.createElement('div');
        alert.className = 'inline-alert error';
        alert.setAttribute('role', 'alert');
        const text = document.createElement('p');
        text.textContent = message;
        alert.append(text);
        if (url) {
            const retry = document.createElement('button');
            retry.type = 'button'; retry.className = 'button button-secondary'; retry.textContent = 'Try opening again';
            retry.addEventListener('click', () => open(url, { force: true }));
            alert.append(retry);
        }
        return alert;
    }
    async function readPage(url) {
        const destination = new URL(url, window.location.href);
        if (destination.origin !== window.location.origin) throw new Error('This action must stay inside MOTO.');
        const response = await fetch(destination, { headers: { Accept: 'text/html', 'X-Moto-Modal': '1' } });
        if (!response.ok) throw new Error(response.status === 403 ? 'You do not have permission to open this action.' : 'This window could not be loaded. Your current page is unchanged.');
        const documentCopy = new DOMParser().parseFromString(await response.text(), 'text/html');
        const main = documentCopy.querySelector('#main-content');
        if (!main) throw new Error('Your session may have expired. Sign in again to continue.');
        main.querySelectorAll('script,meta,link').forEach((node) => node.remove());
        return main;
    }
    async function refresh(url = window.location.href, updateUrl = false) {
        const scrollPosition = window.scrollY;
        try {
            const main = await readPage(url);
            const target = document.querySelector('#main-content');
            target.replaceChildren(...main.childNodes);
            if (updateUrl) window.history.replaceState({}, '', url);
            announce(target);
            window.scrollTo({ top: scrollPosition, behavior: 'instant' });
            return true;
        } catch (error) {
            document.querySelector('#main-content').prepend(failure(error.message));
            return false;
        }
    }
    async function open(url, options = {}) {
        if (busy || (!options.force && !(await mayDiscard()))) return false;
        const resetPos = Boolean(document.querySelector('#main-content [data-pos]')) && /\/sales\/\d+\/receipt/.test(new URL(url, window.location.href).pathname);
        if (!modal.open) {
            trigger = document.activeElement;
            modal.showModal();
        }
        currentUrl = url;
        dirty = false;
        title.textContent = 'Loading…';
        description.textContent = '';
        content.textContent = 'Loading this window…';
        modal.dataset.kind = /\/receipt/.test(url) ? 'receipt' : /\/stock/.test(url) ? 'stock' : 'details';
        setBusy(true);
        try {
            const main = await readPage(url);
            const heading = main.querySelector('h1');
            title.textContent = heading?.textContent.trim() || 'Details';
            description.textContent = heading?.parentElement.querySelector('p')?.textContent.trim() || '';
            content.replaceChildren(...main.childNodes);
            announce(content);
            content.scrollTop = 0;

            if (resetPos) await refresh('/pos', true);
            return true;
        } catch (error) {
            title.textContent = 'Unable to open this window';
            content.replaceChildren(failure(error.message, url));
            return false;
        } finally { setBusy(false); if (modal.open) modal.querySelector('[data-workspace-close]').focus(); }
    }
    function toast(message) {
        document.dispatchEvent(new CustomEvent('moto:feedback',{detail:{tone:'success'}}));
        const fragment = document.getElementById('moto-saved-toast').content.cloneNode(true);
        fragment.querySelector('[data-saved-message]').textContent = message;
        document.body.append(fragment);
    }
    async function save(form) {
        if (busy) return;
        let invalidField = null;
        form.querySelectorAll('[aria-invalid]').forEach((control) => { control.removeAttribute('aria-invalid'); if (control.getAttribute('aria-describedby') === 'moto-modal-validation-summary') control.removeAttribute('aria-describedby'); });
        const payload = new FormData(form);
        form.querySelectorAll('input[type=password]').forEach((input) => { input.value = ''; });
        const buttons = [...modal.querySelectorAll('button')];
        const previous = buttons.map((button) => button.disabled);
        buttons.forEach((button) => { button.disabled = true; });
        setBusy(true);
        const fields = [...form.elements].filter((control) => control.tagName !== 'BUTTON');
        const disabledFields = fields.map((control) => control.disabled);
        fields.forEach((control) => { control.disabled = true; });
        content.querySelectorAll('[data-modal-validation]').forEach((node) => node.remove());
        try {
            const response = await fetch(form.getAttribute('action') || currentUrl, { method: form.getAttribute('method')?.toUpperCase() || 'POST',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }, body: payload });
            const isJson = response.headers.get('content-type')?.includes('application/json');
            const data = isJson ? await response.json() : null;
            if (!response.ok) {
                for (const field of Object.keys(data?.errors || {})) {
                    const parts = field.split('.'); const name = parts.shift() + parts.map((part) => '[' + part + ']').join('');
                    const control = [...form.elements].find((element) => element.name === name);
                    if (control) { control.setAttribute('aria-invalid', 'true'); control.setAttribute('aria-describedby', 'moto-modal-validation-summary'); invalidField ||= control; }
                }
                const errors = Object.values(data?.errors || {}).flat();
                throw new Error(errors.join(' ') || data?.message || 'The result could not be confirmed. Keep this form and check the saved record before retrying.');
            }
            if (!isJson) {
                const returned = new DOMParser().parseFromString(await response.text(), 'text/html');
                if (!returned.querySelector('#main-content')) throw new Error('Your session may have expired. Sign in again and check whether the change was saved.');
            }
            dirty = false;
            form.dataset.saved = 'true';
            setBusy(false);
            if (data?.redirect) {
                const next=new URL(data.redirect,window.location.href);
                if(next.origin!==window.location.origin || next.pathname!=='/login')throw new Error('The sign-in destination is invalid.');
                window.location.assign(next.href);return;
            }
            if (data?.modal_html) {
                const returned=new DOMParser().parseFromString(data.modal_html,'text/html');
                const main=returned.querySelector('#main-content');
                if(!main)throw new Error('The private result could not be displayed. Check the employee account before issuing another code.');
                main.querySelectorAll('script,meta,link').forEach(node=>node.remove());
                title.textContent=main.querySelector('h1')?.textContent.trim()||'Result';
                description.textContent=main.querySelector('h1')?.parentElement.querySelector('p')?.textContent.trim()||'';
                content.replaceChildren(...main.childNodes);announce(content);content.scrollTop=0;
            } else if (data?.modal_url) {
                await open(data.modal_url, { force: true });
            } else {
                closeNow();
            }
            await refresh();
            toast(data?.message || 'Changes saved.');
        } catch (error) {
            dirty = true;
            const alert = failure(error.message);
            alert.dataset.modalValidation = '';
            alert.id = 'moto-modal-validation-summary';
            form.prepend(alert);
            alert.scrollIntoView({ block: 'nearest' });
            if (invalidField) { let details = invalidField.closest('details'); while (details) { details.open = true; details = details.parentElement.closest('details'); } invalidField.focus(); }
        } finally {
            setBusy(false);
            buttons.forEach((button, index) => { if (button.isConnected) button.disabled = previous[index]; });
            fields.forEach((control, index) => { if (control.isConnected) control.disabled = disabledFields[index]; });
            if (invalidField?.isConnected) invalidField.focus();
        }
    }
    document.addEventListener('click', (event) => {
        const link = event.target.closest('a[href]');
        if (!link || event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || link.target === '_blank' || link.hasAttribute('download')) return;
        const destination = new URL(link.href, window.location.href);
        if (destination.origin !== window.location.origin) return;
        const inside = modal.contains(link);
        if (link.hasAttribute('data-modal-link') || (inside && contextualPath.test(destination.pathname))) {
            event.preventDefault();
            if (!busy) open(destination.href);
        } else if (inside && ['/inventory', '/pos', '/sales', '/employees', '/returns', '/activity', '/reports'].includes(destination.pathname)) {
            event.preventDefault();
            if (busy) return;
            (async () => {
                if (!(await mayDiscard())) return;
                closeNow();
                if (destination.pathname === window.location.pathname || destination.pathname === '/pos') await refresh(destination.href, true);
                else window.location.assign(destination.href);
            })();
        }
    });
    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!modal.contains(form) || form.getAttribute('method') === 'dialog') return;
        event.preventDefault();
        if (busy) return;
        if ((form.getAttribute('method') || 'get').toLowerCase() === 'get') {
            const url = new URL(form.getAttribute('action') || currentUrl, window.location.href);
            url.search = new URLSearchParams(new FormData(form));
            open(url.href);
        } else save(form);
    }, true);
    modal.addEventListener('moto:dirty', () => { dirty = true; });
    modal.addEventListener('input', (event) => { if (event.target.closest('dialog') === modal) dirty = true; });
    modal.addEventListener('change', (event) => { if (event.target.closest('dialog') === modal) dirty = true; });
    modal.querySelector('[data-workspace-close]').addEventListener('click', close);
    modal.addEventListener('cancel', (event) => { event.preventDefault(); close(); });
    window.motoModal = { open, close, refresh, openReceipt: (url) => open(url, { force: true }) };
}
