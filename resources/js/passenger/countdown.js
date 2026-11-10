// Time arithmetic for the sheets: quote expiry, the search window, the free waiting time at pickup and the free
// cancellation window. Pure functions of (deadline, now) so they are tested without timers.

/** Whole seconds from now until iso (never negative), or null without a deadline. */
export function secondsUntil(iso, now = Date.now()) {
    if (!iso) return null;
    const at = Date.parse(iso);
    if (Number.isNaN(at)) return null;
    return Math.max(0, Math.ceil((at - now) / 1000));
}

export function secondsSince(iso, now = Date.now()) {
    if (!iso) return null;
    const at = Date.parse(iso);
    if (Number.isNaN(at)) return null;
    return Math.max(0, Math.floor((now - at) / 1000));
}

/** "4:05" for a countdown, "0:00" at the end. */
export function clock(seconds) {
    if (seconds === null || seconds === undefined) return '';
    const s = Math.max(0, Math.floor(seconds));
    const h = Math.floor(s / 3600);
    const m = Math.floor((s % 3600) / 60);
    const rest = String(s % 60).padStart(2, '0');
    return h > 0 ? `${h}:${String(m).padStart(2, '0')}:${rest}` : `${m}:${rest}`;
}

/** Quote state for the compare cards: fresh, ending (last 60 s) or expired. */
export function quoteState(expiresAt, now = Date.now()) {
    const left = secondsUntil(expiresAt, now);
    if (left === null) return { state: 'none', left: null };
    if (left === 0) return { state: 'expired', left: 0 };
    return { state: left <= 60 ? 'ending' : 'fresh', left };
}

/**
 * Waiting at pickup: the driver arrived at arrivedAt; waiting is free for freeMinutes, then perMinuteMinor per
 * started minute (the rule set's terms). Returns {waited, freeLeft, charging, chargedMinutes}.
 */
export function waitState(arrivedAt, freeMinutes, now = Date.now()) {
    const waited = secondsSince(arrivedAt, now);
    if (waited === null) return null;
    const free = Math.max(0, (freeMinutes || 0) * 60);
    const over = Math.max(0, waited - free);
    return { waited, freeLeft: Math.max(0, free - waited), charging: over > 0, chargedMinutes: over > 0 ? Math.ceil(over / 60) : 0 };
}

/** Search progress 0..1 between started and the window's end. */
export function searchProgress(startedAt, endsAt, now = Date.now()) {
    const start = Date.parse(startedAt);
    const end = Date.parse(endsAt);
    if (Number.isNaN(start) || Number.isNaN(end) || end <= start) return null;
    return Math.min(1, Math.max(0, (now - start) / (end - start)));
}

/** The free cancellation window after a driver is assigned: seconds left, or 0 once it has passed. */
export function freeCancelLeft(assignedAt, freeMinutes, now = Date.now()) {
    if (!assignedAt || !Number.isFinite(freeMinutes)) return null;
    const since = secondsSince(assignedAt, now);
    return since === null ? null : Math.max(0, freeMinutes * 60 - since);
}
