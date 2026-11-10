// R-46 passenger app: pure logic (feed client state machine, money, fare breakdown, countdowns, labels, status
// words). Run with: node --test "tests/js/*.test.mjs"
import test from 'node:test';
import assert from 'node:assert/strict';
import { FeedClient, MAX_BACKOFF_S, mergeFeed, nextDelay, reduce } from '../../resources/js/passenger/feed.js';
import { formatMinor, parseAmountToMinor, spokenMinor } from '../../resources/js/passenger/money.js';
import { breakdownRows, formatKm, formatMinutes } from '../../resources/js/passenger/breakdown.js';
import { clock, freeCancelLeft, quoteState, searchProgress, secondsUntil, waitState } from '../../resources/js/passenger/countdown.js';
import { defaultPayment, etaText, laterError, paymentOptions, requestLabel, unavailableText } from '../../resources/js/passenger/labels.js';
import { announcement, holdsDriver, isPreTrip, isTerminal, rideText, stageIndex } from '../../resources/js/passenger/status.js';
import { countdownSteps } from '../../resources/js/passenger/safety.js';

const NNBSP = ' ';

// ------------------------------------------------------------------ money
test('money is formatted from integer minor units like the server (narrow space, U+2212)', () => {
    assert.equal(formatMinor(7400), 'R74.00');
    assert.equal(formatMinor(125050), `R1${NNBSP}250.50`);
    assert.equal(formatMinor(-47), '−R0.47');
    assert.equal(formatMinor(500, 'ZAR', { signed: true }), '+R5.00');
    assert.equal(formatMinor(7400, 'ZAR', { whole: true }), 'R74');
    assert.equal(formatMinor(1000, 'JPY'), 'JPY 1' + NNBSP + '000');
    assert.equal(spokenMinor(-2500), 'minus R25.00');
});

test('typed amounts become minor units without floating point', () => {
    assert.equal(parseAmountToMinor('R 85.50'), 8550);
    assert.equal(parseAmountToMinor('85,5'), 8550);
    assert.equal(parseAmountToMinor('1 250'), 125000);
    assert.equal(parseAmountToMinor('0.1'), 10);
    assert.equal(parseAmountToMinor('12.345'), null, 'more decimals than the currency has');
    assert.equal(parseAmountToMinor('-5'), null);
    assert.equal(parseAmountToMinor('abc'), null);
});

// ------------------------------------------------------------------ breakdown
const fare = {
    currency: 'ZAR',
    components: [
        { code: 'base', label: 'Base fare', amount_minor: 1500, detail: null },
        { code: 'distance', label: 'Distance', amount_minor: 4746, detail: '5.0 km x R 9.50/km' },
        { code: 'time', label: 'Time', amount_minor: 900, detail: '10 min x R 0.90/min' },
        { code: 'booking_fee', label: 'Booking fee', amount_minor: 300, detail: null },
        { code: 'rounding', label: 'Rounding', amount_minor: -46, detail: 'To the nearest R 1.00' },
    ],
    total: { amount_minor: 7400, currency: 'ZAR' },
    demand_multiplier: null,
    tax_included: null,
    notes: ['Tolls on the route, if any, are added at cost after the trip.'],
};

test('the breakdown lists every line in order and says whether it adds up to the total', () => {
    const model = breakdownRows(fare);
    assert.deepEqual(model.rows.map((r) => r.code), ['base', 'distance', 'time', 'booking_fee', 'rounding']);
    assert.equal(model.rows[1].detail, '5.0 km x R 9.50/km');
    assert.equal(model.rows[4].negative, true);
    assert.equal(model.rows[4].spoken, 'minus R0.46');
    assert.equal(model.total.text, 'R74.00');
    assert.equal(model.adds_up, true);
    assert.deepEqual(model.notes, ['Tolls on the route, if any, are added at cost after the trip.']);
});

test('a breakdown whose lines do not sum to the total is flagged, and multipliers and tax are explained', () => {
    const broken = { ...fare, total: { amount_minor: 9999 } };
    assert.equal(breakdownRows(broken).adds_up, false);
    const busy = { ...fare, demand_multiplier: '1.2x', tax_included: { amount_minor: 965 } };
    const notes = breakdownRows(busy).notes;
    assert.match(notes[0], /1\.2x/);
    assert.match(notes.at(-1), /R9\.65/);
    assert.equal(breakdownRows(null).total, null);
});

