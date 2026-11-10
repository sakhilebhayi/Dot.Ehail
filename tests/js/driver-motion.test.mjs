import { test } from 'node:test';
import assert from 'node:assert/strict';
import { MotionMode, sharedMoving, shareMoving } from '../../resources/js/driver/motion.js';

const at = (s, speed, extra = {}) => ({ lat: -33.92, lng: 18.42, accuracy: 10, speed, recorded_at: s * 1000, ...extra });

test('enters minimal-distraction mode after two fast fixes, not one', () => {
    const m = new MotionMode({ enterKmh: 10, exitKmh: 5, exitAfterS: 10 });
    assert.equal(m.update(at(0, 5)), false, '18 km/h once');
    assert.equal(m.update(at(1, 1)), false, 'a slow fix resets the count');
    assert.equal(m.update(at(2, 5)), false);
    assert.equal(m.update(at(3, 5)), true);
});

test('stays moving at a red light until slow for the whole exit time', () => {
    const m = new MotionMode({ enterKmh: 10, exitKmh: 5, exitAfterS: 10 });
    m.update(at(0, 8));
    m.update(at(1, 8));
    assert.equal(m.update(at(2, 0)), true);
    assert.equal(m.update(at(8, 0)), true, '6 s stopped');
    assert.equal(m.update(at(9, 3)), true, 'creeping forward under 5 km/h still counts as slow');
    assert.equal(m.update(at(12, 0)), false, '10 s slow');
});

test('speeding up again during the exit window keeps the mode on', () => {
    const m = new MotionMode({ exitAfterS: 10 });
    m.update(at(0, 8));
    m.update(at(1, 8));
    m.update(at(2, 0));
    m.update(at(7, 6));
    assert.equal(m.update(at(13, 0)), true, 'the slow clock restarted at 13 s');
});

test('without a reported speed it is derived from accurate positions', () => {
    const m = new MotionMode();
    // ~0.001 degrees of latitude is about 111 m; in 5 s that is about 80 km/h.
    m.update({ lat: -33.92, lng: 18.42, accuracy: 10, speed: null, recorded_at: 0 });
    m.update({ lat: -33.919, lng: 18.42, accuracy: 10, speed: null, recorded_at: 5000 });
    assert.equal(m.update({ lat: -33.918, lng: 18.42, accuracy: 10, speed: null, recorded_at: 10000 }), true);

    const poor = new MotionMode();
    poor.update({ lat: -33.92, lng: 18.42, accuracy: 200, speed: null, recorded_at: 0 });
    poor.update({ lat: -33.919, lng: 18.42, accuracy: 200, speed: null, recorded_at: 5000 });
    assert.equal(poor.update({ lat: -33.918, lng: 18.42, accuracy: 200, speed: null, recorded_at: 10000 }), false, 'GPS jitter is not driving');
});

test('the mode is shared with the other driver pages for 30 seconds', () => {
    const store = new Map();
    const fake = { getItem: (k) => store.get(k) ?? null, setItem: (k, v) => store.set(k, v) };
    shareMoving(true, 1000, fake);
    assert.equal(sharedMoving(20000, fake), true);
    assert.equal(sharedMoving(40000, fake), false, 'stale');
    assert.equal(sharedMoving(1000, { getItem: () => { throw new Error('blocked'); } }), false);
});
