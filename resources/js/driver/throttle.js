// Location reporting while online (driver app). The phone produces fixes as fast as it likes; this decides which to
// keep and when to post, within geo's ingest limits (one post per 2 s, 1 to 20 samples, accuracy worse than 150 m
// is discarded there). Battery and data aware: a slower interval when the phone asks to save data or is low.
// Pure: time is passed in. Unit-tested in tests/js/driver-throttle.test.mjs.

export const MAX_ACCURACY_M = 150;

export class LocationThrottler {
    constructor({ everyS = 4, saverEveryS = 8, maxBatch = 20, maxQueue = 120 } = {}) {
        this.everyMs = everyS * 1000;
        this.saverEveryMs = saverEveryS * 1000;
        this.maxBatch = maxBatch;
        this.maxQueue = maxQueue;
        this.saver = false;
        this.queue = [];
        this.lastPostAt = -Infinity;
        this.lastKeptAt = -Infinity;
        this.inFlight = false;
    }

    setSaver(on) {
        this.saver = Boolean(on);
    }

    interval() {
        return this.saver ? this.saverEveryMs : this.everyMs;
    }

    /** Keep a fix if it is accurate enough and not within a second of the last one kept. Returns whether kept. */
    push(fix) {
        if (!fix || !Number.isFinite(fix.lat) || !Number.isFinite(fix.lng)) return false;
        // Geo needs the fix's accuracy and discards anything worse than 150 m: no point sending those.
        if (!Number.isFinite(fix.accuracy) || fix.accuracy > MAX_ACCURACY_M) return false;
        if (fix.recorded_at - this.lastKeptAt < 1000) return false;

        this.lastKeptAt = fix.recorded_at;
        this.queue.push(fix);
        // A long dead zone: keep the newest fixes only (geo ignores fixes older than 10 minutes anyway).
        if (this.queue.length > this.maxQueue) this.queue.splice(0, this.queue.length - this.maxQueue);

        return true;
    }

    /** Is a post due now? */
    due(nowMs) {
        return !this.inFlight && this.queue.length > 0 && nowMs - this.lastPostAt >= this.interval();
    }

    /**
     * Take the next batch to send. Normally only the newest fix (the driver's position); after a gap (a dead zone,
     * a failed post) the queued fixes go together, oldest first, so the trip trail stays whole.
     */
    take(nowMs) {
        if (!this.due(nowMs)) return [];
        this.inFlight = true;
        this.lastPostAt = nowMs;

        return this.queue.slice(0, this.maxBatch);
    }

    /** The post succeeded: drop what was sent. */
    sent(count) {
        this.queue.splice(0, count);
        this.inFlight = false;
    }

    /** The post failed: keep the fixes for the next attempt. Rate-limited: wait the server's Retry-After. */
    failed(nowMs, retryAfterS = 0) {
        this.inFlight = false;
        if (retryAfterS > 0) this.lastPostAt = nowMs + retryAfterS * 1000 - this.interval();
    }

    pending() {
        return this.queue.length;
    }
}

/** Should reporting slow down? Save-Data header preference, or a battery under 20 % and not charging. */
export function shouldSave({ saveData = false, battery = null } = {}) {
    if (saveData) return true;
    if (battery && battery.charging === false && Number.isFinite(battery.level) && battery.level < 0.2) return true;

    return false;
}
