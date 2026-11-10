import { test } from 'node:test';
import assert from 'node:assert/strict';
import { LocationThrottler, shouldSave } from '../../resources/js/driver/throttle.js';

const fix = (t, extra = {}) => ({ lat: -33.92, lng: 18.42, accuracy: 10, recorded_at: t, ...extra });

test('posts at most once per interval, newest fixes in order', () => {
    const t = new LocationThrottler({ everyS: 4 });
    assert.equal(t.push(fix(1000)), true);
    assert.deepEqual(t.take(1000).map((f) => f.recorded_at), [1000]);
    t.sent(1);
    t.push(fix(3000));
    assert.equal(t.due(3000), false, 'within 4 s of the last post');
    assert.deepEqual(t.take(3000), []);
    assert.equal(t.due(5000), true);
    assert.deepEqual(t.take(5000).map((f) => f.recorded_at), [3000]);
    assert.equal(t.due(9000), false, 'nothing new while a post is in flight');
});

test('drops inaccurate, accuracy-less and too-frequent fixes', () => {
    const t = new LocationThrottler();
    assert.equal(t.push(fix(1000, { accuracy: 400 })), false, 'geo discards worse than 150 m');
    assert.equal(t.push(fix(1000, { accuracy: null })), false, 'geo needs the accuracy');
    assert.equal(t.push(fix(1000)), true);
    assert.equal(t.push(fix(1500)), false, 'one fix per second is plenty');
    assert.equal(t.push({ lat: NaN, lng: 1, accuracy: 5, recorded_at: 3000 }), false);
    assert.equal(t.pending(), 1);
});

test('a dead zone queues fixes and sends them together, capped per post', () => {
    const t = new LocationThrottler({ everyS: 4, maxBatch: 20 });
    t.push(fix(0));
    t.take(0);
    t.failed(0);
    for (let i = 1; i <= 30; i++) t.push(fix(i * 2000));
    const batch = t.take(60000);
    assert.equal(batch.length, 20);
    assert.equal(batch[0].recorded_at, 0, 'oldest first so the trail stays whole');
    t.sent(batch.length);
    assert.equal(t.pending(), 11);
});

test('a rate-limited post waits for Retry-After', () => {
    const t = new LocationThrottler({ everyS: 4 });
    t.push(fix(0));
    t.take(0);
    t.failed(0, 10);
    assert.equal(t.due(5000), false);
    assert.equal(t.due(10000), true);
});

test('battery and data saving slow reporting down', () => {
    const t = new LocationThrottler({ everyS: 4, saverEveryS: 8 });
    t.setSaver(true);
    assert.equal(t.interval(), 8000);
    assert.equal(shouldSave({ saveData: true }), true);
    assert.equal(shouldSave({ battery: { level: 0.15, charging: false } }), true);
    assert.equal(shouldSave({ battery: { level: 0.15, charging: true } }), false);
    assert.equal(shouldSave({ battery: { level: 0.8, charging: false } }), false);
    assert.equal(shouldSave(), false);
});

test('sync: the newest fix rides along with every poll while moving, one per 15 s when parked', () => {
    const t = new LocationThrottler();
    t.push(fix(1000));
    t.push(fix(2000));
    t.push(fix(3000));
    assert.deepEqual(t.takeForSync(3000, { moving: true }).map((f) => f.recorded_at), [3000], 'only the newest while moving');
    assert.deepEqual(t.takeForSync(3100, { moving: true }), [], 'nothing while a sync is in flight');
    t.syncSent(1);
    assert.equal(t.pending(), 0);

    t.push(fix(5000));
    assert.deepEqual(t.takeForSync(6000, { moving: false }), [], 'parked: not yet 15 s since the last fix went up');
    assert.deepEqual(t.takeForSync(18000, { moving: false }).map((f) => f.recorded_at), [5000]);
    t.syncSent(1);
    assert.deepEqual(t.takeForSync(19000, { moving: false }), [], 'nothing queued');
});

test('sync: a failed sync keeps its fixes and the next one sends the backlog in order', () => {
    const t = new LocationThrottler({ maxBatch: 3 });
    t.push(fix(1000));
    const first = t.takeForSync(1000, { moving: true });
    t.syncFailed();
    t.push(fix(2000));
    t.push(fix(3000));
    t.push(fix(4000));
    assert.deepEqual(first.map((f) => f.recorded_at), [1000]);
    assert.deepEqual(t.takeForSync(4000, { moving: false }).map((f) => f.recorded_at), [1000, 2000, 3000], 'backlog goes even when parked, capped');
    t.syncSent(3);
    assert.deepEqual(t.takeForSync(4100, { moving: false }), [], 'one fix left is not a backlog: back to the parked rule');
    assert.equal(t.pending(), 1);
});
