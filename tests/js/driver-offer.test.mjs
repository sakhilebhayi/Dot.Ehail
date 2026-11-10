import { test } from 'node:test';
import assert from 'node:assert/strict';
import { commissionDetail, offerLines, percentText, ratingText } from '../../resources/js/driver/offer.js';
import { shortAddress } from '../../resources/js/driver/geometry.js';
import { alertOffer, createChime } from '../../resources/js/driver/alerts.js';

// The jury's offer (34): fare R64.00, a R3.00 booking fee the platform keeps, 20% commission on the R61.00 base.
const jury = {
    available: true, currency: 'ZAR', fare_minor: 6400, driver_gross_minor: 6100, tolls_minor: 0,
    commissionable_minor: 6100, kept_by_platform: [{ code: 'booking_fee', label: 'Booking fee', amount_minor: 300 }],
    commission_rule: { percent_bp: 2000, fixed_minor: 0, min_minor: null, max_minor: null },
    commission_minor: 1220, deductions: [{ code: 'commission', label: 'Platform commission', amount_minor: 1220, detail: '20.00% of the trip fare' }],
    net_minor: 4880, plan: { name: 'Example', is_example: true },
};

test('the offer lines list every deduction and add up to what the driver earns', () => {
    const { rows, reconciles } = offerLines(jury);
    assert.deepEqual(rows.map((r) => [r.label, r.minor]), [
        ['Fare (estimate)', 6400],
        ['Booking fee, kept by the platform', -300],
        ['Commission', -1220],
        ['You earn', 4880],
    ]);
    assert.ok(reconciles);
    assert.equal(rows.find((r) => r.key === 'commission').detail, '20% of R61.00');
    const sum = rows.filter((r) => !r.total).reduce((s, r) => s + r.minor, 0);
    assert.equal(sum, rows.at(-1).minor);
});

test('an older payload without the kept list still shows the difference as its own line', () => {
    const { rows, reconciles } = offerLines({ ...jury, kept_by_platform: undefined, commission_rule: undefined, commissionable_minor: undefined });
    assert.ok(reconciles);
    assert.deepEqual(rows[1], { key: 'kept:other', label: 'Fees, kept by the platform', minor: -300 });
    assert.equal(rows.find((r) => r.key === 'commission').detail, '20.00% of the trip fare');
});

test('lines that do not add up are reported, never hidden', () => {
    const { reconciles } = offerLines({ ...jury, net_minor: 5000 });
    assert.equal(reconciles, false);
});

test('tolls stay inside the fare and come back whole', () => {
    const data = { ...jury, fare_minor: 7400, tolls_minor: 1000, driver_gross_minor: 7100, net_minor: 5880 };
    const { rows, reconciles } = offerLines(data);
    assert.ok(reconciles);
    assert.match(rows[0].note, /includes R10\.00 tolls, paid back to you/);
});

test('a promotion the platform pays shows as an addition', () => {
    const data = { ...jury, fare_minor: 5400, kept_by_platform: [{ code: 'booking_fee', label: 'Booking fee', amount_minor: 300 }, { code: 'promotion:WELCOME', label: 'Promotion', amount_minor: -1000 }] };
    const { rows, reconciles } = offerLines(data);
    assert.ok(reconciles);
    assert.deepEqual(rows[2], { key: 'kept:promotion:WELCOME', label: 'Promotion, paid by the platform', minor: 1000 });
});

test('no plan: the net is "not configured", not zero', () => {
    const { rows, net } = offerLines({ ...jury, deductions: [], commission_minor: null, net_minor: null, commission_rule: null });
    assert.equal(net, null);
    assert.equal(rows.at(-1).minor, null);
});

test('commission detail names the fixed part and a minimum that bit', () => {
    assert.equal(commissionDetail({ ...jury, commission_rule: { percent_bp: 1250, fixed_minor: 200 }, commission_minor: 963 }), '12.5% of R61.00 + R2.00');
    assert.equal(commissionDetail({ ...jury, commission_rule: { percent_bp: 2000, fixed_minor: 0, min_minor: 1500, max_minor: null }, commission_minor: 1500 }), "20% of R61.00, raised to the plan's minimum");
    assert.equal(percentText(725), '7.25%');
});

