// Offer countdown (driver app). The server says when the offer expires and what time it is on the server; the
// phone's clock may be wrong, so the countdown runs on the server's clock: offset = server time - phone time,
// measured when the feed arrived. Pure functions, unit-tested (tests/js/driver-countdown.test.mjs).

/** Milliseconds to add to the phone's clock to get the server's clock. */
export function clockOffset(serverIso, receivedAtMs) {
    const server = Date.parse(serverIso);

    return Number.isFinite(server) ? server - receivedAtMs : 0;
}

/** Whole seconds left (never negative), rounding up so "1" shows until the last moment. */
export function secondsLeft(expiresIso, nowMs, offsetMs = 0) {
    const expires = Date.parse(expiresIso);
    if (!Number.isFinite(expires)) return 0;

    return Math.max(0, Math.ceil((expires - (nowMs + offsetMs)) / 1000));
}

/** Share of the offer window left, 0..1, for the burning line. */
export function fractionLeft(sentIso, expiresIso, nowMs, offsetMs = 0) {
    const sent = Date.parse(sentIso);
    const expires = Date.parse(expiresIso);
    if (!Number.isFinite(sent) || !Number.isFinite(expires) || expires <= sent) return 0;

    const left = (expires - (nowMs + offsetMs)) / (expires - sent);

    return Math.min(1, Math.max(0, left));
}

/**
 * A countdown that calls onTick(seconds, fraction) about four times a second and onExpire() once. The caller owns
 * the timer functions so tests can drive time.
 */
export function startCountdown({ sentAt, expiresAt, offsetMs = 0, onTick, onExpire, now = () => Date.now(), setTimer = setInterval, clearTimer = clearInterval }) {
    let done = false;

    const tick = () => {
        if (done) return;
        const t = now();
        const seconds = secondsLeft(expiresAt, t, offsetMs);
        onTick?.(seconds, fractionLeft(sentAt ?? expiresAt, expiresAt, t, offsetMs));

        if (seconds <= 0) {
            done = true;
            clearTimer(id);
            onExpire?.();
        }
    };

    const id = setTimer(tick, 250);
    tick();

    return () => {
        done = true;
        clearTimer(id);
    };
}
