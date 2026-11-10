// Passenger booking (/ride): pickup and destination, the pin, compare every category with its full fare,
// promo, payment and the request. The sheet works before the map; the map engine loads after first paint.

import { createPlaceSearch, loadGeoConfig, locateOnce, locationPermission, mountMap } from '../geo/index.js';
import { announce, api, ApiError, idempotencyKey, island } from './api.js';
import { formatKm, formatMinutes, renderBreakdown } from './breakdown.js';
import { clock, quoteState } from './countdown.js';
import { countdownCue } from '../eh/announce.js';
import { businessReasons, categoryIcon, defaultPayment, etaText, laterBounds, laterError, paymentOptions, requestLabel, unavailableText } from './labels.js';
import { formatMinor } from './money.js';
import { el, icon, setSnap, showStep, watchConnection } from './dom.js';

const data = island('pax-booking-data') || {};
const stage = document.querySelector('[data-pax-booking]');
const sheet = document.getElementById('pax-book');
const frame = document.getElementById('pax-map');
const pinEl = stage?.querySelector('[data-pax-pin]');
const body = sheet?.querySelector('[data-pax-options-body]');
const seePrices = sheet?.querySelector('[data-pax-see-prices]');
const adjustBtn = sheet?.querySelector('[data-pax-adjust]');

const state = {
    pickup: null,
    dropoff: null,
    when: 'now',
    laterAt: null,
    compare: null,
    etas: null,
    wallet: null,
    selected: null,
    payment: null,
    promo: '',
    key: null,
    keyFor: null,
    submitting: false,
    pinPoint: null,
    places: data.places || [],
    profiles: null,
    business: { purpose: '', status: null, approvalId: null },
};

let config = null;
let map = null;
let searches = {};
let quoteTimer = null;

const urlFor = (template, id) => template.replace('__ID__', String(id));

// ------------------------------------------------------------------ map
async function startMap() {
    try {
        map = await mountMap(frame.querySelector('[data-eh-map-canvas]'), {
            config,
            center: state.pickup || undefined,
            zoom: state.pickup ? 15 : undefined,
            onMapClick: (point) => placeFromMap(point),
        });
        frame.dataset.mapState = 'ready';
        map.map.on('moveend', onMapMoved);
        drawPoints();
    } catch {
        frame.dataset.mapState = 'unavailable';
        const msg = frame.querySelector('[data-eh-map-message] span');
        if (msg) msg.textContent = 'The map could not load on this connection. Search still works.';
        adjustBtn?.setAttribute('disabled', '');
    }
}

function drawPoints() {
    if (!map) return;
    map.setPickup(state.pickup);
    map.setDestination(state.dropoff);
    fitVisible();
}

/** Fit the stops into the part of the map the sheet does not cover. */
function fitVisible() {
    if (!map) return;
    const points = [state.pickup, state.dropoff].filter(Boolean);
    if (!points.length) return;
    const wide = window.matchMedia('(min-width: 1024px)').matches;
    const sheetCover = wide ? 0 : Math.max(0, window.innerHeight - sheet.getBoundingClientRect().top);
    const options = { paddingTopLeft: [wide ? 448 : 32, 48], paddingBottomRight: [32, sheetCover + 32], maxZoom: 16, animate: !matchMedia('(prefers-reduced-motion: reduce)').matches };
    if (points.length === 1) {
        map.map.setView([points[0].lat, points[0].lng], 16, { animate: options.animate });
    } else {
        map.map.fitBounds(points.map((p) => [p.lat, p.lng]), options);
    }
}

async function reverseLabel(point) {
    try {
        const { data: answer } = await api(`${data.urls.reverse}?lat=${point.lat.toFixed(6)}&lng=${point.lng.toFixed(6)}`);
        return answer?.result?.label || null;
    } catch {
        return null;
    }
}

async function placeFromMap(point) {
    if (stage.dataset.pinning === 'true') return;
    const target = !state.pickup ? 'pickup' : 'dropoff';
    const place = { label: 'Pinned on the map', lat: point.lat, lng: point.lng };
    target === 'pickup' ? setPickup(place) : setDropoff(place);
    const label = await reverseLabel(point);
    if (label) {
        place.label = label;
        target === 'pickup' ? setPickup(place) : setDropoff(place);
    }
}

// ------------------------------------------------------------------ pickup and destination
function setPickup(place, { fit = true } = {}) {
    state.pickup = place ? { label: place.label, lat: Number(place.lat), lng: Number(place.lng) } : null;
    searches.pickup?.setValue(place?.label || '');
    adjustBtn?.toggleAttribute('disabled', !state.pickup || !map);
    map?.setPickup(state.pickup);
    if (fit) fitVisible();
    readiness();
}

