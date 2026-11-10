// Make a full-screen layer modal without <dialog> (R-46 a11y, D-1703): everything outside the layer becomes inert
// (no Tab stops, hidden from screen readers) until the returned restore() runs. Live regions and toasts stay
// reachable so announcements still speak. restore() also puts focus back where it was, or on `fallback`.
export function isolate(layer, { keep = '[aria-live], [data-eh-toasts], .eh-toasts, [data-eh-announcer]' } = {}) {
    const changed = [];
    const before = layer.ownerDocument.activeElement;
    for (let node = layer; node && node.parentElement; node = node.parentElement) {
        for (const sibling of node.parentElement.children) {
            if (sibling === node || sibling.inert || sibling.matches(keep)) continue;
            if (['SCRIPT', 'STYLE', 'TEMPLATE'].includes(sibling.tagName)) continue;
            sibling.inert = true;
            changed.push(sibling);
        }
        if (node.parentElement === layer.ownerDocument.body) break;
    }

    return function restore(fallback = null) {
        changed.forEach((el) => { el.inert = false; });
        changed.length = 0;
        const target = before && before.isConnected && !before.closest('[hidden]') && before !== layer.ownerDocument.body ? before : fallback;
        target?.focus?.({ preventScroll: true });
    };
}
