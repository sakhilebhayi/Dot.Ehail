// Times as people in the service area read them (D-1906): every user-facing time is shown in the service area's
// timezone (South African Standard Time by default), never the device's or UTC, so a list and a detail page agree.
// The server renders the same zone (App\Support\Ui\LocalTime). Pure apart from reading the page's eh-tz meta tag.

export const DEFAULT_TZ = 'Africa/Johannesburg';

export function displayTimezone() {
    if (typeof document === 'undefined') return DEFAULT_TZ;
    // The server names the zone it rendered in: <meta name="eh-tz"> in the shared head (or <html data-eh-tz>).
    const fromPage = document.documentElement?.dataset?.ehTz || document.querySelector?.('meta[name="eh-tz"]')?.content;

    return fromPage || DEFAULT_TZ;
}

/** Formats an ISO instant (or Date) in the display timezone with Intl options; '' for nothing. */
export function formatLocal(value, options = {}, tz = displayTimezone()) {
    if (value === null || value === undefined || value === '') return '';
    const date = value instanceof Date ? value : new Date(value);
    if (Number.isNaN(date.getTime())) return '';

    return new Intl.DateTimeFormat('en-ZA', { hourCycle: 'h23', ...options, timeZone: tz }).format(date);
}

/** "07:30" */
export function timeLocal(value, { seconds = false } = {}, tz = displayTimezone()) {
    return formatLocal(value, { hour: '2-digit', minute: '2-digit', ...(seconds ? { second: '2-digit' } : {}) }, tz);
}

/** The zone's offset from UTC in minutes at an instant (Johannesburg: +120 all year). */
export function offsetMinutes(date, tz = displayTimezone()) {
    const parts = Object.fromEntries(
        new Intl.DateTimeFormat('en-US', { timeZone: tz, hourCycle: 'h23', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit' })
            .formatToParts(date)
            .filter((p) => p.type !== 'literal')
            .map((p) => [p.type, Number(p.value)]),
    );
    const asUtc = Date.UTC(parts.year, parts.month - 1, parts.day, parts.hour % 24, parts.minute, parts.second);

    return Math.round((asUtc - Math.floor(date.getTime() / 1000) * 1000) / 60000);
}

/**
 * A wall-clock time picked in a form ("2026-11-11T07:30", as <input type="datetime-local"> gives it) read as a time
 * in the display timezone, returned as an ISO instant. The device's own zone plays no part.
 */
export function wallTimeToIso(local, tz = displayTimezone()) {
    const m = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::(\d{2}))?$/.exec(String(local ?? ''));
    if (!m) return null;
    const guess = Date.UTC(+m[1], +m[2] - 1, +m[3], +m[4], +m[5], +(m[6] || 0));
    const offset = offsetMinutes(new Date(guess), tz);

    return new Date(guess - offset * 60000).toISOString();
}

/**
 * The inverse: an instant as the wall-clock time people in the display timezone read, "2026-11-11T07:30". Parsed
 * with new Date() it gives a Date whose own hours and days are that wall clock, which is what the day-and-time
 * picker works in.
 */
export function wallTime(value = new Date(), tz = displayTimezone()) {
    const date = value instanceof Date ? value : new Date(value);
    if (Number.isNaN(date.getTime())) return '';
    const p = Object.fromEntries(
        new Intl.DateTimeFormat('en-US', { timeZone: tz, hourCycle: 'h23', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' })
            .formatToParts(date)
            .map((x) => [x.type, x.value]),
    );

    return `${p.year}-${p.month}-${p.day}T${String(Number(p.hour) % 24).padStart(2, '0')}:${p.minute}`;
}