function setDropoff(place, { fit = true } = {}) {
    state.dropoff = place ? { label: place.label, lat: Number(place.lat), lng: Number(place.lng) } : null;
    searches.dropoff?.setValue(place?.label || '');
    map?.setDestination(state.dropoff);
    if (fit) fitVisible();
    readiness();
    if (state.pickup && state.dropoff) {
        announce(`Destination set: ${state.dropoff.label}. Press See prices to compare rides.`);
        setSnap(sheet, 'half');
        // Fit once the sheet has settled, so the stops land in the part of the map left visible.
        setTimeout(fitVisible, 380);
        seePrices?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }
}

function readiness() {
    const ready = Boolean(state.pickup && state.dropoff);
    const laterOk = state.when === 'now' || !laterError(state.laterAt, data.schedule);
    seePrices?.toggleAttribute('disabled', !(ready && laterOk));
    const hint = sheet.querySelector('[data-pax-where-hint]');
    if (hint) {
        hint.textContent = !state.pickup
            ? 'Set your pickup: use your location, search, or tap the map.'
            : !state.dropoff
              ? 'Now your destination: search, pick a saved place, or tap the map.'
              : !laterOk
                ? laterError(state.laterAt, data.schedule)
                : 'You will see every ride type with its full fare before you request.';
    }
}

async function locate(button, { quiet = false } = {}) {
    const status = sheet.querySelector('[data-pax-locate-status]');
    button?.setAttribute('aria-busy', 'true');
    if (!quiet) status.textContent = 'Finding you. Your position is used for this pickup only and is not stored.';
    try {
        const fix = await locateOnce({ timeoutMs: 12000 });
        status.textContent = `Located to within ${Math.round(fix.accuracy)} m. Check the pin is where the driver should stop.`;
        status.dataset.kind = '';
        setPickup({ label: 'Your location', lat: fix.lat, lng: fix.lng });
        map?.showAccuracy(fix);
        const label = await reverseLabel(fix);
        if (label && state.pickup && state.pickup.lat === fix.lat) setPickup({ label, lat: fix.lat, lng: fix.lng }, { fit: false });
        if (!quiet && map && fix.accuracy > 60) startPin();
    } catch (error) {
        if (!quiet) {
            status.textContent = error.message || 'Your position could not be found. Search for the address instead.';
            status.dataset.kind = 'error';
        } else {
            status.textContent = '';
        }
    } finally {
        button?.removeAttribute('aria-busy');
    }
}

// ------------------------------------------------------------------ the pin
function startPin() {
    if (!map || !state.pickup) return;
    state.pinPoint = { ...state.pickup };
    stage.dataset.pinning = 'true';
    pinEl.hidden = false;
    showStep(sheet, 'pin');
    setSnap(sheet, 'peek');
    // The pin sits above the sheet: centre the pickup there.
    map.map.setView([state.pickup.lat, state.pickup.lng], 17, { animate: false });
    map.setPickup(null);
    updatePinAddress(state.pickup.label);
}

function updatePinAddress(text) {
    const out = sheet.querySelector('[data-pax-pin-address]');
    if (out) out.textContent = text;
}

let pinLookup = null;
function onMapMoved() {
    if (stage.dataset.pinning !== 'true') return;
    const centre = pinCentre();
    state.pinPoint = { label: 'Pinned on the map', lat: centre.lat, lng: centre.lng };
    updatePinAddress('Finding the address');
    clearTimeout(pinLookup);
    // Reverse lookups are rate limited (public geocoder: one per second for the whole app); wait for the map to settle.
    pinLookup = setTimeout(async () => {
        const label = await reverseLabel(centre);
        if (state.pinPoint && state.pinPoint.lat === centre.lat) {
            state.pinPoint.label = label || 'Pinned on the map';
            updatePinAddress(state.pinPoint.label);
        }
    }, 900);
}

/** The map point under the pin's tip (the pin is drawn above the sheet, not at the map's centre, on phones). */
function pinCentre() {
    const rect = pinEl.getBoundingClientRect();
    const mapRect = frame.getBoundingClientRect();
    const point = map.map.containerPointToLatLng([rect.left + rect.width / 2 - mapRect.left, rect.bottom - mapRect.top]);
    return { lat: point.lat, lng: point.lng };
}

function endPin(confirm) {
    stage.dataset.pinning = 'false';
    pinEl.hidden = true;
    if (confirm && state.pinPoint) setPickup(state.pinPoint);
    else map?.setPickup(state.pickup);
    showStep(sheet, 'where');
    setSnap(sheet, 'half');
}

