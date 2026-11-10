// Bottom sheet with snap points: peek, half, full. The handle is a real button: click or Enter cycles up,
// ArrowUp/ArrowDown move one snap, Home/End jump, Escape returns to peek. Pointer drag snaps to the nearest point.
// Reduced motion: the CSS drops the transition; nothing here animates on its own.

export const SNAPS = ['peek', 'half', 'full'];

export function nextSnap(current, direction) {
    const i = Math.max(0, SNAPS.indexOf(current));
    if (direction === 'up') return SNAPS[Math.min(SNAPS.length - 1, i + 1)];
    if (direction === 'down') return SNAPS[Math.max(0, i - 1)];
    if (direction === 'cycle') return SNAPS[(i + 1) % SNAPS.length];
    return current;
}

// Nearest snap for a sheet whose top edge sits `visible` px above the bottom of a `height` px tall sheet.
export function nearestSnap(visible, height, peek) {
    const points = { peek, half: height / 2, full: height };
    return Object.entries(points).reduce((best, [name, px]) => (Math.abs(px - visible) < Math.abs(points[best] - visible) ? name : best), 'peek');
}

const LABELS = { peek: 'Expand panel', half: 'Expand panel to full height', full: 'Collapse panel' };

export function initSheets() {
    document.querySelectorAll('[data-eh-sheet]').forEach((sheet) => {
        const handle = sheet.querySelector('[data-eh-sheet-handle]');
        if (!handle) return;
        const allowed = (sheet.dataset.snaps || SNAPS.join(',')).split(',');
        const set = (snap) => {
            if (!allowed.includes(snap)) snap = snap === 'full' && allowed.includes('half') ? 'half' : 'peek';
            sheet.dataset.snap = snap;
            handle.setAttribute('aria-expanded', String(snap !== 'peek'));
            handle.setAttribute('aria-label', LABELS[snap]);
            sheet.dispatchEvent(new CustomEvent('eh:sheet', { detail: { snap }, bubbles: true }));
        };
        set(sheet.dataset.snap || 'peek');

        handle.addEventListener('click', () => {
            if (handle.dataset.dragged === '1') { handle.dataset.dragged = ''; return; }
            set(nextSnap(sheet.dataset.snap, sheet.dataset.snap === 'full' ? 'cycle' : 'up'));
        });
        handle.addEventListener('keydown', (e) => {
            const map = { ArrowUp: 'up', ArrowDown: 'down' };
            if (map[e.key]) { e.preventDefault(); set(nextSnap(sheet.dataset.snap, map[e.key])); }
            if (e.key === 'Home') { e.preventDefault(); set('peek'); }
            if (e.key === 'End') { e.preventDefault(); set('full'); }
        });
        sheet.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && sheet.dataset.snap !== 'peek') { set('peek'); handle.focus(); }
        });

        let startY = 0; let startVisible = 0; let dragging = false;
        const visibleNow = () => window.innerHeight - sheet.getBoundingClientRect().top;
        handle.addEventListener('pointerdown', (e) => {
            dragging = true; startY = e.clientY; startVisible = visibleNow();
            handle.setPointerCapture(e.pointerId);
            sheet.dataset.dragging = 'true';
        });
        handle.addEventListener('pointermove', (e) => {
            if (!dragging) return;
            const height = sheet.offsetHeight;
            const visible = Math.min(height, Math.max(48, startVisible + (startY - e.clientY)));
            sheet.style.transform = `translateY(${height - visible}px)`;
        });
        const end = (e) => {
            if (!dragging) return;
            dragging = false;
            sheet.dataset.dragging = 'false';
            const moved = Math.abs(startY - e.clientY) > 6;
            const peek = parseFloat(getComputedStyle(sheet).getPropertyValue('--eh-sheet-peek')) || 168;
            const snap = nearestSnap(visibleNow(), sheet.offsetHeight, peek);
            sheet.style.transform = '';
            if (moved) { handle.dataset.dragged = '1'; set(snap); }
        };
        handle.addEventListener('pointerup', end);
        handle.addEventListener('pointercancel', end);
    });
}
