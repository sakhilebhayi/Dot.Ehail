// R-46 a11y: announcements that inform without nagging, the map in words, and maps that keep still under reduced
// motion (D-1702, D-1704, D-1705).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { countdownCue, distanceWords, etaCue, shouldAnnounce } from '../../resources/js/eh/announce.js';
import { mapSummary } from '../../resources/js/passenger/status.js';

test('a sentence is not repeated back to back, and a topic keeps its minimum gap unless urgent', () => {
    let memory = shouldAnnounce({}, { topic: 'eta', text: 'Arrives in 5 min.', now: 0, minGapMs: 60000 });
    assert.ok(memory);
    assert.equal(shouldAnnounce(memory, { topic: 'eta', text: 'Arrives in 5 min.', now: 90000, minGapMs: 60000 }), null, 'same words stay quiet');
    assert.equal(shouldAnnounce(memory, { topic: 'eta', text: 'Arrives in 4 min.', now: 20000, minGapMs: 60000 }), null, 'too soon');
    assert.ok(shouldAnnounce(memory, { topic: 'eta', text: 'Arrives in 1 min.', now: 20000, minGapMs: 60000, urgent: true }), 'urgent speaks at once');
    memory = shouldAnnounce(memory, { topic: 'eta', text: 'Arrives in 4 min.', now: 61000, minGapMs: 60000 });
    assert.equal(memory.eta.text, 'Arrives in 4 min.');
    assert.equal(shouldAnnounce(memory, { topic: 'eta', text: '', now: 999999 }), null, 'nothing to say');
});

test('an offer countdown speaks at 10 and 5 seconds only, once each', () => {
    const said = [];
    let previous = null;
    for (const s of [15, 15, 14, 12, 11, 10, 10, 9, 7, 6, 5, 4, 3, 2, 1, 0]) {
        const mark = countdownCue(previous, s);
        if (mark) said.push(mark);
        previous = s;
    }
    assert.deepEqual(said, [10, 5]);
    assert.equal(countdownCue(null, 8), 10, 'an offer first seen with 8 s left says the 10 s mark at once');
    assert.equal(countdownCue(6, 0), null, 'nothing at zero: the expiry has its own message');
    assert.equal(countdownCue(null, Number.NaN), null);
});

test('the ETA speaks when the minute changes, at most once a minute, and at once when two minutes or less', () => {
    const first = etaCue(null, 420, 0);
    assert.equal(first.text, 'Arrives in 7 min.');
    assert.equal(etaCue(first, 410, 70000), null, 'same minute, quiet');
    assert.equal(etaCue(first, 360, 30000), null, 'a new minute but under a minute since the last one');
    const second = etaCue(first, 360, 61000);
    assert.equal(second.text, 'Arrives in 6 min.');
    const close = etaCue(second, 110, 70000);
    assert.equal(close.text, 'Arrives in 2 min.', 'two minutes away speaks at once');
    assert.ok(close.urgent);
    assert.equal(etaCue(close, 100, 75000), null, 'still 2 min');
    assert.equal(etaCue(close, 50, 76000).text, 'Arrives in 1 min.');
    assert.equal(etaCue(null, Number.NaN, 0), null);
    assert.equal(etaCue(null, 300, 0, { prefix: 'Your driver arrives in' }).text, 'Your driver arrives in 5 min.');
});

test('distances are read in plain words', () => {
    assert.equal(distanceWords(4), '10 m');
    assert.equal(distanceWords(847), '850 m');
    assert.equal(distanceWords(1234), '1.2 km');
    assert.equal(distanceWords(23456), '23 km');
    assert.equal(distanceWords(undefined), null);
});

test('the map in words: stops, the driver distance and arrival, the stale position, and no driver yet', () => {
    const ride = { status: 'driver_arriving', pickup: { lat: -33.94, lng: 18.41, address: 'Kloof Street, Gardens, Cape Town' }, dropoff: { lat: -33.913, lng: 18.4317, address: 'Bree Street, Cape Town' } };
    const near = { lat: -33.949, lng: 18.41, eta_s: 180, eta_method: 'road' };
    assert.equal(mapSummary(ride, near), 'Pickup: Kloof Street. Drop-off: Bree Street. Your driver is 1.0 km from the pickup in a straight line. Arriving in 3 min.');
    assert.match(mapSummary(ride, { ...near, eta_method: 'straight_line_estimate', stale: true }), /Arriving in about 3 min\. The driver position is not current\.$/);
    assert.match(mapSummary({ ...ride, status: 'in_progress' }, { lat: -33.92, lng: 18.43 }), /You are 790 m from the drop-off in a straight line\.$/);
    assert.equal(mapSummary({ ...ride, status: 'driver_assigned' }, null), 'Pickup: Kloof Street. Drop-off: Bree Street. The driver position is not available yet.');
    assert.equal(mapSummary({ ...ride, status: 'searching' }, near), 'Pickup: Kloof Street. Drop-off: Bree Street.');
    assert.equal(mapSummary(null), '');
});

test('an operator hears new work arrive, not every poll', async () => {
    const { risesSentence } = await import('../../resources/js/eh/announce.js');
    const labels = { needs_review: 'Needs review', incidents: 'Open incidents' };
    assert.equal(risesSentence(null, { needs_review: 1 }, labels), null, 'the first reading is the page itself');
    assert.equal(risesSentence({ needs_review: 1, incidents: 0 }, { needs_review: 1, incidents: 0 }, labels), null);
    assert.equal(risesSentence({ needs_review: 2, incidents: 1 }, { needs_review: 1, incidents: 1 }, labels), null, 'falls are quiet');
    assert.equal(risesSentence({ needs_review: 1, incidents: 0 }, { needs_review: 2, incidents: 1 }, labels), 'New work. Needs review: 2. Open incidents: 1.');
});

test('with reduced motion every map move lands at once, whoever calls it', async () => {
    const { stillMap } = await import('../../resources/js/geo/still.js');
    const calls = [];
    const record = (name) => function (...args) { calls.push([name, args]); return this; };
    const map = { setView: record('setView'), panTo: record('panTo'), fitBounds: record('fitBounds'), panBy: record('panBy'), setZoom: record('setZoom') };
    stillMap(map);
    map.panTo([1, 2]);
    map.fitBounds([[0, 0], [1, 1]], { padding: [40, 40] });
    map.setView([1, 2], 14, { animate: true });
    map.flyTo([3, 4], 12);
    assert.deepEqual(calls.map(([n, a]) => [n, a[n === 'setView' ? 2 : 1]]), [
        ['panTo', { animate: false }],
        ['fitBounds', { padding: [40, 40], animate: false }],
        ['setView', { animate: false }],
        ['setView', { animate: false }],
    ]);
});

test('a driver error without server words still says what to do', async () => {
    const { ApiError, fallbackMessage } = await import('../../resources/js/driver/api.js');
    assert.equal(new ApiError(500, null).message, 'Something went wrong on our side. Try again in a moment.');
    assert.equal(new ApiError(409, { message: 'The offer was taken.' }).message, 'The offer was taken.', 'the server words win');
    assert.match(fallbackMessage(419), /Sign in again/);
    assert.match(fallbackMessage(429), /Wait a moment/);
    for (const status of [400, 401, 403, 404, 422, 429, 500, 503]) assert.doesNotMatch(fallbackMessage(status), /\(\d{3}\)|failed/i);
});
