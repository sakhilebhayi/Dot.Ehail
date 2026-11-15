// The shared header's two host behaviours (R-52). dot-ui (dot-nav.js, dot-search.js) owns the menu, the drawer, the theme and the open and close of the
// dropdowns; this file only feeds the search from the platform's own endpoint and keeps the legacy shell's Livewire bell open across a re-render.
// Everything is progressive: without it the search is a plain GET form to the results page and the bell is a link-free panel that still opens.

const SEARCH_EMPTY = 'Nothing matches. Try a street, a plate or a ride number.';

export function initHeaderSearch(doc = document) {
    doc.querySelectorAll('.dsr[data-eh-search]').forEach((box) => {
        if (box.dataset.ehSearchReady || !window.DotSearchResults) return;
        box.dataset.ehSearchReady = '1';
        const endpoint = box.dataset.ehSearch;
        const scope = box.dataset.ehSearchScope || '';
        window.DotSearchResults.attach(box, {
            enterFirst: true,
            minChars: 2,
            debounce: 220,
            empty: SEARCH_EMPTY,
            search: async (q, { signal }) => {
                const url = new URL(endpoint, window.location.origin);
                url.searchParams.set('q', q);
                if (scope) url.searchParams.set('scope', scope);
                const fleet = new URLSearchParams(window.location.search).get('fleet');
                if (fleet) url.searchParams.set('fleet', fleet);
                const response = await fetch(url, { signal, headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                if (!response.ok) throw new Error(`header search ${response.status}`);
                return (await response.json()).groups || [];
            },
        });
    });
}

// The legacy shell's bell is a Livewire component around dot-ui's panel bell. A re-render (mark as read) rewrites the panel's attributes, which would close it
// under the finger: remember that it is open and put the open attributes back. dot-ui owns opening and closing.
export function keepBellOpen() {
    let bellOpen = false;
    const inBell = (e) => e.target.closest && e.target.closest('[data-dh-bell]');
    document.addEventListener('dot:menu-open', (e) => { if (inBell(e)) bellOpen = true; });
    document.addEventListener('dot:menu-close', (e) => { if (inBell(e)) bellOpen = false; });
    const hook = () => window.Livewire.hook('morphed', ({ el, component }) => {
        if (component.name !== 'notification-bell' || !bellOpen) return;
        const bell = el.querySelector('[data-dh-bell]');
        if (!bell) return;
        bell.setAttribute('data-open', '');
        bell.querySelector('[data-dh-menu-panel]')?.removeAttribute('hidden');
        bell.querySelector('[data-dh-menu-trigger]')?.setAttribute('aria-expanded', 'true');
    });
    if (window.Livewire) hook(); else document.addEventListener('livewire:init', hook);
}

export function initHeader() {
    // This module is deferred and runs BEFORE dot-ui's own deferred script, with readyState already "interactive": the results controller does not exist
    // yet, so attach at DOMContentLoaded (it fires after every deferred script) unless it is already there or the page has finished loading.
    if (document.readyState === 'complete' || window.DotSearchResults) initHeaderSearch();
    else document.addEventListener('DOMContentLoaded', () => initHeaderSearch());
    document.addEventListener('livewire:navigated', () => initHeaderSearch());
    if (document.querySelector('[data-dh-bell]') || document.documentElement.classList.contains('eh-legacy')) keepBellOpen();
}
