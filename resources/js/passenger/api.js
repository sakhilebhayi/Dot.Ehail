// Same-origin JSON calls for the passenger app: session cookie + CSRF token, a hard timeout, and one error shape
// ({status, code, message, errors}) that the screens show as it is. Never retries a write on its own.

export class ApiError extends Error {
    constructor(status, code, message, errors = null, body = null) {
        super(message);
        this.status = status;
        this.code = code;
        this.errors = errors;
        this.body = body;
    }
}

const OFFLINE = 'You appear to be offline. Check your connection and try again.';

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

/** First validation message, or the server's message, or a plain fallback. */
export function errorMessage(body, status) {
    if (body?.errors && typeof body.errors === 'object') {
        const first = Object.values(body.errors).flat()[0];
        if (first) return String(first);
    }
    if (body?.message) return String(body.message);
    if (status === 429) return 'Too many tries in a short time. Wait a moment and try again.';
    if (status === 419) return 'Your session expired. Reload the page and try again.';
    if (status >= 500) return 'Something went wrong on our side. Try again in a moment.';
    return 'That did not work. Try again.';
}

export async function api(url, { method = 'GET', body, headers = {}, timeoutMs = 15000, form = false } = {}) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), timeoutMs);
    const init = {
        method,
        credentials: 'same-origin',
        cache: 'no-store',
        signal: controller.signal,
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf(), ...headers },
    };

    if (body !== undefined) {
        if (form) {
            init.body = body;
        } else {
            init.headers['Content-Type'] = 'application/json';
            init.body = JSON.stringify(body);
        }
    }

    let response;
    try {
        response = await fetch(url, init);
    } catch (error) {
        throw new ApiError(0, error?.name === 'AbortError' ? 'timeout' : 'network', error?.name === 'AbortError' ? 'The server took too long to answer. Try again.' : OFFLINE);
    } finally {
        clearTimeout(timer);
    }

    const type = response.headers.get('Content-Type') || '';
    const data = type.includes('json') ? await response.json().catch(() => null) : null;

    if (!response.ok) {
        throw new ApiError(response.status, data?.error || data?.reason || `http_${response.status}`, errorMessage(data, response.status), data?.errors || null, data);
    }

    return { data, status: response.status, headers: response.headers };
}

/** A random key for Idempotency-Key: the same tap retried sends the same key. */
export function idempotencyKey(prefix = 'ride') {
    const bytes = new Uint8Array(12);
    crypto.getRandomValues(bytes);
    return `${prefix}-${Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('')}`;
}

/** Reads a JSON island rendered by the server (<script type="application/json" id="...">). */
export function island(id) {
    const el = document.getElementById(id);
    if (!el) return null;
    try {
        return JSON.parse(el.textContent);
    } catch {
        return null;
    }
}

/** Announce a change to screen readers through the page's polite live region. */
export function announce(text, { assertive = false } = {}) {
    const region = document.getElementById(assertive ? 'pax-live-assertive' : 'pax-live');
    if (!region) return;
    region.textContent = '';
    // A fresh text node after a tick makes repeated messages heard again.
    setTimeout(() => {
        region.textContent = text;
    }, 30);
}
