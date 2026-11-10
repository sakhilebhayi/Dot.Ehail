import { test } from 'node:test';
import assert from 'node:assert/strict';
import { PinPad } from '../../resources/js/driver/pin.js';
import { destinationArea, formatMoney } from '../../resources/js/driver/geometry.js';
import { navLink, readPrefs, writePrefs } from '../../resources/js/driver/nav.js';

test('the keypad takes four digits and nothing else', () => {
    const pad = new PinPad(4);
    ['1', '2', 'x', '3', '4', '5'].forEach((d) => pad.press(d, 0));
    assert.equal(pad.digits, '1234');
    assert.ok(pad.complete());
    pad.back();
    assert.equal(pad.digits, '123');
    assert.equal(pad.complete(), false);
    pad.clear();
    assert.equal(pad.digits, '');
});

test('a wrong PIN clears the entry; a lock disables the pad until it lifts', () => {
    const pad = new PinPad(4);
    '9999'.split('').forEach((d) => pad.press(d, 0));
    pad.refused('pin_wrong', 0);
    assert.equal(pad.digits, '');
    assert.equal(pad.error, 'pin_wrong');
    assert.equal(pad.locked(0), false);

    pad.refused('pin_locked', 1000, 10);
    assert.equal(pad.locked(1000 + 9 * 60000), true);
    assert.equal(pad.press('1', 1000 + 9 * 60000), false);
    assert.equal(pad.locked(1000 + 10 * 60000), false);
    assert.equal(pad.press('1', 1000 + 10 * 60000), true);
});

test('offer destination shows the area, not the street, before acceptance', () => {
    assert.equal(destinationArea('45 Main Road, Rondebosch, Cape Town, 7700'), 'Rondebosch, Cape Town');
    assert.equal(destinationArea('1 Jan Smuts Avenue, Rosebank, Johannesburg, 2196, South Africa'), 'Rosebank, Johannesburg');
    assert.equal(destinationArea('Sandton'), 'Sandton');
    assert.equal(destinationArea(null), '');
});

test('money formats integer minor units only', () => {
    assert.equal(formatMoney(125050), 'R1 250.50');
    assert.equal(formatMoney(-2000), '−R20.00');
    assert.equal(formatMoney(5), 'R0.05');
    assert.equal(formatMoney(null), null);
});

test('navigation links carry the next stop only, in the chosen app', () => {
    assert.equal(navLink('google', -33.9249, 18.4241), 'https://www.google.com/maps/dir/?api=1&destination=-33.924900,18.424100&travelmode=driving');
    assert.equal(navLink('waze', -33.9249, 18.4241), 'https://waze.com/ul?ll=-33.924900,18.424100&navigate=yes');
    assert.equal(navLink('apple', -33.9249, 18.4241), 'https://maps.apple.com/?daddr=-33.924900,18.424100&dirflg=d');
    assert.equal(navLink('google', null, 18.4), null);

    const store = new Map();
    const fake = { getItem: (k) => store.get(k) ?? null, setItem: (k, v) => store.set(k, v) };
    assert.deepEqual(readPrefs(fake), { nav: 'google', sound: true, vibrate: true });
    writePrefs({ nav: 'waze', sound: false }, fake);
    assert.deepEqual(readPrefs(fake), { nav: 'waze', sound: false, vibrate: true });
    assert.equal(writePrefs({ nav: 'apple' }, { getItem: () => null, setItem: () => { throw new Error('quota'); } }), false);
});
