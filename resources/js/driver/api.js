// JSON requests for the driver app: same-origin session cookie, CSRF token, JSON errors {error, message}.

/** What to tell the driver when the server sent no words of its own: what happened and what to do (R-46 a11y). */
export function fallbackMessage(status) {
    if (status === 401 || status === 419) return 'You are signed out. Sign in again to keep driving.';
    if (status === 403) return 'Your account cannot do this. Ask support if you think it should.';
    if (status === 404) return 'This is no longer available. The screen now shows what is current.';
    if (status === 429) return 'Too many tries in a short time. Wait a moment and try again.';
    if (status >= 500) return 'Something went wrong on our side. Try again in a moment.';
    return 'That did not work. Try again.';
}

export class ApiError extends Error {
    constructor(status, data, headers) {
        super(data?.message || fallbackMessage(status));
        this.status = status;
        this.data = data ?? {};
        this.code = data?.error ?? null;
        this.retryAfter = Number(headers?.get?.('Retry-After') ?? 0) || 0;
    }
}

function token() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

export async function api(url, { method = 'GET', body, headers = {}, signal } = {}) {
    const init = {
        method,
        credentials: 'same-origin',
        signal,
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...headers },
    };

    if (method !== 'GET') {
        init.headers['X-CSRF-TOKEN'] = token();
        if (body !== undefined) {
            init.headers['Content-Type'] = 'application/json';
            init.body = JSON.stringify(body);
        }
    }

    const res = await fetch(url, init);
    if (res.status === 304) return { status: 304, data: null, headers: res.headers };

    let data = null;
    try {
        data = await res.json();
    } catch {
        data = null;
    }

    if (!res.ok) throw new ApiError(res.status, data, res.headers);

    return { status: res.status, data, headers: res.headers };
}

export function fill(template, id, extra = {}) {
    let url = template.replace('__ID__', encodeURIComponent(id));
    for (const [k, v] of Object.entries(extra)) url = url.replace(`__${k.toUpperCase()}__`, encodeURIComponent(v));

    return url;
}