test('distance and time read as people say them', () => {
    assert.equal(formatKm(4996), '5.0 km');
    assert.equal(formatKm(640), '640 m');
    assert.equal(formatMinutes(600), '10 min');
    assert.equal(formatMinutes(20), '1 min');
    assert.equal(formatMinutes(3900), '1 h 05 min');
});

// ------------------------------------------------------------------ countdowns
const T0 = Date.parse('2026-11-10T08:00:00Z');

test('quote expiry counts down and turns to ending, then expired', () => {
    assert.deepEqual(quoteState('2026-11-10T08:05:00Z', T0), { state: 'fresh', left: 300 });
    assert.deepEqual(quoteState('2026-11-10T08:00:45Z', T0), { state: 'ending', left: 45 });
    assert.deepEqual(quoteState('2026-11-10T07:59:00Z', T0), { state: 'expired', left: 0 });
    assert.equal(quoteState(null, T0).state, 'none');
    assert.equal(clock(272), '4:32');
    assert.equal(clock(3723), '1:02:03');
    assert.equal(secondsUntil('not a date', T0), null);
});

test('waiting at pickup is free for the rule set minutes, then charged per started minute', () => {
    const arrived = '2026-11-10T07:57:00Z';
    assert.deepEqual(waitState(arrived, 5, T0), { waited: 180, freeLeft: 120, charging: false, chargedMinutes: 0 });
    assert.deepEqual(waitState(arrived, 2, T0), { waited: 180, freeLeft: 0, charging: true, chargedMinutes: 1 });
    assert.equal(waitState(null, 5, T0), null);
});

test('search progress and the free cancellation window', () => {
    assert.equal(searchProgress('2026-11-10T07:55:00Z', '2026-11-10T08:05:00Z', T0), 0.5);
    assert.equal(searchProgress('2026-11-10T08:05:00Z', '2026-11-10T07:55:00Z', T0), null);
    assert.equal(freeCancelLeft('2026-11-10T07:59:00Z', 2, T0), 60);
    assert.equal(freeCancelLeft('2026-11-10T07:50:00Z', 2, T0), 0);
    assert.equal(freeCancelLeft(null, 2, T0), null);
});

test('SOS waits three seconds, one at a time, before sending', () => {
    assert.deepEqual(countdownSteps(), [3, 2, 1]);
});

// ------------------------------------------------------------------ labels
test('pickup ETA wording is honest about its source', () => {
    assert.equal(etaText({ state: 'eta', eta_s: 240, method: 'road' }).text, '4 min to you');
    assert.match(etaText({ state: 'eta', eta_s: 240, method: 'straight_line_estimate' }).text, /About 4 min.*straight-line estimate/);
    assert.equal(etaText({ state: 'none' }, 'available').state, 'none');
    assert.equal(etaText(null, 'unknown').state, 'unknown');
    assert.match(etaText(null, 'available').text, /Cars nearby/);
});

const wallet = {
    methods: [
        { id: 3, type: 'card', label: 'Visa •••• 4081', exp_month: '12', exp_year: '2030', is_default: false, chargeable: true },
        { id: 4, type: 'card', label: 'Mastercard •••• 1111', exp_month: '01', exp_year: '2031', is_default: true, chargeable: true },
        { id: 5, type: 'card', label: 'Expired', exp_month: '01', exp_year: '2020', is_default: false, chargeable: false },
    ],
    card_payments: { available: true },
    cash: { enabled: true, categories: ['economy'] },
    business_accounts: { available: false },
};

test('payment choices: chargeable cards, cash only where allowed, no business account when unavailable', () => {
    const options = paymentOptions(wallet, 'economy');
    assert.deepEqual(options.map((o) => o.value), ['card:3', 'card:4', 'cash']);
    assert.equal(defaultPayment(options), 'card:4', 'the default card is preselected');
    assert.deepEqual(paymentOptions(wallet, 'premium').map((o) => o.value), ['card:3', 'card:4'], 'cash limited to its categories');
    const noCards = paymentOptions({ ...wallet, methods: [] }, 'economy');
    assert.equal(noCards[0].value, 'checkout');
    assert.equal(noCards[0].kind, null, 'checkout sends no method; the server picks pay-after-trip');
    assert.deepEqual(paymentOptions({ ...wallet, card_payments: { available: false }, cash: { enabled: false } }), []);
    assert.deepEqual(paymentOptions(null), []);
});

