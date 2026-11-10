// Small fetch layer for the geo proxy: same-origin JSON, a hard timeout, one retry on "busy" (429 + Retry-After),
// and honest error objects ({reason, message}) the widgets show as they are.

const DEFAULT_TIMEOUT_MS = 8000;

export class GeoRequestError extends Error {
    constructor(reason, message, status = 0) {
        super(message);
        this.reason = reason;
        this.status = status;
    }
}

export async function getJson(url, params = {}, { signal, timeoutMs = DEFAULT_TIMEOUT_MS, retryBusy = true } = {}) {
    const query = new URLSearchParams();

    for (const [key, value] of Object.entries(params)) {
        if (value !== null && value !== undefined && value !== '') {
            query.set(key, String(value));
        }
    }

    const target = query.size > 0 ? `${url}?${query}` : url;

    if (typeof navigator !== 'undefined' && navigator.onLine === false) {
        throw new GeoRequestError('offline', 'You are offline. Search and routes come back when the connection does.');
    }

    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(new DOMException('timeout', 'TimeoutError')), timeoutMs);
    const abortFromCaller = () => controller.abort(signal.reason);
    signal?.addEventListener('abort', abortFromCaller, { once: true });

    let response;

    try {
        response = await fetch(target, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            signal: controller.signal,
        });
    } catch (error) {
        if (signal?.aborted) {
            throw new GeoRequestError('aborted', 'Cancelled.');
        }

        if (controller.signal.aborted) {
            throw new GeoRequestError('timeout', 'The connection is slow and the request timed out. Try again.');
        }

        throw new GeoRequestError('network', 'Could not reach Dot.Ehail. Check your connection and try again.');
    } finally {
        clearTimeout(timer);
        signal?.removeEventListener('abort', abortFromCaller);
    }

    let body = null;

    try {
        body = await response.json();
    } catch {
        body = null;
    }

    if (response.status === 429 && retryBusy) {
        const wait = Math.min(3, Number(response.headers.get('Retry-After')) || 1) * 1000 + 100;
        await new Promise((resolve) => setTimeout(resolve, wait));

        return getJson(url, params, { signal, timeoutMs, retryBusy: false });
    }

    if (!response.ok) {
        const message = body?.message || (response.status === 429 ? 'Too many requests. Wait a moment and try again.' : 'Something went wrong. Try again.');

        throw new GeoRequestError(body?.reason || `http_${response.status}`, message, response.status);
    }

    return body;
}
