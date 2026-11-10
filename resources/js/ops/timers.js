// Live timers: <span class="ops-timer" data-ops-due="ISO" | data-ops-since="ISO" data-ops-warn="300" data-ops-label="">.
// Re-rendered every 15 s from the clock; the server-rendered text is the no-script fallback.
import { describeTimer } from './helpers.js';

export function initTimers(root = document) {
    const timers = [...root.querySelectorAll('[data-ops-due], [data-ops-since]')];
    if (!timers.length) return;
    const render = () => {
        const now = Date.now();
        timers.forEach((el) => {
            const { text, tone } = describeTimer({
                dueAt: el.dataset.opsDue || null,
                since: el.dataset.opsSince || null,
                now,
                warnSeconds: Number(el.dataset.opsWarn || 300),
                label: el.dataset.opsLabel || '',
            });
            if (text) { el.textContent = text; el.dataset.tone = tone; }
        });
    };
    render();
    setInterval(render, 15000);
}