test('the request button says what it does and for how much', () => {
    const option = { category: { name: 'Comfort' }, fare: { total: { amount_minor: 7400, currency: 'ZAR' } } };
    assert.equal(requestLabel(option), 'Request Comfort, R74.00');
    assert.match(requestLabel(option, '2026-11-11T09:30'), /^Book Comfort for .*R74\.00$/);
    assert.equal(requestLabel(null), 'Choose a ride');
});

test('unpriceable trips say why in plain words', () => {
    assert.match(unavailableText({ reason: 'routing_unavailable', detail: 'not_configured' }, 'Route pricing is unavailable: x'), /Route pricing is unavailable/);
    assert.match(unavailableText({ reason: 'routing_unavailable', detail: 'timeout' }), /could not be worked out/);
    assert.equal(unavailableText({ reason: 'outside_service_area', message: 'Not here yet.' }), 'Not here yet.');
});

test('scheduled pickup times respect the lead-time rules', () => {
    const rules = { min_lead_minutes: 30, max_lead_days: 30 };
    const now = new Date('2026-11-10T08:00:00');
    assert.match(laterError('2026-11-10T08:10', rules, now), /at least 30 minutes/);
    assert.equal(laterError('2026-11-10T09:00', rules, now), null);
    assert.match(laterError('2026-12-31T09:00', rules, now), /within 30 days/);
    assert.match(laterError('', rules, now), /Choose/);
});

// ------------------------------------------------------------------ status words
test('every ride state has words, a stage and the right flags', () => {
    const ride = { status: 'driver_arriving', driver: { first_name: 'Lindiwe' }, dropoff: { address: 'Bree Street, City Centre' } };
    assert.deepEqual(rideText(ride, { eta_s: 240, eta_method: 'road' }), { title: 'Lindiwe is on the way', sub: 'Arrives in 4 min.' });
    assert.match(rideText(ride, { eta_s: 240, eta_method: 'straight_line_estimate' }).sub, /about 4 min/);
    assert.equal(rideText({ ...ride, status: 'in_progress' }).title, 'On the way to Bree Street');
    assert.equal(rideText({ status: 'expired' }).title, 'No driver found');
    assert.equal(rideText({ status: 'scheduled', schedule_phase: 'driver_confirmed' }).sub, 'A driver has confirmed your ride.');
    assert.deepEqual(['searching', 'driver_assigned', 'driver_arrived', 'in_progress', 'completed', 'expired'].map(stageIndex), [0, 1, 2, 3, 4, -1]);
    assert.equal(holdsDriver('in_progress'), true);
    assert.equal(isPreTrip('in_progress'), false);
    assert.equal(isTerminal('cancelled_by_driver'), true);
});

test('a state change is announced once, urgently for the moments that need action', () => {
    const ride = { status: 'driver_arrived', driver: { first_name: 'Lindiwe' } };
    assert.equal(announcement('driver_arrived', ride, null), null);
    const said = announcement('driver_arriving', ride, null);
    assert.equal(said.assertive, true);
    assert.match(said.text, /Lindiwe is here/);
    assert.equal(announcement('searching', { status: 'driver_assigned' }, null).assertive, false);
});

// ------------------------------------------------------------------ feed client
test('a delta replaces only the sections it carries; a ride without a driver drops the position', () => {
    const held = { version: 1, ride: { status: 'driver_arriving' }, driver_position: { lat: 1 }, connection: { driver_position: 'live' } };
    const moved = mergeFeed(held, { version: 2, driver_position: { lat: 2 } });
    assert.equal(moved.ride.status, 'driver_arriving');
    assert.equal(moved.driver_position.lat, 2);
    const done = mergeFeed(moved, { version: 3, ride: { status: 'completed' } });
    assert.equal(done.driver_position, null);
    assert.equal(mergeFeed(held, null), held);
});

test('polling waits as long as the server asks and backs off exponentially on errors', () => {
    assert.equal(nextDelay({ outcome: 'ok', retryAfter: 3 }), 3);
    assert.equal(nextDelay({ outcome: 'not_modified', pollAfter: 30 }), 30);
    assert.equal(nextDelay({ outcome: 'ok' }), 3);
    assert.equal(nextDelay({ outcome: 'rate_limited' }), 10);
    assert.deepEqual([1, 2, 3, 4, 5, 6, 7].map((failures) => nextDelay({ outcome: 'error', failures })), [2, 4, 8, 16, 32, MAX_BACKOFF_S, MAX_BACKOFF_S]);
});

