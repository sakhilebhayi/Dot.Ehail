// The fare breakdown as rows: one line per priced component, in the server's order, the total last. Pure, so it
// is tested in node and the same rows render in the booking sheet, the live ride and the public calculator.

import { formatMinor, spokenMinor } from './money.js';

/**
 * fare: the pricing API's FareBreakdown array {currency, components[{code,label,amount_minor,detail}], total{amount_minor},
 * demand_multiplier, tax_included, notes[]}. Returns {rows, total, adds_up, notes}.
 */
export function breakdownRows(fare) {
    const currency = fare?.currency || fare?.total?.currency || 'ZAR';
    const components = Array.isArray(fare?.components) ? fare.components : [];
    const rows = components.map((c) => ({
        code: c.code,
        label: c.label,
        detail: c.detail || null,
        amount_minor: c.amount_minor,
        text: formatMinor(c.amount_minor, currency),
        spoken: spokenMinor(c.amount_minor, currency),
        negative: c.amount_minor < 0,
    }));
    const totalMinor = fare?.total?.amount_minor ?? null;
    const sum = components.reduce((acc, c) => acc + (Number.isSafeInteger(c.amount_minor) ? c.amount_minor : 0), 0);
    const notes = [...(Array.isArray(fare?.notes) ? fare.notes : [])];

    if (fare?.demand_multiplier) {
        notes.unshift(`Busy-time multiplier ${fare.demand_multiplier} is included above as its own line.`);
    }

    if (fare?.tax_included?.amount_minor) {
        notes.push(`Includes tax of ${formatMinor(fare.tax_included.amount_minor, currency)}.`);
    }

    return {
        rows,
        total: totalMinor === null ? null : { amount_minor: totalMinor, text: formatMinor(totalMinor, currency), spoken: spokenMinor(totalMinor, currency) },
        // The server defines the total as the sum of its lines; the screen shows it only when that holds.
        adds_up: totalMinor !== null && sum === totalMinor,
        notes,
        currency,
    };
}

/** Distance and time as people say them: "5.0 km", "12 min", "1 h 05 min". */
export function formatKm(metres) {
    if (!Number.isFinite(metres)) return null;
    return metres < 1000 ? `${Math.round(metres / 10) * 10} m` : `${(metres / 1000).toFixed(1)} km`;
}

export function formatMinutes(seconds) {
    if (!Number.isFinite(seconds)) return null;
    const minutes = Math.max(1, Math.round(seconds / 60));
    if (minutes < 60) return `${minutes} min`;
    return `${Math.floor(minutes / 60)} h ${String(minutes % 60).padStart(2, '0')} min`;
}

/** Renders rows into a <dl class="pax-fare"> (or the given element). */
export function renderBreakdown(el, fare, { caption } = {}) {
    const model = breakdownRows(fare);
    const doc = el.ownerDocument;
    el.replaceChildren();

    for (const row of model.rows) {
        const item = doc.createElement('div');
        item.className = 'pax-fare__row' + (row.negative ? ' is-credit' : '');
        const dt = doc.createElement('dt');
        dt.textContent = row.label;
        if (row.detail) {
            const small = doc.createElement('span');
            small.className = 'pax-fare__detail';
            small.textContent = row.detail;
            dt.appendChild(small);
        }
        const dd = doc.createElement('dd');
        dd.className = 'eh-num';
        dd.textContent = row.text;
        if (row.negative) dd.setAttribute('aria-label', row.spoken);
        item.append(dt, dd);
        el.appendChild(item);
    }

    if (model.total) {
        const item = doc.createElement('div');
        item.className = 'pax-fare__row pax-fare__row--total';
        const dt = doc.createElement('dt');
        dt.textContent = caption || 'Total';
        const dd = doc.createElement('dd');
        dd.className = 'eh-num';
        dd.textContent = model.total.text;
        item.append(dt, dd);
        el.appendChild(item);
    }

    return model;
}
