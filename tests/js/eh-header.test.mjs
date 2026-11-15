import test from 'node:test';
import assert from 'node:assert/strict';

// The header search is attached to dot-ui's results controller. The Vite entry (a deferred module) runs BEFORE dot-ui's own deferred script, while
// document.readyState is already "interactive", so a "not loading, run now" check found no controller and the search never attached. It must wait for
// DOMContentLoaded (which fires after every deferred script) unless the controller is already there.

function makeEnv({ readyState, controller }) {
    const listeners = {};
    const box = { dataset: { ehSearch: '/search/suggest', ehSearchScope: 'passenger' } };
    globalThis.document = {
        readyState,
        documentElement: { classList: { contains: () => false } },
        querySelectorAll: (sel) => (sel === '.dsr[data-eh-search]' ? [box] : []),
        querySelector: () => null,
        addEventListener: (name, fn) => { (listeners[name] ||= []).push(fn); },
    };
    const attached = [];
    globalThis.window = { location: { origin: 'http://x', search: '' }, DotSearchResults: controller ? { attach: (b, o) => attached.push([b, o]) } : undefined };
    return { listeners, attached, box };
}

test('with the controller not loaded yet, the search attaches at DOMContentLoaded', async () => {
    const env = makeEnv({ readyState: 'interactive', controller: false });
    const { initHeader } = await import('../../resources/js/eh/header.js?a=' + Math.random());
    initHeader();
    assert.equal(env.attached.length, 0, 'nothing to attach to yet');
    globalThis.window.DotSearchResults = { attach: (b, o) => env.attached.push([b, o]) }; // dot-ui's deferred script has run
    (env.listeners.DOMContentLoaded || []).forEach((fn) => fn());
    assert.equal(env.attached.length, 1, 'attached once the controller exists');
    assert.equal(env.attached[0][1].enterFirst, true);
});

test('with the controller already there, the search attaches at once', async () => {
    const env = makeEnv({ readyState: 'interactive', controller: true });
    const { initHeader } = await import('../../resources/js/eh/header.js?b=' + Math.random());
    initHeader();
    assert.equal(env.attached.length, 1);
});

test('a page that has finished loading attaches at once', async () => {
    const env = makeEnv({ readyState: 'complete', controller: true });
    const { initHeader } = await import('../../resources/js/eh/header.js?c=' + Math.random());
    initHeader();
    assert.equal(env.attached.length, 1);
});
