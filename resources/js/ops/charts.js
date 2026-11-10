// Chart hover layer: every mark in an .ops-chart carries a transparent hit area with data-tip-value / data-tip-label;
// one tooltip follows the pointer and keyboard focus. The same values are in the table under each chart.
export function initCharts(root = document) {
    const hits = root.querySelectorAll('.ops-chart [data-tip-value]');
    if (!hits.length) return;
    const tip = document.createElement('div');
    tip.className = 'ops-tip';
    tip.hidden = true;
    tip.setAttribute('role', 'status');
    const value = document.createElement('strong');
    const label = document.createElement('span');
    tip.append(value, label);
    document.body.appendChild(tip);

    const show = (el, x, y) => {
        value.textContent = el.dataset.tipValue;
        label.textContent = el.dataset.tipLabel || '';
        tip.hidden = false;
        const w = tip.offsetWidth;
        tip.style.left = `${Math.min(window.innerWidth - w - 8, Math.max(8, x - w / 2))}px`;
        tip.style.top = `${Math.max(8, y - tip.offsetHeight - 12)}px`;
    };
    const hide = () => { tip.hidden = true; };

    hits.forEach((el) => {
        el.addEventListener('pointermove', (e) => show(el, e.clientX, e.clientY));
        el.addEventListener('pointerleave', hide);
        el.addEventListener('focus', () => {
            const r = el.getBoundingClientRect();
            show(el, r.left + r.width / 2, r.top);
        });
        el.addEventListener('blur', hide);
    });
}
