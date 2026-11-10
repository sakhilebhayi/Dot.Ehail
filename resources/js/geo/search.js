// Place search: a keyboard-accessible combobox over the server proxy (/geo/search). With a provider that allows
// search-as-you-type it searches 350 ms after typing stops. With one that does not (public Nominatim: no
// keystroke autocomplete) it searches on Enter, on the search button inside the field, or once when typing has
// paused for a full second with at least 4 letters; the same words are never sent twice in a row, the server
// caches answers for 24 h and holds the whole app to one request a second (R-46 ppass, D-2001).

import { comboboxKey, debounce } from './combobox.js';
import { getJson } from './network.js';

let uid = 0;

// The area line under a result, without the postcode and the country (one-country service): "Rivonia Road, Sandhurst, Sandton".
export function areaLine(text) {
    return String(text || '')
        .split(',')
        .map((p) => p.trim())
        .filter((p) => p && !/^\d{4}$/.test(p) && !/^(south africa|suid-afrika)$/i.test(p))
        .join(', ');
}

/**
 * Enhances markup:
 *   <div data-geo-search>
 *     <label for="x">Pickup</label>
 *     <div class="eh-search__field"><input id="x" type="search"> <button type="button" data-geo-search-submit>Search</button></div>
 *     <p data-geo-search-hint></p>
 *     <ul data-geo-search-list></ul>
 *     <p data-geo-search-status></p>
 *   </div>
 * options: {config, near: () => {lat, lng} | null, onSelect(place)}
 */
