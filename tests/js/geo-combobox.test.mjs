import { test } from 'node:test';
import assert from 'node:assert/strict';
import { comboboxKey } from '../../resources/js/geo/combobox.js';

const closed = { open: false, active: -1, count: 3 };

test('arrow down opens on the first option, then cycles', () => {
    let { state } = comboboxKey(closed, 'ArrowDown');
    assert.deepEqual(state, { open: true, active: 0, count: 3 });
    ({ state } = comboboxKey(state, 'ArrowDown'));
    ({ state } = comboboxKey(state, 'ArrowDown'));
    assert.equal(state.active, 2);
    ({ state } = comboboxKey(state, 'ArrowDown'));
    assert.equal(state.active, 0, 'wraps to the first');
});

test('arrow up opens on the last option and wraps backwards', () => {
    let { state } = comboboxKey(closed, 'ArrowUp');
    assert.equal(state.active, 2);
    ({ state } = comboboxKey({ ...state, active: 0 }, 'ArrowUp'));
    assert.equal(state.active, 2);
});

test('enter selects the active option, or submits a search when none is active', () => {
    assert.equal(comboboxKey({ open: true, active: 1, count: 3 }, 'Enter').action, 'select');
    assert.equal(comboboxKey({ open: true, active: 1, count: 3 }, 'Enter').state.open, false);
    assert.equal(comboboxKey(closed, 'Enter').action, 'submit');
});

test('escape closes an open list, and clears when already closed', () => {
    const open = { open: true, active: 1, count: 3 };
    assert.deepEqual(comboboxKey(open, 'Escape'), { state: { open: false, active: -1, count: 3 }, action: 'close' });
    assert.equal(comboboxKey(closed, 'Escape').action, 'clear');
});

test('home, end and tab', () => {
    const open = { open: true, active: 1, count: 3 };
    assert.equal(comboboxKey(open, 'Home').state.active, 0);
    assert.equal(comboboxKey(open, 'End').state.active, 2);
    assert.equal(comboboxKey(open, 'Tab').state.open, false);
});

test('arrows do nothing without results', () => {
    const empty = { open: false, active: -1, count: 0 };
    assert.deepEqual(comboboxKey(empty, 'ArrowDown').state, empty);
    assert.deepEqual(comboboxKey(empty, 'ArrowUp').state, empty);
});
