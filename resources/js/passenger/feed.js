// Polling feed client for GET /api/ehail/rides/{id}/feed (docs/R46_DISPATCH.md section 8): sends the version it
// holds as If-None-Match, merges the delta it gets back, waits as long as the server says (Retry-After or
// poll_after_s), backs off on failures, pauses while the tab is hidden and says when it is offline.
// The pure parts (mergeFeed, nextDelay, reduce) are tested in node; FeedClient wires them to fetch and timers.

export const MAX_BACKOFF_S = 60;

/** Merge a delta into the snapshot: sections present in the body replace the ones held. */
export function mergeFeed(snapshot, body) {
    if (!body) return snapshot;
    const next = { ...(snapshot || {}) };
    for (const key of ['version', 'server_time', 'poll_after_s', 'ride', 'driver_position', 'connection']) {
        if (Object.prototype.hasOwnProperty.call(body, key)) next[key] = body[key];
    }
    // A ride that no longer holds a driver has no driver position.
    const holds = ['driver_assigned', 'driver_arriving', 'driver_arrived', 'in_progress'];
    if (next.ride && !holds.includes(next.ride.status)) {
        next.driver_position = null;
        next.connection = null;
    }
    return next;
}

/**
 * Seconds to wait before the next poll.
 *  ok/not_modified: the server's hint (Retry-After header, else poll_after_s, else 3);
 *  rate_limited: Retry-After or 10;
 *  error: exponential backoff from 2 s, capped at MAX_BACKOFF_S.
 */
export function nextDelay({ outcome, retryAfter = null, pollAfter = null, failures = 0 }) {
    const hint = Number.isFinite(retryAfter) && retryAfter > 0 ? retryAfter : Number.isFinite(pollAfter) && pollAfter > 0 ? pollAfter : null;
    switch (outcome) {
        case 'ok':
        case 'not_modified':
            return hint ?? 3;
        case 'rate_limited':
            return hint ?? 10;
        default:
            return Math.min(MAX_BACKOFF_S, 2 ** Math.max(1, failures));
    }
}

/**
 * The client's state machine. state: idle | live | paused | offline | stopped. Events:
 *  start, hidden, visible, ok, not_modified, rate_limited, error, went_offline, came_online, gone (403/404), stop.
 * Returns the next {state, failures}.
 */
export function reduce(current, event) {
    const { state, failures } = current;
    if (state === 'stopped') return current;
    switch (event) {
        case 'start':
            return { state: 'live', failures: 0 };
        case 'hidden':
            return { state: 'paused', failures };
        case 'visible':
            return { state: state === 'offline' ? 'offline' : 'live', failures };
        case 'ok':
        case 'not_modified':
            return { state: state === 'paused' ? 'paused' : 'live', failures: 0 };
        case 'rate_limited':
            return { state, failures };
        case 'error':
            // Two failures in a row is when people should be told: one blip is not news.
            return { state: failures + 1 >= 2 ? (state === 'paused' ? 'paused' : 'offline') : state, failures: failures + 1 };
        case 'went_offline':
            return { state: 'offline', failures };
        case 'came_online':
            return { state: 'live', failures: 0 };
        case 'gone':
        case 'stop':
            return { state: 'stopped', failures };
        default:
            return current;
    }
}

export class FeedClient {
    constructor({ url, initial = null, onUpdate = () => {}, onState = () => {}, fetchImpl, timers, doc, nav } = {}) {
        this.url = url;
        this.snapshot = initial;
        this.onUpdate = onUpdate;
        this.onState = onState;
        this.fetch = fetchImpl || ((...args) => globalThis.fetch(...args));
        this.timers = timers || { set: (fn, ms) => setTimeout(fn, ms), clear: (id) => clearTimeout(id) };
        this.doc = doc || (typeof document !== 'undefined' ? document : null);
        this.nav = nav || (typeof navigator !== 'undefined' ? navigator : null);
        this.machine = { state: 'idle', failures: 0 };
        this.timer = null;
        this.inflight = null;
        this.lastOkAt = null;
        this.onVisibility = () => this.dispatch(this.doc.hidden ? 'hidden' : 'visible', !this.doc.hidden);
        this.onOnline = () => this.dispatch('came_online', true);
        this.onOffline = () => this.dispatch('went_offline');
    }

    get state() {
        return this.machine.state;
    }

    start() {
        this.doc?.addEventListener?.('visibilitychange', this.onVisibility);
        globalThis.addEventListener?.('online', this.onOnline);
        globalThis.addEventListener?.('offline', this.onOffline);
        this.dispatch('start');
        if (this.doc?.hidden) this.dispatch('hidden');
        else this.poll();
    }

    stop() {
        this.dispatch('stop');
        this.timers.clear(this.timer);
        this.inflight?.abort?.();
        this.doc?.removeEventListener?.('visibilitychange', this.onVisibility);
        globalThis.removeEventListener?.('online', this.onOnline);
        globalThis.removeEventListener?.('offline', this.onOffline);
    }

    /** Poll now (after an action the passenger took, e.g. cancel). */
    refresh() {
        if (this.machine.state === 'stopped') return Promise.resolve();
        this.timers.clear(this.timer);
        return this.poll();
    }

    dispatch(event, pollNow = false) {
        const before = this.machine.state;
        this.machine = reduce(this.machine, event);
        if (this.machine.state !== before) this.onState(this.machine.state, { failures: this.machine.failures, lastOkAt: this.lastOkAt });
        if (pollNow && this.machine.state === 'live') {
            this.timers.clear(this.timer);
            this.poll();
        }
    }

    schedule(seconds) {
        this.timers.clear(this.timer);
        if (this.machine.state === 'stopped' || this.machine.state === 'paused') return;
        this.timer = this.timers.set(() => this.poll(), seconds * 1000);
    }

    async poll() {
        if (this.machine.state === 'stopped' || this.machine.state === 'paused') return;
        const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
        if (this.snapshot?.version) headers['If-None-Match'] = `"v${this.snapshot.version}"`;
        const controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
        this.inflight = controller;
        let outcome = 'error';
        let retryAfter = null;

        try {
            const response = await this.fetch(this.url, { headers, credentials: 'same-origin', cache: 'no-store', signal: controller?.signal });
            retryAfter = Number(response.headers?.get?.('Retry-After')) || null;

            if (response.status === 304) {
                outcome = 'not_modified';
            } else if (response.ok) {
                const body = await response.json();
                this.snapshot = mergeFeed(this.snapshot, body);
                outcome = 'ok';
                this.lastOkAt = Date.now();
                this.onUpdate(this.snapshot, body);
            } else if (response.status === 429) {
                outcome = 'rate_limited';
            } else if (response.status === 403 || response.status === 404 || response.status === 401 || response.status === 419) {
                this.dispatch('gone');
                return;
            }
        } catch (error) {
            if (error?.name === 'AbortError' && this.machine.state === 'stopped') return;
            outcome = 'error';
        } finally {
            this.inflight = null;
        }

        if (outcome === 'not_modified') this.lastOkAt = Date.now();
        this.dispatch(outcome);
        this.schedule(nextDelay({ outcome, retryAfter, pollAfter: this.snapshot?.poll_after_s, failures: this.machine.failures }));
    }
}