export function createPlaceSearch(root, { config, near = () => null, onSelect = () => {} }) {
    const input = root.querySelector('input');
    const list = root.querySelector('[data-geo-search-list]');
    const status = root.querySelector('[data-geo-search-status]');
    const hint = root.querySelector('[data-geo-search-hint]');
    const submit = root.querySelector('[data-geo-search-submit]');
    const clearBtn = root.querySelector('[data-eh-place-clear]');
    const id = `eh-search-${++uid}`;
    const autocomplete = Boolean(config.geocoding.autocomplete);

    list.id = `${id}-list`;
    list.setAttribute('role', 'listbox');
    list.hidden = true;
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-expanded', 'false');
    input.setAttribute('aria-controls', list.id);
    input.setAttribute('autocomplete', 'off');
    status.setAttribute('role', 'status');
    status.setAttribute('aria-live', 'polite');

    if (hint) {
        hint.id = `${id}-hint`;
        input.setAttribute('aria-describedby', hint.id);
        hint.textContent = !config.geocoding.configured
            ? 'Address search is not set up. Tap the map to place the pin.'
            : '';
    }

    if (submit) {
        submit.hidden = autocomplete;
    }

    if (!config.geocoding.configured) {
        input.disabled = true;
        submit && (submit.disabled = true);

        return { destroy() {}, setValue() {}, clear() {} };
    }

    let state = { open: false, active: -1, count: 0 };
    let results = [];
    let inflight = null;
    let lastQuery = null;

    const render = () => {
        input.setAttribute('aria-expanded', String(state.open));
        list.hidden = !state.open;
        [...list.children].forEach((item, index) => {
            const selected = index === state.active;
            item.setAttribute('aria-selected', String(selected));
            item.classList.toggle('is-active', selected);

            if (selected) {
                item.scrollIntoView?.({ block: 'nearest' });
            }
        });

        if (state.open && state.active >= 0) {
            input.setAttribute('aria-activedescendant', `${id}-opt-${state.active}`);
        } else {
            input.removeAttribute('aria-activedescendant');
        }
    };

    const setStatus = (message, kind = '') => {
        status.textContent = message;
        status.dataset.kind = kind;
    };

    const choose = (index) => {
        const place = results[index];

        if (!place) {
            return;
        }

        input.value = place.name === place.label ? place.label : `${place.name}, ${place.label.replace(`${place.name}, `, '')}`;
        state = { open: false, active: -1, count: 0 };
        list.replaceChildren();
        render();
        setStatus('');
        syncClear();
        onSelect(place);
    };

    const show = (places) => {
        results = places;
        list.replaceChildren(
            ...places.map((place, index) => {
                const item = document.createElement('li');
                item.id = `${id}-opt-${index}`;
                item.setAttribute('role', 'option');
                item.className = 'eh-search__option';

                const name = document.createElement('span');
                name.className = 'eh-search__name';
                name.textContent = place.name;
                item.appendChild(name);

                if (place.label !== place.name) {
                    const detail = document.createElement('span');
                    detail.className = 'eh-search__detail';
                    detail.textContent = areaLine(place.label.startsWith(`${place.name}, `) ? place.label.slice(place.name.length + 2) : place.label);
                    item.appendChild(detail);
                }

                // pointerdown keeps focus in the input (blur would close the list before click lands).
                item.addEventListener('pointerdown', (event) => {
                    event.preventDefault();
                    choose(index);
                });

                return item;
            }),
        );
        state = { open: places.length > 0, active: places.length > 0 && !autocomplete ? 0 : -1, count: places.length };
        render();
        setStatus(places.length === 0 ? 'No places found. Try a street and suburb, or tap the map.' : `${places.length} ${places.length === 1 ? 'place' : 'places'} found.`);
    };

    const run = async ({ pause = false } = {}) => {
        const query = input.value.trim();

        // A pause search never repeats the words just searched (the list is already showing their answer).
        if (pause && (query.length < 4 || query === lastQuery)) {
            return;
        }

        if (query.length < 3) {
            show([]);
            setStatus(query.length === 0 ? '' : 'Type at least 3 letters.');

            return;
        }

        inflight?.abort();
        inflight = new AbortController();
        lastQuery = query;
        root.setAttribute('aria-busy', 'true');
        setStatus('Searching…');

        try {
            const point = near();
            const body = await getJson(config.endpoints.search, { q: query, lat: point?.lat, lng: point?.lng }, { signal: inflight.signal });
            show(body.results || []);
        } catch (error) {
            if (error.reason !== 'aborted') {
                show([]);
                setStatus(error.message, 'error');
            }
        } finally {
            root.removeAttribute('aria-busy');
        }
    };

    const syncClear = () => {
        if (clearBtn) clearBtn.hidden = input.value === '' || input.disabled;
    };

    const typed = debounce(run, 350);
    const paused = debounce(() => run({ pause: true }), 1000);

    const onInput = () => {
        if (autocomplete) {
            typed();
        } else {
            if (state.open) {
                state = { ...state, open: false };
                render();
            }
            paused();
        }
        syncClear();
        root.dispatchEvent(new CustomEvent('geo:typing', { detail: { value: input.value } }));
    };

    const onKeyDown = (event) => {
        const { state: next, action } = comboboxKey(state, event.key);

        const moved = next.open !== state.open || next.active !== state.active;

        if (moved && ['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) {
            event.preventDefault();
        }

        const chosen = state.active;
        state = next;
        render();

        if (action === 'select') {
            event.preventDefault();
            choose(chosen);
        } else if (action === 'submit') {
            event.preventDefault();
            typed.cancel();
            paused.cancel();
            run();
        } else if (action === 'close') {
            event.key === 'Escape' && event.preventDefault();
        }
    };

    const onBlur = () => {
        setTimeout(() => {
            state = { ...state, open: false };
            render();
        }, 0);
    };

    input.addEventListener('input', onInput);
    clearBtn?.addEventListener('click', () => {
        controller.clear();
        input.focus();
        root.dispatchEvent(new CustomEvent('geo:cleared'));
    });
    input.addEventListener('keydown', onKeyDown);
    input.addEventListener('blur', onBlur);
    submit?.addEventListener('click', () => {
        typed.cancel();
        paused.cancel();
        run();
        input.focus();
    });

    const controller = {
        setValue(text) {
            input.value = text;
            lastQuery = null;
            syncClear();
        },
        clear() {
            inflight?.abort();
            typed.cancel();
            paused.cancel();
            input.value = '';
            lastQuery = null;
            results = [];
            list.replaceChildren();
            state = { open: false, active: -1, count: 0 };
            render();
            setStatus('');
            syncClear();
        },
        destroy() {
            inflight?.abort();
            typed.cancel();
            paused.cancel();
            input.removeEventListener('input', onInput);
            input.removeEventListener('keydown', onKeyDown);
            input.removeEventListener('blur', onBlur);
        },
    };

    syncClear();

    return controller;
}
