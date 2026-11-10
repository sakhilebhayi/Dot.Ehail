// R-46 display time (D-1906) and the one money text (D-1903) in the browser. Run with: node --test "tests/js/*.test.mjs"
// The machine's own timezone must not matter: these run the same under TZ=UTC, TZ=America/New_York, TZ=Asia/Tokyo.
import test from 'node:test';
import assert from 'node:assert/strict';
import { DEFAULT_TZ, displayTimezone, formatLocal, offsetMinutes, timeLocal, wallTimeToIso } from '../../resources/js/eh/time.js';
import { formatMinor } from '../../resources/js/passenger/money.js';
import { formatMoney } from '../../resources/js/driver/geometry.js';

const NNBSP = ' ';

test('the display timezone is South African time unless the page names another', () => {
    assert.equal(DEFAULT_TZ, 'Africa/Johannesburg');
    assert.equal(displayTimezone(), 'Africa/Johannesburg');
});

test('05:30 UTC reads 07:30 in South Africa, on every device', () => {
    assert.equal(timeLocal('2026-11-10T05:30:00Z'), '07:30');
    assert.equal(timeLocal('2026-11-10T05:30:00+00:00'), '07:30');
    assert.equal(timeLocal(new Date(Date.UTC(2026, 10, 10, 5, 30, 0))), '07:30');
    assert.equal(timeLocal('2026-11-10T05:30:09Z', { seconds: true }), '07:30:09');
    // Midnight is 00, not 24.
    assert.equal(timeLocal('2026-11-10T22:00:00Z'), '00:00');
    // No daylight saving: June and December are both UTC+2.
    assert.equal(timeLocal('2026-06-21T05:30:00Z'), '07:30');
    assert.equal(offsetMinutes(new Date('2026-06-21T05:30:00Z')), 120);
    assert.equal(offsetMinutes(new Date('2026-12-21T05:30:00Z')), 120);
});

test('a list row and a detail page format the same instant to the same words', () => {
    const iso = '2026-11-12T05:30:00Z';
    const list = formatLocal(iso, { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
    const detail = formatLocal(new Date(iso), { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });

    assert.equal(list, detail);
    assert.match(list, /Thu/);
    assert.match(list, /12 Nov/);
    assert.match(list, /07:30/);
    assert.doesNotMatch(list, /05:30/);
    // Late evening UTC is already tomorrow in Johannesburg.
    assert.match(formatLocal('2026-11-10T22:15:00Z', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }), /11 Nov.*00:15/);
});

test('nothing to show is an empty string, never "Invalid Date"', () => {
    assert.equal(formatLocal(null), '');
    assert.equal(formatLocal(''), '');
    assert.equal(formatLocal('not a date'), '');
    assert.equal(timeLocal(undefined), '');
});

test('a time picked in a form is read as South African time, whatever the device zone', () => {
    assert.equal(wallTimeToIso('2026-11-11T07:30'), '2026-11-11T05:30:00.000Z');
    assert.equal(wallTimeToIso('2026-11-11T00:15:30'), '2026-11-10T22:15:30.000Z');
    assert.equal(wallTimeToIso('2026-11-11T07:30', 'UTC'), '2026-11-11T07:30:00.000Z');
    assert.equal(wallTimeToIso('tomorrow'), null);
    assert.equal(wallTimeToIso(null), null);
    // Round trip: pick 07:30, store UTC, show 07:30.
    assert.equal(timeLocal(wallTimeToIso('2026-11-11T07:30')), '07:30');
});

test('another service area can name its own zone', () => {
    assert.equal(timeLocal('2026-11-10T05:30:00Z', {}, 'UTC'), '05:30');
    assert.equal(timeLocal('2026-11-10T05:30:00Z', {}, 'Africa/Nairobi'), '08:30');
});

test('money reads the same in the passenger and driver apps: R10 000.00 with a narrow no-break space', () => {
    assert.equal(formatMinor(1000000), `R10${NNBSP}000.00`);
    assert.equal(formatMoney(1000000), `R10${NNBSP}000.00`);

    for (const minor of [0, 5, 6400, 99999, 100000, 123456789]) {
        assert.equal(formatMoney(minor), formatMinor(minor), String(minor));
        assert.ok(!formatMinor(minor).includes(','), 'no comma separators');
        assert.ok(!formatMinor(minor).includes(' '), 'no plain space');
    }
    assert.equal(formatMoney(null), null);
});
