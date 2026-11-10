// Minimal-distraction mode (driver app): while the car moves faster than about 10 km/h the app hides everything
// but the next action and safety, and holds typing-heavy screens. A UI rule, not a security control: the server
// never trusts it. Hysteresis stops it flickering at traffic lights. Pure; tested in tests/js/driver-motion.test.mjs.

import { haversine } from './geometry.js';

export class MotionMode {
    constructor({ enterKmh = 10, exitKmh = 5, exitAfterS = 10, enterFixes = 2 } = {}) {
        this.enterMs = enterKmh / 3.6;
        this.exitMs = exitKmh / 3.6;
        this.exitAfterMs = exitAfterS * 1000;
        this.enterFixes = enterFixes;
        this.moving = false;
        this.fastCount = 0;
        this.slowSince = null;
        this.last = null;
    }

    /**
     * Feed one fix {lat, lng, speed (m/s or null), accuracy, recorded_at (ms)}. Returns the mode after it.
     * Without a reported speed, speed is derived from the distance to the previous fix when both are accurate.
     */
    update(fix) {
        let speed = Number.isFinite(fix.speed) && fix.speed !== null ? fix.speed : null;

        if (speed === null && this.last) {
            const dt = (fix.recorded_at - this.last.recorded_at) / 1000;
            const accurate = (fix.accuracy ?? 0) <= 50 && (this.last.accuracy ?? 0) <= 50;
            if (dt >= 1 && dt <= 30 && accurate) speed = haversine(this.last, fix) / dt;
        }
        this.last = fix;

        if (speed === null) return this.moving;

        if (!this.moving) {
            this.fastCount = speed >= this.enterMs ? this.fastCount + 1 : 0;
            if (this.fastCount >= this.enterFixes) {
                this.moving = true;
                this.slowSince = null;
            }

            return this.moving;
        }

        if (speed < this.exitMs) {
            this.slowSince ??= fix.recorded_at;
            if (fix.recorded_at - this.slowSince >= this.exitAfterMs) {
                this.moving = false;
                this.fastCount = 0;
                this.slowSince = null;
            }
        } else {
            this.slowSince = null;
        }

        return this.moving;
    }
}

// The console shares the mode with the driver's other pages for a short while (sessionStorage), so a page opened
// while driving starts in the right mode. Stale after 30 s.
const KEY = 'eh-drive-moving';

export function shareMoving(moving, now = Date.now(), store = globalThis.sessionStorage) {
    try {
        store?.setItem(KEY, JSON.stringify({ moving: Boolean(moving), at: now }));
    } catch {
        /* storage unavailable: each page decides on its own */
    }
}

export function sharedMoving(now = Date.now(), store = globalThis.sessionStorage) {
    try {
        const v = JSON.parse(store?.getItem(KEY) ?? 'null');

        return Boolean(v && v.moving && now - v.at < 30000);
    } catch {
        return false;
    }
}
