// The live board's emergency rail (R-46 polish, D-2202): the title counts what nobody owns, and the alarm sounds
// only for emergencies it has not sounded for yet.
import test from 'node:test';
import assert from 'node:assert/strict';
import { takeoverTitle, newUnowned } from '../../resources/js/ops/takeover.js';

test('the title says how many emergencies nobody owns', () => {
    assert.equal(takeoverTitle([{ id: 1, owner: null }]), '1 emergency open, 1 with nobody owning it');
    assert.equal(takeoverTitle([{ id: 1, owner: null }, { id: 2, owner: null }, { id: 3, owner: 'Busi' }]), '3 emergencies open, 2 with nobody owning them');
    assert.equal(takeoverTitle([{ id: 1, owner: 'Busi' }]), '1 emergency open');
});

test('only unowned emergencies that have not sounded yet are new', () => {
    const items = [{ id: 1, owner: null }, { id: 2, owner: 'Busi' }, { id: 3, owner: null }];
    assert.deepEqual(newUnowned(new Set(), items), [1, 3]);
    assert.deepEqual(newUnowned(new Set([1]), items), [3]);
    assert.deepEqual(newUnowned(new Set([1, 3]), items), []);
});