// ------------------------------------------------------------------ compare
async function loadOptions({ keepSelection = true } = {}) {
    showStep(sheet, 'options');
    setSnap(sheet, 'full');
    sheet.querySelector('[data-pax-sum="pickup"]').textContent = state.pickup.label;
    sheet.querySelector('[data-pax-sum="dropoff"]').textContent = state.dropoff.label;
    body.setAttribute('aria-busy', 'true');
    body.replaceChildren(el('div', { class: 'eh-skeleton', role: 'status' }, [el('span', { class: 'eh-sr-only' }, 'Pricing your trip'), ...[1, 2, 3].map(() => el('div', { class: 'eh-skeleton__bar' }))]));
    clearInterval(quoteTimer);

    const points = { pickup_lat: state.pickup.lat, pickup_lng: state.pickup.lng, dropoff_lat: state.dropoff.lat, dropoff_lng: state.dropoff.lng };
    const quoteCall = api(data.urls.quotes, { method: 'POST', body: { ...points, promo_code: state.promo || undefined } });
    const walletCall = state.wallet ? Promise.resolve({ data: state.wallet }) : api(data.urls.wallet);
    const profileCall = state.profiles !== null || !data.urls.profiles ? Promise.resolve({ data: { profiles: state.profiles || [] } }) : api(data.urls.profiles).catch(() => ({ data: { profiles: [] } }));
    const etaCall = api(data.urls.etas, { method: 'POST', body: { lat: state.pickup.lat, lng: state.pickup.lng } }).catch(() => null);
    drawRoute(points);

    try {
        const [{ data: compare }, wallet, etas, profiles] = await Promise.all([quoteCall, walletCall.catch(() => null), etaCall, profileCall]);
        state.profiles = profiles?.data?.profiles || [];
        state.business.status = null;
        state.compare = compare;
        state.wallet = wallet?.data || null;
        state.etas = etas?.data?.categories || null;
        state.key = null;
        if (!keepSelection || !compare.options?.some((o) => o.category.key === state.selected)) {
            state.selected = compare.options?.[0]?.category.key || null;
        }
        renderOptions();
    } catch (error) {
        renderProblem(error instanceof ApiError ? error.message : 'Prices could not load. Try again.', true);
    } finally {
        body.removeAttribute('aria-busy');
    }
}

async function drawRoute(points) {
    if (!map || !config?.routing?.configured) return;
    try {
        const q = new URLSearchParams({ from_lat: points.pickup_lat, from_lng: points.pickup_lng, to_lat: points.dropoff_lat, to_lng: points.dropoff_lng });
        const { data: answer } = await api(`${data.urls.route}?${q}`);
        const polyline = answer?.route?.polyline;
        if (polyline) {
            map.drawRoute(polyline);
            fitVisible();
        }
    } catch {
        // The fare does not depend on drawing the line; pricing reports routing problems itself.
    }
}

function renderProblem(message, retry) {
    body.replaceChildren(
        el('div', { class: 'eh-banner eh-banner--caution', role: 'alert' }, [
            icon('warning'),
            el('div', { class: 'eh-banner__body' }, [
                el('p', { class: 'eh-banner__title' }, 'No prices for this trip'),
                el('div', { class: 'eh-banner__text' }, message),
                retry ? el('div', { class: 'eh-banner__actions' }, [el('button', { type: 'button', class: 'eh-btn eh-btn--secondary eh-btn--sm', onclick: () => loadOptions() }, [icon('refresh'), el('span', { class: 'eh-btn__label' }, 'Try again')])]) : null,
            ]),
        ]),
    );
}

function renderOptions() {
    const compare = state.compare;

    if (!compare || compare.available === false) {
        renderProblem(unavailableText(compare, config?.routing?.unavailableMessage), compare?.reason !== 'outside_service_area');
        return;
    }

    if (!compare.options?.length) {
        renderProblem(compare.unavailable?.[0]?.message || 'No ride type is available for this trip right now.', true);
        return;
    }

    const children = [];
    const route = compare.route || compare.options[0].route;
    children.push(
        el('p', { class: 'pax-trip' }, [
            el('span', { class: 'pax-trip__figure' }, formatKm(route.distance_m)),
            el('span', { class: 'pax-trip__figure' }, formatMinutes(route.duration_s)),
            el('span', { class: 'pax-trip__note' }, 'Estimated road distance and driving time for this route.'),
        ]),
    );

    if (compare.options.some((o) => o.tariff?.is_example)) {
        children.push(el('p', { class: 'pax-note' }, [icon('info'), 'Example tariff: prices are placeholders until the operator publishes real rates']));
    }

    const list = el('fieldset', { class: 'pax-options' }, [el('legend', {}, 'Ride types with their full price')]);
    for (const option of compare.options) list.appendChild(optionCard(option));
    children.push(list);

    if (compare.unavailable?.length) {
        children.push(el('ul', { class: 'pax-unavailable', 'aria-label': 'Not available right now' }, compare.unavailable.map((u) => el('li', {}, [icon('info'), el('span', {}, u.message)]))));
    }

    // A timer role: the clock ticks every second and is never read aloud on its own (R-46 a11y, D-1702).
    children.push(el('p', { class: 'pax-quote', 'data-pax-quote': '', role: 'timer', 'aria-label': 'Prices held' }));
    children.push(promoBlock());
    children.push(paymentBlock());
    if (data.paymentDue?.blocked) children.push(dueBlock(data.paymentDue));
    children.push(requestBar());

    body.replaceChildren(...children);
    quoteSaid = null;
    tickQuote();
    quoteTimer = setInterval(tickQuote, 1000);
    // One sentence instead of reading the whole list: how many rides, and how long the prices hold.
    const count = compare.options?.length || 0;
    const held = Math.round((quoteState(compare.options?.map((o) => o.expires_at).filter(Boolean).sort()[0]).left || 0) / 60);
    if (count) announce(`${count} ${count === 1 ? 'ride' : 'rides'} priced. The first is selected.${held ? ` Prices held for ${held} min.` : ''}`);
    updateRequest();
}

