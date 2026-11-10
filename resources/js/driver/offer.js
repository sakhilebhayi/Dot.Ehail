// The offer card's words and figures, as pure functions (tested in tests/js/driver-offer.test.mjs). Every money line
// on the card comes from the server's split of the quoted fare; this file only orders them, names them and checks
// that they add up on screen: fare - what the platform keeps - commission = what the driver earns (D-2101).

import { formatMoney } from './geometry.js';

/** "20%", "12.5%", "7.25%" from basis points. */
export function percentText(bp) {
    if (!Number.isFinite(Number(bp))) return null;
    const p = Number(bp) / 100;

    return `${Number.isInteger(p) ? p : p.toFixed(2).replace(/0$/, '')}%`;
}

/** "20% of R54.00", "20% of R54.00 + R2.00", with the plan's floor or cap named when it bit. */
export function commissionDetail(data) {
    const rule = data.commission_rule;
    const base = data.commissionable_minor ?? (Number.isFinite(data.driver_gross_minor) ? data.driver_gross_minor - (data.tolls_minor || 0) : null);
    if (!rule || base === null || base === undefined) {
        // An older payload: the server's own wording, unchanged.
        return (data.deductions || []).find((d) => d.code === 'commission')?.detail ?? null;
    }
    const pct = percentText(rule.percent_bp);
    let text = `${pct} of ${formatMoney(base, data.currency)}`;
    if (rule.fixed_minor) text += ` + ${formatMoney(rule.fixed_minor, data.currency)}`;
    const plain = Math.round((base * rule.percent_bp) / 10000) + (rule.fixed_minor || 0);
    if (data.commission_minor !== null && data.commission_minor !== undefined && data.commission_minor !== plain) {
        if (rule.min_minor !== null && rule.min_minor !== undefined && data.commission_minor === rule.min_minor) text += `, raised to the plan's minimum`;
        else if (rule.max_minor !== null && rule.max_minor !== undefined && data.commission_minor === rule.max_minor) text += `, capped at the plan's maximum`;
    }

    return text;
}

/**
 * The lines of the offer's earnings, in reading order, each with a signed amount in minor units:
 *   Fare (estimate)                 R57.00
 *   Booking fee, kept by Dot.Ehail  -R3.00
 *   Commission, 20% of R54.00      -R10.80
 *   You earn                        R43.20   (total)
 * Tolls are part of the fare and come back to the driver whole, so they are a note on the fare line, not a sum line.
 * `reconciles` is false when the shown lines do not sum to the net (then the card says so instead of hiding it).
 */
export function offerLines(data) {
    const cur = data.currency || 'ZAR';
    const rows = [{ key: 'fare', label: 'Fare (estimate)', minor: data.fare_minor, note: data.tolls_minor ? `includes ${formatMoney(data.tolls_minor, cur)} tolls, paid back to you` : null }];
    if (!data.available) return { rows, net: null, reconciles: true, currency: cur };

    const kept = Array.isArray(data.kept_by_platform)
        ? data.kept_by_platform
        : Number.isFinite(data.driver_gross_minor) && Number.isFinite(data.fare_minor) && data.fare_minor !== data.driver_gross_minor
          ? [{ code: 'other', label: 'Fees', amount_minor: data.fare_minor - data.driver_gross_minor }]
          : [];
    kept.forEach((k) => rows.push({ key: `kept:${k.code}`, label: k.amount_minor < 0 ? `${k.label}, paid by Dot.Ehail` : `${k.label}, kept by Dot.Ehail`, minor: -k.amount_minor }));

    (data.deductions || []).forEach((d) => {
        if (d.code === 'commission') rows.push({ key: 'commission', label: 'Commission', detail: commissionDetail(data), minor: -d.amount_minor });
        else rows.push({ key: `deduction:${d.code}`, label: d.label, detail: d.detail || null, minor: -d.amount_minor });
    });

    const net = data.net_minor ?? null;
    const sum = rows.reduce((s, r) => s + (Number(r.minor) || 0), 0);
    rows.push({ key: 'net', label: 'You earn', minor: net, total: true });

    return { rows, net, reconciles: net === null || sum === net, currency: cur };
}

/** The passenger's rating as a driver reads it: the band, or how few ratings there are yet. */
export function ratingText(passenger) {
    const band = passenger?.rating_band;
    const count = Number.isFinite(passenger?.rating_count) ? passenger.rating_count : null;
    if (!band) return null;
    if (band === 'new') {
        if (count === 0) return 'no ratings yet';
        if (count !== null) return `${count} ${count === 1 ? 'rating' : 'ratings'}, too few for an average`;

        return 'fewer than 5 ratings';
    }
    if (band === 'below 4.0') return 'rated below 4.0';
    if (/^\d\.\d\+$/.test(band)) return `rated ${band.replace('+', '')} or higher`;
    const range = band.match(/^(\d\.\d)-(\d\.\d)$/);
    if (range) return `rated ${range[1]} to ${range[2]}`;

    return `rated ${band}`;
}
