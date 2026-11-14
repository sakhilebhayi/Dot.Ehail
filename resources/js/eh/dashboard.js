// Dashboard figure reveal (R-51): the counts on /dashboard run up to their TRUE value once, over ~400 ms, then stay.
// The server-rendered number is already the final text, so without this script (or with reduced motion) nothing
// changes. Never runs on a live screen: it only touches elements marked data-count inside .eh-dash.
export function initDashboardCounts() {
    const root = document.querySelector('.eh-dash');
    if (!root || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

    const nodes = [...root.querySelectorAll('[data-count]')].filter((el) => /^\d+$/.test(el.dataset.count ?? '') && Number(el.dataset.count) > 1);
    if (!nodes.length) return;

    const formatter = new Intl.NumberFormat('en-ZA');
    const started = performance.now() + 260;
    const duration = 420;
    const ease = (t) => 1 - Math.pow(1 - t, 3);

    // Hold each figure's width so the count never shifts the layout.
    nodes.forEach((el) => { el.style.minWidth = `${el.getBoundingClientRect().width}px`; });

    const frame = (now) => {
        const t = Math.min(Math.max((now - started) / duration, 0), 1);
        nodes.forEach((el) => {
            const target = Number(el.dataset.count);
            el.textContent = formatter.format(t >= 1 ? target : Math.round(target * ease(t))).replace(/ /g, ' ');
        });
        if (t < 1) requestAnimationFrame(frame);
        else nodes.forEach((el) => { el.textContent = el.dataset.final; el.style.minWidth = ''; });
    };

    nodes.forEach((el) => { el.dataset.final = el.textContent.trim(); });
    requestAnimationFrame(frame);
}
