import { test } from 'node:test';
import assert from 'node:assert/strict';
import { glideAt, MAX_GLIDE_METRES, planGlide } from '../../resources/js/geo/follow.js';

const a = { lat: -26.1, lng: 28.05, heading: 90 };
const b = { lat: -26.1, lng: 28.051, heading: 100 };

test('a normal update glides over about one interval', () => {
    const glide = planGlide(a, b, { intervalMs: 4000 });
    assert.equal(glide.instant, false);
    assert.equal(glide.durationMs, 4000);

    const half = glideAt(glide, 2000);
    assert.ok(Math.abs(half.lng - 28.0505) < 1e-9);
    assert.equal(half.done, false);
    assert.equal(half.heading, 100, 'heading settles in the first half');

    assert.equal(glideAt(glide, 4000).done, true);
    assert.equal(glideAt(glide, 9000).lng, 28.051);
});

test('reduced motion places the marker directly', () => {
    const glide = planGlide(a, b, { reducedMotion: true });
    assert.equal(glide.instant, true);
    assert.deepEqual(glideAt(glide, 0), { lat: b.lat, lng: b.lng, heading: 100, done: true });
});

test('the first fix and long jumps are placed, not animated across the map', () => {
    assert.equal(planGlide(null, b).instant, true);
    const far = { lat: -26.0, lng: 28.2 };
    assert.ok(MAX_GLIDE_METRES < 20000);
    assert.equal(planGlide(a, far).instant, true);
});

test('glide duration is clamped for very fast or very slow update rates', () => {
    assert.equal(planGlide(a, b, { intervalMs: 50 }).durationMs, 300);
    assert.equal(planGlide(a, b, { intervalMs: 60000 }).durationMs, 5000);
});

test('a target without a heading keeps the drawn heading', () => {
    const glide = planGlide(a, { lat: b.lat, lng: b.lng });
    assert.equal(glideAt(glide, 4000).heading, 90);
});
