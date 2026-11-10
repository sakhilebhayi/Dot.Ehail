// Action forms: <form data-ops-action action="(a domain JSON API)" method="post" [data-ops-method="PATCH"]
// [data-ops-confirm="question"] [data-ops-done="toast text"] [data-ops-redirect="url"] [data-ops-redirect-template, -key]>. Sends the form with the CSRF
// token and Accept: application/json, shows the domain's own refusal under the form, and reloads on success so
// every figure on the page is read again from the server (nothing is updated optimistically).
import { errorMessage, randToMinor } from './helpers.js';

const TOAST_KEY = 'ops-toast';

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

function showError(form, message) {
    let slot = form.querySelector('[data-ops-error]');
    if (!slot) {
        slot = document.createElement('p');
        slot.className = 'ops-act__error';
        slot.setAttribute('role', 'alert');
        slot.dataset.opsError = '';
        form.appendChild(slot);
    }
    slot.textContent = message;
    slot.hidden = false;
}

async function submit(form, submitter) {
    const question = form.dataset.opsConfirm;
    if (question && !window.confirm(question)) return;

    const data = new FormData(form, submitter || undefined);
    // Rand inputs become integer cents for the API (data-ops-minor names the field the API reads).
    for (const input of form.querySelectorAll('[data-ops-minor]')) {
        data.delete(input.name);
        if (input.value.trim() === '') continue;
        const minor = randToMinor(input.value);
        if (minor === null) {
            showError(form, `Enter an amount in rand, like 125.50, in "${input.labels?.[0]?.childNodes?.[0]?.textContent?.trim() || input.name}".`);
            input.focus();
            return;
        }
        data.set(input.dataset.opsMinor, minor);
    }
    const method = (form.dataset.opsMethod || 'POST').toUpperCase();
    if (method !== 'POST') data.set('_method', method);
    // Empty optional fields are left out so a domain's "nullable" rules see nothing rather than "".
    for (const [key, value] of [...data.entries()]) {
        if (value === '' && form.querySelector(`[name="${CSS.escape(key)}"]`)?.dataset.opsKeepEmpty === undefined) data.delete(key);
    }

    const buttons = form.querySelectorAll('button[type="submit"], button:not([type])');
    form.setAttribute('aria-busy', 'true');
    buttons.forEach((b) => { b.disabled = true; });
    form.querySelector('[data-ops-error]')?.setAttribute('hidden', '');

    let response;
    let body = null;
    try {
        response = await fetch(form.action, {
            method: 'POST',
            body: data,
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' },
        });
        body = await response.json().catch(() => null);
    } catch {
        showError(form, 'No connection. Nothing was changed. Try again when you are back online.');
        form.removeAttribute('aria-busy');
        buttons.forEach((b) => { b.disabled = false; });
        return;
    }

    if (response.ok) {
        try { sessionStorage.setItem(TOAST_KEY, form.dataset.opsDone || 'Done.'); } catch { /* private mode */ }
        // data-ops-redirect-template="/ops/tariffs/{id}" + data-ops-redirect-key="rule_set.id": go to what was created.
        const key = form.dataset.opsRedirectKey;
        const created = key ? key.split('.').reduce((value, part) => (value == null ? value : value[part]), body) : null;
        if (form.dataset.opsRedirectTemplate && created != null) window.location.assign(form.dataset.opsRedirectTemplate.replace('{id}', encodeURIComponent(String(created))));
        else if (form.dataset.opsRedirect) window.location.assign(form.dataset.opsRedirect);
        else window.location.reload();
        return;
    }

    showError(form, errorMessage(response.status, body));
    form.removeAttribute('aria-busy');
    buttons.forEach((b) => { b.disabled = false; });
}

export function initActions(root = document) {
    try {
        const pending = sessionStorage.getItem(TOAST_KEY);
        if (pending) {
            sessionStorage.removeItem(TOAST_KEY);
            window.ehToast?.(pending, { tone: 'go' });
        }
    } catch { /* private mode */ }

    root.addEventListener('submit', (event) => {
        const form = event.target.closest?.('form[data-ops-action]');
        if (!form) return;
        event.preventDefault();
        submit(form, event.submitter);
    });

    // Plain (non-JSON) forms that need a confirmation step.
    root.addEventListener('submit', (event) => {
        const form = event.target.closest?.('form[data-ops-confirm]:not([data-ops-action])');
        if (form && !window.confirm(form.dataset.opsConfirm)) event.preventDefault();
    }, true);
}
