// Pure helpers for the operations centre (tested in tests/js/ops.test.mjs): durations, timer tones, error text.

/** "4 min", "1 h 05 min", "2 d 3 h" from seconds (absolute value); "0 min" under a minute is "under 1 min". */
export function formatDuration(seconds) {
    const s = Math.abs(Math.round(Number(seconds) || 0));
    if (s < 60) return 'under 1 min';
    const minutes = Math.floor(s / 60);
    if (minutes < 60) return `${minutes} min`;
    const hours = Math.floor(minutes / 60);
    if (hours < 48) return `${hours} h ${String(minutes % 60).padStart(2, '0')} min`;
    return `${Math.floor(hours / 24)} d ${hours % 24} h`;
}

/**
 * A due time read as words: "due in 12 min" / "overdue by 3 min"; tone stop when overdue, caution inside the
 * warning window, calm otherwise. elapsed mode ("waiting 4 min") counts up from a start time.
 */
export function describeTimer({ dueAt = null, since = null, now = Date.now(), warnSeconds = 300, label = '' } = {}) {
    if (dueAt !== null) {
        const remaining = Math.round((Date.parse(dueAt) - now) / 1000);
        if (remaining < 0) return { text: `${label ? label + ' ' : ''}overdue by ${formatDuration(remaining)}`, tone: 'stop' };
        return { text: `${label ? label + ' ' : ''}due in ${formatDuration(remaining)}`, tone: remaining <= warnSeconds ? 'caution' : 'calm' };
    }
    if (since !== null) {
        const elapsed = Math.round((now - Date.parse(since)) / 1000);
        return { text: `${label ? label + ' ' : ''}${formatDuration(elapsed)}`, tone: warnSeconds && elapsed >= warnSeconds ? 'caution' : 'calm' };
    }
    return { text: '', tone: 'calm' };
}

/** The one sentence an operator reads when a domain API refuses: validation, role, conflict, or anything else. */
export function errorMessage(status, body) {
    if (status === 403) return 'Your operations role does not include this action.';
    if (status === 419) return 'Your session expired. Reload the page and try again.';
    if (status === 429) return 'Too many attempts. Wait a moment and try again.';
    if (body && typeof body === 'object') {
        if (body.errors && typeof body.errors === 'object') {
            const first = Object.values(body.errors).flat()[0];
            if (first) return String(first);
        }
        if (body.message) return String(body.message);
        if (body.error) return String(body.error).replaceAll('_', ' ');
    }
    if (status >= 500) return 'The server could not do this right now. Nothing was changed. Try again.';
    return 'That did not work. Nothing was changed.';
}

/**
 * "125.50" / "125,5" / "1 250" (rand) -> "12550" (integer cents as a string), without floating point.
 * Returns null for anything that is not a non-negative amount with at most two decimals.
 */
export function randToMinor(input) {
    const text = String(input ?? '').replace(/[\s  ]/g, '').replace(/^R/i, '');
    const match = /^(\d+)(?:[.,](\d{1,2}))?$/.exec(text);
    if (!match) return null;
    const cents = (match[2] || '').padEnd(2, '0');
    const joined = (match[1] + cents).replace(/^0+(?=\d)/, '');
    return joined;
}