function optionCard(option) {
    const key = option.category.key;
    const id = `pax-opt-${key}`;
    const eta = etaText(state.etas?.[key], option.supply);
    const detailId = `${id}-detail`;
    const detail = el('div', { class: 'pax-option__detail', id: detailId, hidden: '' });
    const fareList = el('dl', { class: 'pax-fare' });
    const model = renderBreakdown(fareList, option.fare, { caption: `Total for ${option.category.name}` });
    const notes = model.notes.map((n) => el('p', {}, n));
    notes.push(el('p', {}, option.wording));
    if (option.promotion_note) notes.push(el('p', {}, option.promotion_note));
    if (!model.adds_up) notes.push(el('p', {}, 'These lines do not add up to the total, so this price is not shown as final. Refresh prices.'));
    detail.append(fareList, el('div', { class: 'pax-fare-notes' }, notes));

    const more = el('button', { type: 'button', class: 'pax-option__more', 'aria-expanded': 'false', 'aria-controls': detailId }, ['Fare breakdown', icon('chevron-down')]);
    more.addEventListener('click', (event) => {
        event.preventDefault();
        const open = more.getAttribute('aria-expanded') !== 'true';
        more.setAttribute('aria-expanded', String(open));
        detail.hidden = !open;
    });

    const input = el('input', { class: 'pax-option__pick', type: 'radio', name: 'pax-category', value: key, id, 'aria-describedby': `${id}-meta` });
    input.checked = key === state.selected;
    input.addEventListener('change', () => {
        state.selected = key;
        state.key = null;
        state.business.status = null;
        renderPayment();
        updateRequest();
    });

    return el('div', { class: 'pax-option' }, [
        input,
        el('label', { class: 'pax-option__name', for: id }, [icon(categoryIcon(option.category.icon_key)), option.category.name]),
        el('span', { class: 'pax-option__total eh-num' }, model.total?.text || ''),
        el('p', { class: 'pax-option__meta', id: `${id}-meta`, style: 'margin:0' }, [
            el('span', { class: 'pax-option__eta', 'data-state': eta.state }, eta.text),
            el('span', {}, `${option.category.capacity} seats`),
            option.category.luggage ? el('span', {}, `${option.category.luggage} bags`) : null,
            option.promotion ? el('span', { class: 'eh-badge eh-badge--go' }, [icon('tag'), option.promotion.name]) : null,
        ]),
        more,
        detail,
    ]);
}

let quoteSaid = null;
function tickQuote() {
    const out = body.querySelector('[data-pax-quote]');
    if (!out || !state.compare?.options?.length) return;
    const expires = state.compare.options.map((o) => o.expires_at).filter(Boolean).sort()[0];
    const { state: q, left } = quoteState(expires);
    out.dataset.state = q;
    if (q === 'expired') {
        clearInterval(quoteTimer);
        out.replaceChildren(el('span', {}, 'These prices have expired.'), el('button', { type: 'button', class: 'eh-btn eh-btn--secondary eh-btn--sm', onclick: () => loadOptions() }, [icon('refresh'), el('span', { class: 'eh-btn__label' }, 'Refresh prices')]));
        announce('The prices expired. Refresh prices to request.');
        updateRequest();
        return;
    }
    if (countdownCue(quoteSaid, left, [60])) announce('Prices held for 1 more minute.');
    quoteSaid = left;
    if (!out.firstChild || out.dataset.built !== '1') {
        out.dataset.built = '1';
        out.replaceChildren(el('span', {}, ['Prices held for ', el('strong', { class: 'pax-quote__clock' }, clock(left))]), el('span', {}, 'You pay this price unless the trip changes for a reason listed in the fare rules.'));
    } else {
        out.querySelector('.pax-quote__clock').textContent = clock(left);
    }
}

// ------------------------------------------------------------------ promo, payment, payment due
function promoBlock() {
    const input = el('input', { class: 'eh-input', id: 'pax-promo', name: 'promo', autocomplete: 'off', maxlength: '40', value: state.promo });
    const apply = el('button', { type: 'submit', class: 'eh-btn eh-btn--secondary eh-btn--md' }, el('span', { class: 'eh-btn__label' }, 'Apply'));
    const form = el('form', { class: 'pax-promo__row' }, [input, apply]);
    form.addEventListener('submit', (event) => {
        event.preventDefault();
        state.promo = input.value.trim().toUpperCase();
        loadOptions();
    });
    const note = state.compare?.options?.find((o) => o.promotion_note)?.promotion_note;
    return el('details', { class: 'pax-section pax-promo', open: state.promo ? '' : null }, [
        el('summary', {}, state.promo ? `Promo code ${state.promo}` : 'Add a promo code'),
        el('label', { class: 'eh-sr-only', for: 'pax-promo' }, 'Promo code'),
        form,
        note ? el('p', { class: 'pax-rule', role: 'status', style: 'margin-top: var(--eh-space-2)' }, note) : null,
    ]);
}

