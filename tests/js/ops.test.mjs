// Operations centre helpers (R-46 ops): durations, timers, refusal messages, rand to cents without floats.
import test from 'node:test';
import assert from 'node:assert/strict';
import { formatDuration, describeTimer, errorMessage, randToMinor } from '../../resources/js/ops/helpers.js';

test('durations read like the server wording', () => {
    assert.equal(formatDuration(30), 'under 1 min');
    assert.equal(formatDuration(240), '4 min');
    assert.equal(formatDuration(3900), '1 h 05 min');
    assert.equal(formatDuration(-600), '10 min');
    assert.equal(formatDuration(3 * 86400 + 7200), '3 d 2 h');
});

test('timers say due or overdue with a tone', () => {
    const now = Date.parse('2026-11-10T10:00:00Z');
    assert.deepEqual(describeTimer({ dueAt: '2026-11-10T10:30:00Z', now, warnSeconds: 300, label: 'first reply' }), { text: 'first reply due in 30 min', tone: 'calm' });
    assert.deepEqual(describeTimer({ dueAt: '2026-11-10T10:04:00Z', now, warnSeconds: 300 }), { text: 'due in 4 min', tone: 'caution' });
    assert.deepEqual(describeTimer({ dueAt: '2026-11-10T09:50:00Z', now }), { text: 'overdue by 10 min', tone: 'stop' });
    assert.deepEqual(describeTimer({ since: '2026-11-10T09:55:00Z', now, warnSeconds: 120, label: 'searching' }), { text: 'searching 5 min', tone: 'caution' });
});

test('refusals become one plain sentence', () => {
    assert.equal(errorMessage(403, null), 'Your operations role does not include this action.');
    assert.equal(errorMessage(422, { errors: { reason: ['The reason field is required.'] } }), 'The reason field is required.');
    assert.equal(errorMessage(409, { message: 'This ride cannot move to that state.' }), 'This ride cannot move to that state.');
    assert.equal(errorMessage(409, { error: 'conflict_retry' }), 'conflict retry');
    assert.match(errorMessage(500, null), /Nothing was changed/);
});

test('rand amounts become integer cents as strings', () => {
    assert.equal(randToMinor('125.50'), '12550');
    assert.equal(randToMinor('125,5'), '12550');
    assert.equal(randToMinor('R 1 250'), '125000');
    assert.equal(randToMinor('0'), '0');
    assert.equal(randToMinor('0.05'), '5');
    assert.equal(randToMinor('19.99'), '1999');
    assert.equal(randToMinor('1.234'), null);
    assert.equal(randToMinor('-5'), null);
    assert.equal(randToMinor('abc'), null);
});