test('the feed state machine: hidden pauses, two failures mean offline, success recovers, gone stops for good', () => {
    let s = reduce({ state: 'idle', failures: 0 }, 'start');
    assert.equal(s.state, 'live');
    s = reduce(s, 'error');
    assert.deepEqual(s, { state: 'live', failures: 1 }, 'one blip is not news');
    s = reduce(s, 'error');
    assert.deepEqual(s, { state: 'offline', failures: 2 });
    s = reduce(s, 'ok');
    assert.deepEqual(s, { state: 'live', failures: 0 });
    s = reduce(s, 'hidden');
    assert.equal(s.state, 'paused');
    assert.equal(reduce(s, 'ok').state, 'paused', 'an answer in flight does not wake a hidden tab');
    s = reduce(s, 'visible');
    assert.equal(s.state, 'live');
    s = reduce(s, 'gone');
    assert.equal(s.state, 'stopped');
    assert.equal(reduce(s, 'start').state, 'stopped');
});

function fakeTimers() {
    const queue = [];
    return { queue, set: (fn, ms) => (queue.push({ fn, ms }), queue.length), clear: () => {} };
}

function response(status, body = null, headers = {}) {
    return { status, ok: status >= 200 && status < 300, headers: { get: (k) => headers[k] ?? null }, json: async () => body };
}

test('the feed client sends its version as an ETag, merges the answer and schedules by the server hint', async () => {
    const calls = [];
    const answers = [response(200, { version: 10, poll_after_s: 3, ride: { status: 'searching' } }, { 'Retry-After': '3' }), response(304, null, { 'Retry-After': '3' })];
    const timers = fakeTimers();
    const updates = [];
    const client = new FeedClient({
        url: '/feed',
        initial: { version: 5, ride: { status: 'requested' } },
        fetchImpl: async (url, init) => (calls.push(init.headers['If-None-Match']), answers.shift()),
        timers,
        doc: { hidden: false, addEventListener() {}, removeEventListener() {} },
        onUpdate: (snap) => updates.push(snap.ride.status),
    });
    client.start();
    await new Promise((r) => setImmediate(r));
    assert.deepEqual(calls, ['"v5"']);
    assert.deepEqual(updates, ['searching']);
    assert.equal(timers.queue.at(-1).ms, 3000);
    await client.refresh();
    assert.deepEqual(calls, ['"v5"', '"v10"']);
    assert.equal(updates.length, 1, '304 changes nothing');
    client.stop();
});

test('the feed client backs off on network errors, reports offline, and stops on 404', async () => {
    const states = [];
    const timers = fakeTimers();
    let n = 0;
    const client = new FeedClient({
        url: '/feed',
        initial: { version: 1 },
        fetchImpl: async () => {
            n++;
            if (n <= 2) throw new TypeError('network');
            return response(404, { error: 'not_found' });
        },
        timers,
        doc: { hidden: false, addEventListener() {}, removeEventListener() {} },
        onState: (s) => states.push(s),
    });
    client.start();
    await new Promise((r) => setImmediate(r));
    assert.equal(timers.queue.at(-1).ms, 2000);
    await client.refresh();
    assert.equal(timers.queue.at(-1).ms, 4000);
    assert.equal(client.state, 'offline');
    await client.refresh();
    assert.equal(client.state, 'stopped');
    assert.deepEqual(states, ['live', 'offline', 'stopped']);
});

test('the feed client does not poll while the tab is hidden and polls at once when it comes back', async () => {
    const listeners = {};
    const doc = { hidden: true, addEventListener: (k, fn) => (listeners[k] = fn), removeEventListener() {} };
    let fetched = 0;
    const client = new FeedClient({ url: '/feed', initial: { version: 1 }, fetchImpl: async () => (fetched++, response(304)), timers: fakeTimers(), doc });
    client.start();
    await new Promise((r) => setImmediate(r));
    assert.equal(fetched, 0);
    assert.equal(client.state, 'paused');
    doc.hidden = false;
    listeners.visibilitychange();
    await new Promise((r) => setImmediate(r));
    assert.equal(fetched, 1);
    assert.equal(client.state, 'live');
    client.stop();
});