function paymentBlock() {
    return el('div', { class: 'pax-section', 'data-pax-payment': '' });
}

function renderPayment() {
    const host = body.querySelector('[data-pax-payment]');
    if (!host) return;
    const wallet = state.wallet;

    if (!wallet) {
        host.replaceChildren(el('p', { class: 'pax-rule' }, 'Payment methods could not load. Your default card will be used if you have one.'));
        return;
    }

    const options = paymentOptions(wallet, state.selected, state.profiles);
    if (!options.some((o) => o.value === state.payment && !o.disabled)) state.payment = defaultPayment(options);

    const set = el('fieldset', { class: 'pax-pay' }, [el('legend', {}, 'Pay with')]);
    if (!options.length) {
        set.appendChild(el('p', { class: 'pax-rule' }, wallet.card_payments?.notice || 'Rides cannot be paid for right now: card payments and cash are both switched off.'));
    }
    for (const option of options) {
        const radio = el('input', { type: 'radio', name: 'pax-payment', value: option.value, disabled: option.disabled || null });
        radio.checked = option.value === state.payment;
        radio.addEventListener('change', () => {
            state.payment = option.value;
            state.key = null;
            state.business.status = null;
            renderBusiness();
            updateRequest();
        });
        set.appendChild(el('label', { class: 'pax-pay__opt' }, [radio, icon(option.icon), el('span', { class: 'pax-pay__text' }, [el('span', {}, option.label), el('span', { class: 'pax-pay__meta' }, option.meta)])]));
    }

    const extras = [];
    if (wallet.card_payments?.available) {
        const add = el('button', { type: 'button', class: 'eh-btn eh-btn--ghost eh-btn--sm' }, [icon('plus'), el('span', { class: 'eh-btn__label' }, 'Add a card')]);
        add.addEventListener('click', () => addCard(add));
        extras.push(add);
        extras.push(el('p', { class: 'pax-rule' }, `Adding a card makes a ${formatMinor(wallet.card_setup_charge_minor || 100)} check that is refunded straight away.${wallet.card_payments.test_mode ? ' Payments are in test mode.' : ''}`));
    }
    host.replaceChildren(set, el('div', { 'data-pax-business': '' }), ...extras);
    renderBusiness();
}

// ------------------------------------------------------------------ business rides (docs/R46_BUSINESS.md section 5)
function chosenPayment() {
    return paymentOptions(state.wallet, state.selected, state.profiles || []).find((o) => o.value === state.payment) || null;
}

/** The company's checks for this quote: allowed, needs approval (ask for it), or refused with reasons. */
function renderBusiness() {
    const host = body.querySelector('[data-pax-business]');
    if (!host) return;
    const choice = chosenPayment();
    if (!choice?.profile) {
        host.replaceChildren();
        return;
    }
    const profile = choice.profile;
    const needsPurpose = Boolean(profile.needs_purpose || profile.policy?.purpose_required);
    const purpose = el('input', { class: 'eh-input', id: 'pax-biz-purpose', maxlength: '120', value: state.business.purpose, placeholder: 'Client meeting, airport, site visit' });
    purpose.addEventListener('change', () => {
        state.business.purpose = purpose.value.trim();
        state.business.status = null;
        prepareBusiness();
    });
    const status = el('div', { class: 'pax-section', 'data-pax-biz-status': '', role: 'status' });
    host.replaceChildren(
        el('div', { class: 'eh-field pax-section' }, [el('label', { class: 'eh-field__label', for: 'pax-biz-purpose' }, ['Purpose of the trip', needsPurpose ? null : el('span', { class: 'eh-field__optional' }, ' optional')]), purpose]),
        status,
    );
    if (!state.business.status) prepareBusiness();
    else drawBusinessStatus();
}

