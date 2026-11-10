// Tiny DOM helpers for the passenger screens: build elements, clone the kit's icons from a <template>, move the
// sheet, switch sheet steps with focus, and show the offline notice.

/** el('p', {class: 'x', onclick: fn}, ['text', child]) */
export function el(tag, attrs = {}, children = []) {
    const node = document.createElement(tag);
    for (const [name, value] of Object.entries(attrs || {})) {
        if (value === null || value === undefined || value === false) continue;
        if (name.startsWith('on') && typeof value === 'function') node.addEventListener(name.slice(2), value);
        else node.setAttribute(name, value === true ? '' : String(value));
    }
    for (const child of [].concat(children)) {
        if (child === null || child === undefined || child === false) continue;
        node.append(child instanceof Node ? child : document.createTextNode(String(child)));
    }
    return node;
}

/** A kit icon, cloned from <template id="pax-icons"> (the server draws the SVGs). */
export function icon(name) {
    const template = document.getElementById('pax-icons');
    const source = template?.content.querySelector(`[data-icon="${name}"] svg`);
    return source ? source.cloneNode(true) : document.createTextNode('');
}

const SNAP_LABELS = { peek: 'Expand panel', half: 'Expand panel to full height', full: 'Collapse panel' };

export function setSnap(sheet, snap) {
    if (!sheet || window.matchMedia('(min-width: 1024px)').matches) return;
    sheet.dataset.snap = snap;
    const handle = sheet.querySelector('[data-eh-sheet-handle]');
    handle?.setAttribute('aria-expanded', String(snap !== 'peek'));
    handle?.setAttribute('aria-label', SNAP_LABELS[snap] || SNAP_LABELS.peek);
}

/** Shows one [data-step] section, hides the others, and moves focus to its heading. */
export function showStep(sheet, name, { focus = true } = {}) {
    let target = null;
    sheet.querySelectorAll('[data-step]').forEach((section) => {
        const on = section.dataset.step === name;
        section.hidden = !on;
        if (on) target = section;
    });
    const heading = target?.querySelector('h2, [tabindex="-1"]');
    if (focus && heading) heading.focus({ preventScroll: false });
    sheet.querySelector('.eh-sheet__body')?.scrollTo?.({ top: 0 });
}

/** Keeps every given notice in step with the browser's online state. */
export function watchConnection(nodes) {
    const update = () => nodes.forEach((n) => (n.hidden = navigator.onLine !== false));
    window.addEventListener('online', update);
    window.addEventListener('offline', update);
    update();
}

/** Copy text, with a fallback for browsers without the async clipboard. */
export async function copyText(text, input = null) {
    try {
        await navigator.clipboard.writeText(text);
        return true;
    } catch {
        if (input) {
            input.select();
            return document.execCommand?.('copy') ?? false;
        }
        return false;
    }
}
