// The place-search combobox: a pure keyboard reducer (tested) plus the DOM binding.
// Pattern: WAI-ARIA 1.2 combobox with a listbox popup, focus stays in the input, aria-activedescendant moves.

/**
 * state: {open: boolean, active: number (-1 for none), count: number}
 * returns {state, action} where action is null | 'select' | 'close' | 'submit'.
 */
export function comboboxKey(state, key) {
    const { open, active, count } = state;

    switch (key) {
        case 'ArrowDown':
            if (count === 0) {
                return { state, action: null };
            }

            return { state: { ...state, open: true, active: open ? (active + 1) % count : 0 }, action: null };
        case 'ArrowUp':
            if (count === 0) {
                return { state, action: null };
            }

            return { state: { ...state, open: true, active: open ? (active - 1 + count) % count : count - 1 }, action: null };
        case 'Home':
            return open && count > 0 ? { state: { ...state, active: 0 }, action: null } : { state, action: null };
        case 'End':
            return open && count > 0 ? { state: { ...state, active: count - 1 }, action: null } : { state, action: null };
        case 'Enter':
            if (open && active >= 0 && active < count) {
                return { state: { ...state, open: false }, action: 'select' };
            }

            return { state, action: 'submit' };
        case 'Escape':
            return open ? { state: { ...state, open: false, active: -1 }, action: 'close' } : { state, action: 'clear' };
        case 'Tab':
            return { state: { ...state, open: false }, action: open ? 'close' : null };
        default:
            return { state, action: null };
    }
}

/** Debounce with cancel, for search-as-you-type. */
export function debounce(fn, wait) {
    let timer = null;

    const debounced = (...args) => {
        clearTimeout(timer);
        timer = setTimeout(() => fn(...args), wait);
    };

    debounced.cancel = () => clearTimeout(timer);

    return debounced;
}