let prepareSeq = 0;
async function prepareBusiness() {
    const choice = chosenPayment();
    const option = selectedOption();
    if (!choice?.profile || !option) return;
    const mine = ++prepareSeq;
    state.business.status = { state: 'checking' };
    drawBusinessStatus();
    updateRequest();
    try {
        const body = { profile_id: choice.profile.id, quote_id: option.quote_id, purpose: state.business.purpose || undefined, approval_id: state.business.approvalId || undefined };
        if (state.when === 'later') body.scheduled_for = new Date(state.laterAt).toISOString();
        const { data: checked } = await api(data.urls.businessPrepare, { method: 'POST', body });
        if (mine !== prepareSeq) return;
        state.business.status = checked.allowed ? { state: 'allowed' } : checked.needs_approval ? { state: 'approval', reasons: checked.reasons, approval: checked.approval } : { state: 'refused', ...businessReasons({ reasons: checked.reasons }) };
        if (checked.approval?.id && checked.approval.status === 'approved') state.business.approvalId = checked.approval.id;
    } catch (failure) {
        if (mine !== prepareSeq) return;
        state.business.status = { state: 'refused', ...businessReasons(failure.body || { message: failure.message }) };
    }
    drawBusinessStatus();
    updateRequest();
}

function drawBusinessStatus() {
    const host = body.querySelector('[data-pax-biz-status]');
    const st = state.business.status;
    if (!host || !st) return;
    const list = (items) => (items?.length ? el('ul', { class: 'pax-unavailable' }, items.map((r) => el('li', {}, typeof r === 'string' ? r : r.message))) : null);
    if (st.state === 'checking') host.replaceChildren(el('p', { class: 'pax-rule' }, 'Checking the company policy for this ride.'));
    else if (st.state === 'allowed') host.replaceChildren(el('p', { class: 'pax-rule' }, [icon('info'), ' Within the company policy. The fare is billed to the account.']));
    else if (st.state === 'approval') {
        const pending = st.approval && st.approval.status === 'pending';
        const ask = el('button', { type: 'button', class: 'eh-btn eh-btn--secondary eh-btn--sm', disabled: pending || null }, el('span', { class: 'eh-btn__label' }, pending ? 'Approval requested' : 'Ask for approval'));
        ask.addEventListener('click', () => askApproval(ask));
        host.replaceChildren(el('p', { class: 'pax-note' }, [icon('info'), pending ? 'Waiting for an approver. You can request the ride once it is approved; this page checks again when you refresh prices.' : 'This ride needs approval from your company before you can request it.']), list(st.reasons), ask);
    } else {
        host.replaceChildren(el('div', { class: 'eh-banner eh-banner--caution', role: 'alert' }, [icon('warning'), el('div', { class: 'eh-banner__body' }, [el('p', { class: 'eh-banner__title' }, st.message), list(st.reasons)])]));
    }
}

async function askApproval(button) {
    const choice = chosenPayment();
    const option = selectedOption();
    button.disabled = true;
    button.setAttribute('aria-busy', 'true');
    try {
        const { data: made } = await api(data.urls.businessApprovals, { method: 'POST', body: { profile_id: choice.profile.id, quote_id: option.quote_id, purpose: state.business.purpose || undefined } });
        state.business.status = { ...state.business.status, approval: made.approval || { status: 'pending' } };
        announce('Approval requested. You will be able to request the ride once it is approved.');
    } catch (failure) {
        state.business.status = { state: 'refused', ...businessReasons(failure.body || { message: failure.message }) };
    }
    drawBusinessStatus();
}

async function addCard(button) {
    button.setAttribute('aria-busy', 'true');
    button.disabled = true;
    try {
        const { data: started } = await api(data.urls.addCard, { method: 'POST' });
        window.location.assign(started.authorization_url);
    } catch (error) {
        button.removeAttribute('aria-busy');
        button.disabled = false;
        window.ehToast?.(error.message, { tone: 'stop' });
    }
}

function dueBlock(due) {
    const card = (state.wallet?.methods || []).find((m) => m.type === 'card' && m.chargeable && m.is_default) || (state.wallet?.methods || []).find((m) => m.type === 'card' && m.chargeable);
    const rows = (due.rides || []).map((owed) => {
        const amount = formatMinor(owed.amount_minor, owed.currency);
        const what = owed.kind === 'cancellation_fee' ? `Cancellation fee, ride ${owed.ride_id}` : `Fare for ride ${owed.ride_id}`;
        const action = owed.kind === 'fare' && card
            ? el('button', { type: 'button', class: 'eh-btn eh-btn--primary eh-btn--sm', onclick: (event) => payNow(event.currentTarget, owed.ride_id, card.id) }, el('span', { class: 'eh-btn__label' }, `Pay ${amount} with ${card.label}`))
            : el('a', { class: 'eh-link', href: data.urls.trips }, owed.kind === 'fare' ? 'Add a card, then pay from your trips' : 'Ask support about this fee');
        return el('li', { style: 'display:grid;gap:var(--eh-space-2)' }, [el('span', {}, [el('strong', {}, amount), ` ${what}`]), action]);
    });
    return el('div', { class: 'eh-banner eh-banner--stop pax-section', role: 'alert', 'data-pax-due': '' }, [
        icon('warning'),
        el('div', { class: 'eh-banner__body' }, [
            el('p', { class: 'eh-banner__title' }, 'Settle the unpaid amount to request'),
            el('div', { class: 'eh-banner__text' }, due.reason || 'You have an unpaid amount from an earlier ride.'),
            el('ul', { style: 'list-style:none;margin:var(--eh-space-2) 0 0;padding:0;display:grid;gap:var(--eh-space-3)' }, rows),
        ]),
    ]);
}

