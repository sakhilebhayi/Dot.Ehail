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
        this.backlog = false;
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

    /**
     * For the combined sync request (POST /api/ehail/driver/sync, docs/R46_PERF.md): which fixes ride along with this
     * poll. Moving: the newest fix every poll. Parked: one every parkedEveryS (enough to stay matchable; a parked
     * phone repeating the same spot costs two database writes per post for nothing). After a failed sync or a dead
     * zone (backlog): the queued fixes, oldest first, so the trip trail stays whole. Call sent()/failed() after.
     */
    takeForSync(nowMs, { moving = false, parkedEveryS = 15 } = {}) {
        if (this.inFlight || this.queue.length === 0) return [];
        const backlog = this.backlog === true;
        if (!moving && !backlog && nowMs - this.lastPostAt < parkedEveryS * 1000) return [];

        this.inFlight = true;
        this.lastPostAt = nowMs;
        if (backlog) return this.queue.slice(0, this.maxBatch);

        // Only the newest matters for the marker and matching; older unsent fixes from this interval are dropped.
        this.queue.splice(0, this.queue.length - 1);

        return this.queue.slice(0, 1);
    }

    /** The sync with fixes failed: keep them and send the backlog with the next sync. */
    syncFailed() {
        this.inFlight = false;
        this.backlog = true;
    }

    /** The sync succeeded: drop what was sent. */
    syncSent(count) {
        this.sent(count);
        // More than one fix still waiting (a long backlog, capped per post): keep draining.
        this.backlog = this.backlog && this.queue.length > 1;
    }
}

/** Should reporting slow down? Save-Data header preference, or a battery under 20 % and not charging. */
export function shouldSave({ saveData = false, battery = null } = {}) {
    if (saveData) return true;
    if (battery && battery.charging === false && Number.isFinite(battery.level) && battery.level < 0.2) return true;

    return false;
}
