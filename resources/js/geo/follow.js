// Smooth driver movement between position updates. The marker glides linearly from where it is drawn now to
// the new fix over roughly one update interval, so a car posting every 4 s moves continuously instead of
// jumping. With reduced motion (or a jump too big to be driving) it is placed directly.

import { haversine, interpolate, interpolateHeading } from './geometry.js';

export const MAX_GLIDE_METRES = 1500;

/**
 * Pure planner: given the drawn position and the new target, returns the glide to run.
 * {from, to, fromHeading, toHeading, durationMs, instant}
 */
export function planGlide(drawn, target, { intervalMs = 4000, reducedMotion = false } = {}) {
    const toHeading = Number.isFinite(target.heading) ? target.heading : drawn?.heading ?? 0;

    if (!drawn || reducedMotion || haversine(drawn, target) > MAX_GLIDE_METRES) {
        return { from: target, to: target, fromHeading: toHeading, toHeading, durationMs: 0, instant: true };
    }

    return {
        from: { lat: drawn.lat, lng: drawn.lng },
        to: { lat: target.lat, lng: target.lng },
        fromHeading: Number.isFinite(drawn.heading) ? drawn.heading : toHeading,
        toHeading,
        durationMs: Math.max(300, Math.min(intervalMs, 5000)),
        instant: false,
    };
}

/** Position and heading of a glide at elapsed milliseconds. */
export function glideAt(glide, elapsedMs) {
    if (glide.instant || glide.durationMs === 0) {
        return { ...glide.to, heading: glide.toHeading, done: true };
    }

    const t = Math.min(1, elapsedMs / glide.durationMs);

    return {
        ...interpolate(glide.from, glide.to, t),
        heading: interpolateHeading(glide.fromHeading, glide.toHeading, Math.min(1, t * 2)),
        done: t >= 1,
    };
}

/**
 * Runs glides with requestAnimationFrame. draw(position) is called every frame; a new target interrupts the
 * current glide from wherever the marker is drawn.
 */
export class Follower {
    constructor(draw, { reducedMotion = false } = {}) {
        this.draw = draw;
        this.reducedMotion = reducedMotion;
        this.drawn = null;
        this.frame = null;
        this.lastTargetAt = null;
    }

    moveTo(target) {
        const now = performance.now();
        const intervalMs = this.lastTargetAt ? now - this.lastTargetAt : 4000;
        this.lastTargetAt = now;

        const glide = planGlide(this.drawn, target, { intervalMs, reducedMotion: this.reducedMotion });
        cancelAnimationFrame(this.frame);

        if (glide.instant) {
            this.drawn = { ...glide.to, heading: glide.toHeading };
            this.draw(this.drawn, true);

            return;
        }

        const started = now;
        const step = (time) => {
            const position = glideAt(glide, time - started);
            this.drawn = position;
            this.draw(position, position.done);

            if (!position.done) {
                this.frame = requestAnimationFrame(step);
            }
        };

        this.frame = requestAnimationFrame(step);
    }

    stop() {
        cancelAnimationFrame(this.frame);
    }
}
