// ds (R-46): pure parts of the shell scripts. Run with: node --test "tests/js/*.test.mjs"
import test from 'node:test';
import assert from 'node:assert/strict';
import { nextSnap, nearestSnap, SNAPS } from '../../resources/js/eh/sheet.js';
import { resolveTheme } from '../../resources/js/eh/theme.js';

test('the sheet snaps in order and stops at the ends', () => {
    assert.deepEqual(SNAPS, ['peek', 'half', 'full']);
    assert.equal(nextSnap('peek', 'up'), 'half');
    assert.equal(nextSnap('half', 'up'), 'full');
    assert.equal(nextSnap('full', 'up'), 'full');
    assert.equal(nextSnap('full', 'down'), 'half');
    assert.equal(nextSnap('peek', 'down'), 'peek');
    assert.equal(nextSnap('full', 'cycle'), 'peek');
    assert.equal(nextSnap('unknown', 'up'), 'half');
});

test('a drag lands on the nearest snap point', () => {
    const height = 800; const peek = 168;
    assert.equal(nearestSnap(150, height, peek), 'peek');
    assert.equal(nearestSnap(380, height, peek), 'half');
    assert.equal(nearestSnap(700, height, peek), 'full');
});

test('theme choices resolve: pinned, system, and the driver night rule', () => {
    assert.equal(resolveTheme('light', 22, true), 'light');
    assert.equal(resolveTheme('dark', 12, false), 'dark');
    assert.equal(resolveTheme('system', 12, true), null);
    assert.equal(resolveTheme('night', 19, false), 'dark');
    assert.equal(resolveTheme('night', 5, false), 'dark');
    assert.equal(resolveTheme('night', 6, false), 'light');
    assert.equal(resolveTheme('night', 12, true), 'dark');
    assert.equal(resolveTheme('night', 17, false), 'light');
});
