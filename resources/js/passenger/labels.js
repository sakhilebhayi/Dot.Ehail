// Words the passenger app shows, computed from API data. Pure (tested in node): no DOM here.

import { formatMinor } from './money.js';
import { displayTimezone, formatLocal, wallTimeToIso } from '../eh/time.js';

const ICONS = { car: 'car', 'car-comfort': 'car-sedan', 'car-premium': 'car-sedan', 'car-xl': 'car-xl', accessible: 'accessibility', plane: 'luggage', road: 'route' };

export function categoryIcon(iconKey) {
    return ICONS[iconKey] || 'car';
}

/** The pickup ETA line on a compare card, from the passenger app's /ride/api/pickup-etas answer or the quote's supply. */
export function etaText(entry, supply) {
    if (entry?.state === 'eta' && Number.isFinite(entry.eta_s)) {
        const minutes = Math.max(1, Math.round(entry.eta_s / 60));
        const estimate = entry.method && entry.method !== 'road';
        return { state: 'eta', text: `${estimate ? 'About ' : ''}${minutes} min to you${estimate ? ' (straight-line estimate)' : ''}` };
    }
    if (entry?.state === 'none' || supply === 'none') {
        return { state: 'none', text: 'No car of this kind nearby right now' };
    }
    if (supply === 'available') {
        return { state: 'unknown', text: 'Cars nearby; pickup time shown once one accepts' };
    }
    return { state: 'unknown', text: 'Nearby cars unknown until you request' };
}

/**
 * Payment choices for the request, from GET /money/wallet and GET /business/profiles. Each: {value, kind, ref, label,
 * meta, icon, disabled?, profile?}.
 * value is what the radio carries; kind/ref are what POST /api/ehail/rides receives.
 */
export function paymentOptions(wallet, categoryKey = null, profiles = []) {
    if (!wallet) return [];
    const options = [];
    const cardsOn = Boolean(wallet.card_payments?.available);

    if (cardsOn) {
        for (const method of wallet.methods || []) {
            if (method.type !== 'card' || !method.chargeable) continue;
            options.push({
                value: `card:${method.id}`, kind: 'card', ref: String(method.id), icon: 'card', label: method.label,
                meta: `Charged after the trip${method.is_default ? ', your default card' : ''}. Expires ${method.exp_month}/${String(method.exp_year).slice(-2)}.`,
                isDefault: Boolean(method.is_default),
            });
        }
        if (!options.length) {
            options.push({ value: 'checkout', kind: null, ref: null, icon: 'card', label: 'Card, paid after the trip', meta: 'You pay the final fare from the ride page when the trip ends.' });
        }
    }

    const cashCategories = wallet.cash?.categories || [];
    if (wallet.cash?.enabled && (!categoryKey || cashCategories.length === 0 || cashCategories.includes(categoryKey))) {
        options.push({ value: 'cash', kind: 'cash', ref: null, icon: 'cash', label: 'Cash', meta: 'Pay the driver the fare shown at the end of the trip.' });
    }

    // Business profiles come from GET /business/profiles (only people on a business account have any).
    for (const profile of profiles || []) {
        const account = profile.account || {};
        const left = profile.allowance?.left_minor;
        const meta = [
            account.bookable === false ? account.blocked_reason || 'This account cannot book rides right now.' : 'Billed to the company account.',
            profile.role_label || null,
            Number.isFinite(left) ? `${formatMinor(left, account.currency || 'ZAR')} of your monthly allowance left` : null,
        ].filter(Boolean).join(' ');
        options.push({
            value: `business:${profile.id}`, kind: 'business_account', ref: String(profile.id), icon: 'briefcase', label: account.name || 'Business account', meta,
            disabled: account.bookable === false, profile,
        });
    }

    return options;
}

/** The default choice: the default card, else the first option. */
export function defaultPayment(options) {
    return (options.find((o) => o.isDefault) || options.find((o) => !o.disabled) || null)?.value ?? null;
}

/** The request button's words: what it does and for how much. */
export function requestLabel(option, laterAt = null) {
    if (!option) return 'Choose a ride';
    const total = formatMinor(option.fare.total.amount_minor, option.fare.total.currency || option.fare.currency);
    if (laterAt) {
        // The picked wall time is a time in the service area (D-1906), shown back exactly as picked.
        const when = formatLocal(wallTimeToIso(laterAt) ?? laterAt, { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
        return `Book ${option.category.name} for ${when}, ${total}`;
    }
    return `Request ${option.category.name}, ${total}`;
}

/** Why a quote could not be priced, in the passenger's words (pricing's reason codes, docs/R46_PRICING.md section 5). */
export function unavailableText(result, routingMessage = null) {
    switch (result?.reason) {
        case 'routing_unavailable':
            return result?.detail === 'not_configured' || !result?.detail
                ? routingMessage || 'Route pricing is unavailable: the operator has not configured a routing provider.'
                : 'The road route could not be worked out just now, so no fare can be quoted. Try again in a minute.';
        case 'outside_service_area':
            return result.message || 'Dot.Ehail does not pick up there yet.';
        case 'no_tariff':
            return result.message || 'No fares are published for this area yet.';
        default:
            return result?.message || 'No ride can be priced for this trip right now.';
    }
}

/** datetime-local bounds for "Later", from dispatch's rules, as wall times in the service area (D-1906). */
export function laterBounds(rules, now = new Date()) {
    const min = new Date(now.getTime() + (rules.min_lead_minutes + 1) * 60000);
    const max = new Date(now.getTime() + rules.max_lead_days * 86400000);
    const local = (d) => {
        const p = Object.fromEntries(new Intl.DateTimeFormat('en-US', { timeZone: displayTimezone(), hourCycle: 'h23', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' }).formatToParts(d).map((x) => [x.type, x.value]));
        return `${p.year}-${p.month}-${p.day}T${String(Number(p.hour) % 24).padStart(2, '0')}:${p.minute}`;
    };
    return { min: local(min), max: local(max), minDate: min, maxDate: max };
}

export function laterError(value, rules, now = new Date()) {
    if (!value) return 'Choose a pickup date and time.';
    const at = new Date(wallTimeToIso(value) ?? value);
    if (Number.isNaN(at.getTime())) return 'Choose a pickup date and time.';
    const { minDate, maxDate } = laterBounds(rules, now);
    if (at < new Date(minDate.getTime() - 60000)) return `Pick a time at least ${rules.min_lead_minutes} minutes from now.`;
    if (at > maxDate) return `Pick a time within ${rules.max_lead_days} days.`;
    return null;
}

/** The business refusal as one sentence plus its reasons (business API: {error: business_<code>, message, reasons[]}). */
export function businessReasons(body) {
    const all = (body?.reasons || []).map((r) => (typeof r === 'string' ? r : r?.message)).filter(Boolean);
    const message = body?.message || all[0] || 'The company policy does not allow this ride.';
    return { message, reasons: all.filter((r) => r !== message) };
}