test('addresses on driver screens are the street and the suburb', () => {
    assert.equal(shortAddress('Oxford Road, Rosebank, Johannesburg, 2196, South Africa'), 'Oxford Road, Rosebank');
    assert.equal(shortAddress('Sandton City, Rivonia Road, Sandhurst, Sandton, 2196, South Africa'), 'Sandton City, Rivonia Road, Sandhurst');
    assert.equal(shortAddress('Juta Street, Braamfontein, Johannesburg, 2001, South Africa'), 'Juta Street, Braamfontein');
    assert.equal(shortAddress('10 Kloof Street, Gardens, Cape Town, Western Cape, 8001'), '10 Kloof Street, Gardens');
    assert.equal(shortAddress('Kloof Street'), 'Kloof Street');
    assert.equal(shortAddress(null), '');
});

test('the passenger rating reads in words', () => {
    assert.equal(ratingText({ rating_band: 'new' }), 'fewer than 5 ratings');
    assert.equal(ratingText({ rating_band: 'new', rating_count: 0 }), 'no ratings yet');
    assert.equal(ratingText({ rating_band: 'new', rating_count: 3 }), '3 ratings, too few for an average');
    assert.equal(ratingText({ rating_band: '4.8+' }), 'rated 4.8 or higher');
    assert.equal(ratingText({ rating_band: '4.5-4.8' }), 'rated 4.5 to 4.8');
    assert.equal(ratingText({ rating_band: 'below 4.0' }), 'rated below 4.0');
    assert.equal(ratingText({}), null);
});

test('an offer vibrates and chimes only as the phone settings say', () => {
    const calls = [];
    const nav = { vibrate: (p) => calls.push(['vibrate', p]) };
    const chime = { play: () => calls.push(['sound']) };
    alertOffer(chime, { sound: true, vibrate: true }, nav);
    alertOffer(chime, { sound: false, vibrate: false }, nav);
    alertOffer(chime, { sound: true, vibrate: true }, {});
    assert.deepEqual(calls, [['vibrate', [200, 100, 200]], ['sound'], ['sound']]);
});

test('the chime needs a tap to unlock and says when audio is missing', () => {
    let made = 0;
    class Ctx { constructor() { made++; this.state = 'running'; this.currentTime = 0; this.destination = {}; }
        resume() { return Promise.resolve(); }
        createOscillator() { return { frequency: {}, connect: (g) => g, start() {}, stop() {} }; }
        createGain() { return { gain: { setValueAtTime() {}, exponentialRampToValueAtTime() {} }, connect: () => ({}) }; } }
    const chime = createChime({ AudioContext: Ctx });
    assert.equal(chime.play(), false);
    assert.ok(chime.unlock());
    assert.ok(chime.play());
    chime.unlock();
    assert.equal(made, 1);
    assert.equal(createChime({}).supported, false);
});

test('the pricing walk (lines) is used as it comes when the server sends it', () => {
    const data = { ...jury, lines: [
        { code: 'not_earned:booking_fee', label: 'Booking fee, kept by the platform', amount_minor: -300, detail: 'Not part of your earnings and not commissioned' },
        { code: 'commission', label: 'Platform commission', amount_minor: -1220, detail: '20% of R61.00 (fare before fees)' },
    ] };
    const { rows, reconciles } = offerLines(data);
    assert.ok(reconciles);
    assert.deepEqual(rows.map((r) => [r.label, r.minor, r.detail ?? null]), [
        ['Fare (estimate)', 6400, null],
        ['Booking fee, kept by the platform', -300, null],
        ['Commission', -1220, '20% of R61.00 (fare before fees)'],
        ['You earn', 4880, null],
    ]);
});
