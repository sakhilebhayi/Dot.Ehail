import { test } from 'node:test';
import assert from 'node:assert/strict';
import { clockOffset, fractionLeft, secondsLeft, startCountdown } from '../../resources/js/driver/countdown.js';

const sent = '2026-11-10T08:00:00.000Z';
const expires = '2026-11-10T08:00:15.000Z';
const at = (s) => Date.parse(sent) + s * 1000;

test('seconds left counts down on the server clock and never goes negative', () => {
    assert.equal(secondsLeft(expires, at(0)), 15);
    assert.equal(secondsLeft(expires, at(14.2)), 1, 'rounds up so 1 shows until the end');
    assert.equal(secondsLeft(expires, at(15)), 0);
    assert.equal(secondsLeft(expires, at(40)), 0);
    assert.equal(secondsLeft('not a date', at(0)), 0);
});

test('a phone clock that is wrong is corrected by the offset from the feed', () => {
    // The phone is 20 s fast: when the server says 08:00:00 the phone says 08:00:20.
    const offset = clockOffset(sent, at(20));
    assert.equal(offset, -20000);
    assert.equal(secondsLeft(expires, at(20), offset), 15);
    assert.equal(clockOffset('garbage', at(0)), 0);
});

test('the burning line is the share of the window left', () => {
    assert.equal(fractionLeft(sent, expires, at(0)), 1);
    assert.equal(fractionLeft(sent, expires, at(7.5)), 0.5);
    assert.equal(fractionLeft(sent, expires, at(30)), 0);
    assert.equal(fractionLeft(expires, sent, at(0)), 0, 'a nonsense window is empty');
});

test('the countdown ticks, expires once and can be stopped', () => {
    let now = at(13);
    let timer = null;
    const ticks = [];
    let expired = 0;
    startCountdown({
        sentAt: sent, expiresAt: expires, now: () => now,
        setTimer: (fn) => { timer = fn; return 1; }, clearTimer: () => { timer = null; },
        onTick: (s) => ticks.push(s), onExpire: () => expired++,
    });
    assert.deepEqual(ticks, [2]);
    now = at(14.5); timer();
    now = at(15.1); timer();
    assert.deepEqual(ticks, [2, 1, 0]);
    assert.equal(expired, 1);
    assert.equal(timer, null, 'cleared after expiry');

    let stopped = false;
    const stop = startCountdown({ sentAt: sent, expiresAt: expires, now: () => at(1), setTimer: () => 7, clearTimer: () => { stopped = true; }, onExpire: () => assert.fail('must not expire') });
    stop();
    assert.ok(stopped);
});