async function payNow(button, rideId, cardId) {
    button.disabled = true;
    button.setAttribute('aria-busy', 'true');
    try {
        const { data: paid } = await api(urlFor(data.urls.payNow, rideId), { method: 'POST', body: { payment_method_id: cardId } });
        window.ehToast?.(paid.message, { tone: paid.status === 'paid' ? 'go' : 'neutral' });
        const { data: due } = await api(data.urls.wallet);
        state.wallet = due;
        data.paymentDue = due.payment_due;
        document.querySelector('[data-pax-due-static]')?.remove();
        renderOptions();
    } catch (error) {
        button.disabled = false;
        button.removeAttribute('aria-busy');
        window.ehToast?.(error.message, { tone: 'stop' });
    }
}

// ------------------------------------------------------------------ request
function requestBar() {
    const button = el('button', { type: 'button', class: 'eh-btn eh-btn--primary eh-btn--lg eh-btn--block', 'data-pax-request': '' }, el('span', { class: 'eh-btn__label' }, 'Request'));
    button.addEventListener('click', submit);
    const fine = el('p', { class: 'pax-request__fine', 'data-pax-request-fine': '' });
    const error = el('p', { class: 'pax-field__status', 'data-kind': 'error', role: 'alert', 'data-pax-request-error': '' });
    return el('div', { class: 'pax-request' }, [error, button, fine]);
}

function selectedOption() {
    return state.compare?.options?.find((o) => o.category.key === state.selected) || null;
}

function updateRequest() {
    renderPaymentOnce();
    const button = body.querySelector('[data-pax-request]');
    if (!button) return;
    const option = selectedOption();
    const expired = quoteState(option?.expires_at).state === 'expired';
    const blocked = Boolean(data.paymentDue?.blocked);
    const biz = chosenPayment()?.profile ? state.business.status?.state : null;
    const payable = state.payment !== null && (biz === null || biz === 'allowed');
    button.querySelector('.eh-btn__label').textContent = requestLabel(option, state.when === 'later' ? state.laterAt : null);
    button.disabled = !option || expired || blocked || !payable || state.submitting;
    const fine = body.querySelector('[data-pax-request-fine]');
    const free = option?.category?.policies?.cancellation_free_minutes;
    fine.textContent = blocked
        ? 'Requests open again once the unpaid amount is settled.'
        : state.when === 'later'
          ? `Free to cancel or change until ${data.schedule.free_change_until_minutes} minutes before pickup.`
          : `Free to cancel while we find a driver${Number.isFinite(free) ? ` and for ${free} minutes after one accepts` : ''}.`;
}

let paymentRendered = false;
function renderPaymentOnce() {
    const host = body.querySelector('[data-pax-payment]');
    if (host && (!paymentRendered || !host.firstChild)) {
        paymentRendered = true;
        renderPayment();
    }
}

async function submit() {
    if (state.submitting) return;
    const option = selectedOption();
    if (!option) return;
    const choice = chosenPayment();
    const payload = {
        quote_id: option.quote_id,
        pickup_address: state.pickup.label.slice(0, 255),
        dropoff_address: state.dropoff.label.slice(0, 255),
    };
    if (choice?.kind) payload.payment_method = choice.kind;
    if (choice?.ref) payload.payment_ref = choice.ref;
    if (choice?.profile) {
        payload.business = Object.fromEntries(Object.entries({ purpose: state.business.purpose || null, approval_id: state.business.approvalId || null }).filter(([, v]) => v !== null));
    }
    if (state.when === 'later') payload.scheduled_for = new Date(state.laterAt).toISOString();

    // The same tap retried (a timeout, a lost answer) carries the same key, so it can never book twice.
    const signature = JSON.stringify(payload);
    if (state.keyFor !== signature || !state.key) {
        state.key = idempotencyKey('ride');
        state.keyFor = signature;
    }

    const button = body.querySelector('[data-pax-request]');
    const error = body.querySelector('[data-pax-request-error]');
    state.submitting = true;
    button.disabled = true;
    button.setAttribute('aria-busy', 'true');
    error.textContent = '';
    announce(state.when === 'later' ? 'Booking your ride.' : 'Requesting your ride.');

    for (let attempt = 0; attempt < 4; attempt++) {
        try {
            const { data: created } = await api(data.urls.request, { method: 'POST', body: payload, headers: { 'Idempotency-Key': state.key }, timeoutMs: 20000 });
            window.location.assign(urlFor(data.urls.trip, created.ride.id));
            return;
        } catch (failure) {
            if (failure.status === 409 && failure.code === 'request_in_progress') {
                await new Promise((r) => setTimeout(r, 1000));
                continue;
            }
            if (failure.status === 0) {
                // A lost answer: the same key is safe to send again once.
                if (attempt === 0) {
                    await new Promise((r) => setTimeout(r, 1500));
                    continue;
                }
            }
            state.submitting = false;
            button.removeAttribute('aria-busy');
            error.textContent = failure.message;
            if (String(failure.code).startsWith('business_')) {
                const why = businessReasons(failure.body);
                if (why.reasons.length) error.append(' ', why.reasons.join(' '));
            }
            announce(failure.message, { assertive: true });
            if (failure.status === 402) {
                try {
                    const { data: wallet } = await api(data.urls.wallet);
                    state.wallet = wallet;
                    data.paymentDue = wallet.payment_due;
                    renderOptions();
                } catch {
                    // Keep the message on screen.
                }
            } else if (failure.status === 422 && /quote|price|expired|promotion/i.test(failure.message)) {
                state.key = null;
                error.append(' ', el('button', { type: 'button', class: 'eh-link', style: 'background:none;border:0;padding:0;font:inherit;cursor:pointer', onclick: () => loadOptions() }, 'Refresh prices'));
            }
            updateRequest();
            return;
        }
    }
    state.submitting = false;
    button.removeAttribute('aria-busy');
    error.textContent = 'The request is taking longer than usual. Check your trips before trying again.';
    updateRequest();
}

// ------------------------------------------------------------------ saved places
function renderPlaces() {
    const host = sheet.querySelector('[data-pax-places]');
    if (!host) return;
    host.hidden = state.places.length === 0;
    host.replaceChildren(
        ...state.places.map((place) => {
            const chip = el('button', { type: 'button', class: 'eh-chip', 'data-pax-place': String(place.id) }, [icon(place.kind === 'home' ? 'home' : place.kind === 'work' ? 'briefcase' : 'pin'), place.label]);
            chip.addEventListener('click', () => (state.pickup ? setDropoff({ label: place.address, lat: place.lat, lng: place.lng }) : setPickup({ label: place.address, lat: place.lat, lng: place.lng })));
            return chip;
        }),
    );
}

// ------------------------------------------------------------------ start
async function start() {
    if (!stage || !sheet) return;
    config = await loadGeoConfig();

    const attribution = sheet.querySelector('[data-pax-geo-attribution]');
    if (attribution && config.geocoding?.attribution) attribution.textContent = config.geocoding.attribution;

    for (const kind of ['pickup', 'dropoff']) {
        const rootEl = sheet.querySelector(`[data-pax-search="${kind}"]`);
        // Typing needs the room: the results list opens under the field.
        rootEl.querySelector('input')?.addEventListener('focus', () => setSnap(sheet, 'full'));
        searches[kind] = createPlaceSearch(rootEl, {
            config,
            near: () => state.pickup,
            onSelect: (place) => (kind === 'pickup' ? setPickup(place) : setDropoff(place)),
        });
    }

    sheet.querySelector('[data-pax-locate]')?.addEventListener('click', (event) => locate(event.currentTarget));
    adjustBtn?.addEventListener('click', startPin);
    sheet.querySelector('[data-pax-pin-confirm]')?.addEventListener('click', () => endPin(true));
    sheet.querySelector('[data-pax-pin-cancel]')?.addEventListener('click', () => endPin(false));
    sheet.querySelector('[data-pax-back]')?.addEventListener('click', () => {
        clearInterval(quoteTimer);
        showStep(sheet, 'where');
        setSnap(sheet, 'half');
    });
    seePrices?.addEventListener('click', () => loadOptions({ keepSelection: false }));

    const laterBox = sheet.querySelector('[data-pax-later]');
    const laterInput = sheet.querySelector('#pax-later-at');
    sheet.querySelectorAll('input[name="when"]').forEach((radio) =>
        radio.addEventListener('change', () => {
            state.when = radio.value;
            laterBox.hidden = state.when !== 'later';
            if (state.when === 'later') {
                const bounds = laterBounds(data.schedule);
                laterInput.min = bounds.min;
                laterInput.max = bounds.max;
                if (!laterInput.value) laterInput.value = bounds.min;
                state.laterAt = laterInput.value;
                laterInput.focus();
            }
            readiness();
        }),
    );
    laterInput?.addEventListener('input', () => {
        state.laterAt = laterInput.value;
        readiness();
    });

    renderPlaces();
    watchConnection(sheet.querySelectorAll('[data-pax-net]'));

    if (data.again) {
        setPickup(data.again.pickup, { fit: false });
        setDropoff(data.again.dropoff, { fit: false });
    }

    readiness();

    // The map after first paint, so the sheet is usable at once on a slow connection.
    const idle = window.requestIdleCallback || ((fn) => setTimeout(fn, 200));
    idle(() => startMap().then(() => {
        if (!data.again && !state.pickup) {
            // Locate without a prompt only when the person already allowed it; otherwise wait for the button.
            locationPermission().then((permission) => permission === 'granted' && locate(null, { quiet: true }));
        }
    }));
}

start();
