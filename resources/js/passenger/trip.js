// The live ride page: follows GET /api/ehail/rides/{id}/feed and keeps every panel true to the ride's state.

import { mountMap, loadGeoConfig } from '../geo/index.js';
import { announce, api, island } from './api.js';
import { renderBreakdown } from './breakdown.js';
import { clock, freeCancelLeft, searchProgress, secondsSince, secondsUntil, waitState } from './countdown.js';
import { el, watchConnection } from './dom.js';
import { FeedClient } from './feed.js';
import { formatMinor } from './money.js';
import { bindCheckIns, bindShare, bindSos } from './safety.js';
import { announcement, holdsDriver, isPreTrip, isSearching, isTerminal, rideText, stageIndex } from './status.js';

const data = island('pax-trip-data');
const sheet = document.getElementById('pax-ride');
const frame = document.getElementById('pax-map');
const $ = (selector) => sheet.querySelector(selector);
const doc = document;

let snapshot = data?.feed || null;
let overview = data?.overview || null;
let safetyInfo = null;
let lastStatus = snapshot?.ride?.status || null;
let map = null;
let clockTimer = null;
let checkIns = null;
let feed = null;
let finalLoaded = false;

const currency = () => snapshot?.ride?.fare?.currency || 'ZAR';
const money = (minor) => formatMinor(minor, currency());

// ------------------------------------------------------------------ render
function render() {
    const ride = snapshot?.ride;
    if (!ride) return;
    const status = ride.status;
    const position = snapshot.driver_position || null;
    const text = rideText(ride, position);

    $('[data-pax-title]').textContent = text.title;
    $('[data-pax-sub]').textContent = text.sub;
    doc.title = `${text.title} | Dot.Ehail`;

    // Stages
    const stage = stageIndex(status);
    const steps = $('[data-pax-steps]');
    steps.hidden = stage < 0;
    steps.querySelectorAll('.eh-stepper__step').forEach((li, i) => {
        const s = i < stage ? 'done' : i === stage ? 'current' : 'todo';
        li.dataset.state = s;
        s === 'current' ? li.setAttribute('aria-current', 'step') : li.removeAttribute('aria-current');
    });

    const panel = (name, on) => {
        const node = sheet.querySelector(`[data-panel="${name}"]`);
        if (node) node.hidden = !on;
    };
    panel('scheduled', status === 'scheduled');
    panel('searching', isSearching(status));
    panel('driver', holdsDriver(status) || (status === 'completed' && Boolean(ride.driver)));
    panel('pin', Boolean(ride.pin));
    panel('wait', status === 'driver_arrived');
    panel('trip', status === 'in_progress');
    panel('price', !isTerminal(status) && status !== 'needs_review');
    panel('done', status === 'completed');
    panel('ended', isTerminal(status) && status !== 'completed' || status === 'needs_review');

    renderScheduled(ride);
    renderDriver(ride, position);
    renderPin(ride.pin);
    renderCancel(ride);

    $('[data-pax-pickup]').textContent = ride.pickup?.address || '';
    $('[data-pax-dropoff]').textContent = ride.dropoff?.address || '';
    if (ride.fare?.quoted_total_minor != null) $('[data-pax-quoted]').textContent = money(ride.fare.quoted_total_minor);

    const active = holdsDriver(status) || isSearching(status);
    doc.querySelector('[data-pax-sos-open]').hidden = !active;
    doc.querySelector('[data-pax-safety-link]').hidden = active;
    $('[data-pax-share-pre]').hidden = !(holdsDriver(status) && status !== 'in_progress');
    $('[data-pax-again]').hidden = !isTerminal(status);
    if (!holdsDriver(status)) $('[data-pax-share-box]').hidden = true;

    const fee = ride.fare?.cancellation_fee_minor;
    $('[data-pax-fee]').hidden = !fee;
    if (fee) $('[data-pax-fee-amount]').textContent = money(fee);

    if (status === 'completed' && !finalLoaded) loadFinal();

    // Map
    if (map) {
        map.setPickup(ride.pickup?.lat != null ? ride.pickup : null);
        map.setDestination(ride.dropoff?.lat != null ? ride.dropoff : null);
        if (holdsDriver(status) && position) {
            map.setDriver({ lat: position.lat, lng: position.lng, heading: position.heading });
        } else {
            map.setDriver(null);
        }
    }

    // Announce changes of state once.
    const said = announcement(lastStatus, ride, position);
    if (said) {
        announce(said.text, { assertive: said.assertive });
        if (said.assertive) $('[data-pax-title]').focus({ preventScroll: true });
        if (holdsDriver(status) && !holdsDriver(lastStatus)) loadSafety();
        if (map) fitRide();
    }
    lastStatus = status;

    // Check-ins only while a trip is on.
    if (holdsDriver(status) && !checkIns) checkIns = bindCheckIns(doc.getElementById('pax-checkin'), { rideId: data.rideId });
    if (!holdsDriver(status) && checkIns) {
        checkIns.stop();
        checkIns = null;
    }

    tick();
}

function renderScheduled(ride) {
    if (ride.status !== 'scheduled') return;
    const at = ride.scheduled_for ? new Date(ride.scheduled_for) : null;
    if (at) $('[data-pax-scheduled-at]').textContent = at.toLocaleString('en-ZA', { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
    const phase = $('[data-pax-phase]');
    const confirmed = ride.schedule_phase === 'driver_confirmed';
    phase.textContent = confirmed ? 'Driver confirmed' : 'Awaiting driver';
    phase.className = `eh-badge ${confirmed ? 'eh-badge--go' : 'eh-badge--caution'}`;
    const requote = $('[data-pax-requote]');
    requote.hidden = !ride.requote;
    if (ride.requote) $('[data-pax-requote-total]').textContent = money(ride.requote.total_minor);
}

function renderDriver(ride, position) {
    const driver = ride.driver;
    if (!driver) return;
    const name = safetyInfo?.driver?.first_name || driver.first_name || 'Your driver';
    $('[data-pax-driver-name]').textContent = name;
    const face = $('[data-pax-face]');
    if (face && face.tagName === 'SPAN') face.textContent = name.charAt(0).toUpperCase();
    const vehicle = driver.vehicle;
    if (vehicle) {
        $('[data-pax-car]').textContent = [vehicle.color, vehicle.make, vehicle.model].filter(Boolean).join(' ');
        const plate = $('[data-pax-plate]');
        plate.textContent = vehicle.plate || '';
        plate.setAttribute('aria-label', `Registration ${vehicle.plate || 'not given'}`);
    }
    const stale = $('[data-pax-stale]');
    const staleText = $('[data-pax-stale-text]');
    if (holdsDriver(ride.status) && position?.stale) {
        stale.hidden = false;
        staleText.textContent = `Last position ${position.age_s >= 120 ? `${Math.round(position.age_s / 60)} min` : `${position.age_s} s`} ago`;
    } else if (holdsDriver(ride.status) && !position && snapshot.connection?.driver_position === 'unknown') {
        stale.hidden = false;
        staleText.textContent = 'Driver position not available yet';
    } else {
        stale.hidden = true;
    }
}

function renderPin(pin) {
    const holder = $('[data-pax-pin]');
    if (!pin) return;
    if (holder.dataset.pin === String(pin)) return;
    holder.dataset.pin = String(pin);
    holder.setAttribute('aria-label', `Trip PIN ${String(pin).split('').join(' ')}`);
    holder.replaceChildren(...String(pin).split('').map((digit) => el('span', { class: 'pax-pincode__digit', 'aria-hidden': 'true' }, digit)));
}

function renderCancel(ride) {
    const box = $('[data-pax-cancel-box]');
    const can = isPreTrip(ride.status) || ride.status === 'scheduled';
    box.hidden = !can;
    if (!can) return;
    $('[data-pax-cancel-rule]').textContent = cancelSentence(ride);
}

function cancelSentence(ride) {
    const policy = overview?.policy;
    const now = overview?.cancellation_now;
    if (ride.status === 'scheduled') return `Free to cancel until ${data.schedule.free_change_until_minutes} minutes before pickup.`;
    if (isSearching(ride.status)) return 'Free to cancel while we look for a driver.';
    if (now && now.fee_minor > 0) return `Cancelling now costs ${money(now.fee_minor)}. ${now.reason}`;
    const left = freeCancelLeft(ride.timestamps?.accepted_at, policy?.cancel_free_minutes);
    if (left !== null && left > 0 && policy?.cancel_fee_minor > 0) return `Free to cancel for another ${clock(left)}. After that a fee of ${money(policy.cancel_fee_minor)} applies.`;
    if (now) return now.reason;
    return 'A cancellation fee may apply under the fare rules.';
}

/** Once a second: countdowns that do not need the server. */
function tick() {
    const ride = snapshot?.ride;
    if (!ride) return;

    if (isSearching(ride.status) && ride.search) {
        const progress = searchProgress(ride.search.started_at, ride.search.window_ends_at);
        const bar = $('[data-pax-search-progress]');
        if (progress !== null) {
            bar.style.setProperty('--pax-progress', `${Math.round(progress * 100)}%`);
            bar.setAttribute('aria-valuenow', String(Math.round(progress * 100)));
            const left = secondsUntil(ride.search.window_ends_at);
            $('[data-pax-search-left]').textContent = left > 0 ? `Searching for ${clock(secondsSince(ride.search.started_at))}. We keep looking for another ${clock(left)}.` : 'Still looking. If nobody accepts, the ride ends and you are not charged.';
        }
    }

    if (ride.status === 'driver_arrived') {
        const policy = overview?.policy;
        const wait = waitState(ride.timestamps?.arrived_at, policy?.wait_free_minutes ?? 0);
        if (wait) {
            const box = sheet.querySelector('[data-panel="wait"]');
            box.dataset.charging = String(wait.charging);
            $('[data-pax-wait-clock]').textContent = clock(wait.waited);
            $('[data-pax-wait-rule]').textContent = !policy
                ? 'Please go to the car now.'
                : wait.charging
                  ? `Waiting is now charged at ${money(policy.wait_per_minute_minor)} a minute (${wait.chargedMinutes} min so far).`
                  : policy.wait_per_minute_minor > 0
                    ? `${clock(wait.freeLeft)} of free waiting left, then ${money(policy.wait_per_minute_minor)} a minute.`
                    : 'Waiting is free on this tariff. Please go to the car now.';
        }
    }

    if (isPreTrip(ride.status)) $('[data-pax-cancel-rule]').textContent = cancelSentence(ride);
}

// ------------------------------------------------------------------ completed ride
async function loadFinal() {
    finalLoaded = true;
    try {
        const [{ data: fare }, { data: fresh }] = await Promise.all([api(data.urls.fare), api(data.urls.overview)]);
        overview = fresh;
        const final = fare.final;
        if (final) {
            $('[data-pax-done-quoted]').textContent = money(final.quoted.amount_minor);
            $('[data-pax-done-final]').textContent = money(final.final.amount_minor);
            $('[data-pax-changes]').replaceChildren(
                ...(final.changes || []).map((c) => el('li', {}, [el('span', {}, c.text), el('strong', { class: 'eh-num' }, c.amount.amount_minor === 0 ? '' : formatMinor(c.amount.amount_minor, c.amount.currency, { signed: true }))])),
            );
            renderBreakdown($('[data-pax-final-breakdown]'), final.fare, { caption: 'You pay' });
        } else if (fare.quoted) {
            renderBreakdown($('[data-pax-final-breakdown]'), fare.quoted, { caption: 'Quoted' });
            $('[data-pax-changes]').replaceChildren(el('li', {}, el('span', {}, 'The final fare is being worked out from the trip. This page updates when it is ready.')));
            finalLoaded = false;
        }
        renderPayment();
    } catch {
        finalLoaded = false;
    }
}

function renderPayment() {
    if (!overview) return;
    const badge = $('[data-pax-pay-badge]');
    badge.textContent = overview.payment.label;
    badge.className = `eh-badge ${overview.payment.status === 'paid' ? 'eh-badge--go' : overview.payment.status === 'failed' ? 'eh-badge--stop' : ''}`;
    $('[data-pax-pay-method]').textContent = overview.payment.method_label || '';
    const receipt = $('[data-pax-receipt]');
    if (overview.receipt) {
        receipt.hidden = false;
        receipt.setAttribute('href', overview.receipt.url);
        receipt.querySelector('.eh-btn__label').textContent = `Receipt ${overview.receipt.number}`;
    }
    $('[data-pax-pay-now]').hidden = !overview.payment.can_pay_now;
    $('[data-pax-rate]').hidden = !overview.can_rate || overview.rated;
    $('[data-pax-rated]').hidden = !overview.rated;
}

async function payNow(button) {
    button.setAttribute('aria-busy', 'true');
    try {
        const { data: wallet } = await api(data.urls.wallet);
        const card = (wallet.methods || []).find((m) => m.type === 'card' && m.chargeable && m.is_default) || (wallet.methods || []).find((m) => m.type === 'card' && m.chargeable);
        if (!card) {
            window.ehToast?.('Add a card in your account first, then pay from here.', { tone: 'neutral' });
            return;
        }
        const { data: paid } = await api(data.urls.payNow, { method: 'POST', body: { payment_method_id: card.id } });
        window.ehToast?.(paid.message, { tone: paid.status === 'paid' ? 'go' : 'neutral' });
        overview = (await api(data.urls.overview)).data;
        renderPayment();
    } catch (error) {
        window.ehToast?.(error.message, { tone: 'stop' });
    } finally {
        button.removeAttribute('aria-busy');
    }
}

// ------------------------------------------------------------------ the driver's facts from the safety domain
async function loadSafety() {
    try {
        const { data: info } = await api(data.urls.safety);
        safetyInfo = info;
        const d = info.driver;
        if (!d) return;
        $('[data-pax-driver-rating]').textContent = d.rating?.label || 'New on Dot.Ehail';
        $('[data-pax-driver-verified]').hidden = !d.verified;
        if (d.check) $('[data-pax-check]').textContent = d.check;
        if (d.photo_url) {
            const face = $('[data-pax-face]');
            const img = el('img', { class: 'pax-driver__face', src: d.photo_url, alt: `Photo of ${d.first_name}`, width: '64', height: '64' });
            img.addEventListener('error', () => img.replaceWith(face));
            face.replaceWith(img);
        }
        if (d.vehicle?.registration) $('[data-pax-plate]').textContent = d.vehicle.registration;
    } catch {
        // The feed already shows the name, car and registration.
    }
}

// ------------------------------------------------------------------ actions
function bindActions() {
    const dialog = doc.getElementById('pax-cancel-dialog');
    $('[data-pax-cancel]')?.addEventListener('click', async () => {
        try {
            overview = (await api(data.urls.overview)).data;
        } catch {
            // Use what we have.
        }
        dialog.querySelector('[data-pax-cancel-cost]').textContent = cancelSentence(snapshot.ride);
        dialog.querySelector('[data-pax-cancel-error]').textContent = '';
        dialog.showModal();
    });
    dialog.querySelector('[data-pax-cancel-confirm]')?.addEventListener('click', async (event) => {
        const button = event.currentTarget;
        button.setAttribute('aria-busy', 'true');
        button.disabled = true;
        try {
            const reason = dialog.querySelector('select[name="reason"]').value;
            const { data: body } = await api(data.urls.cancel, { method: 'POST', body: { reason } });
            snapshot = { ...snapshot, ...body };
            dialog.close();
            render();
            feed?.refresh();
        } catch (error) {
            dialog.querySelector('[data-pax-cancel-error]').textContent = error.message;
        } finally {
            button.removeAttribute('aria-busy');
            button.disabled = false;
        }
    });

    const share = bindShare($('[data-pax-share-box]'), { createUrl: data.urls.shares, revokeUrl: data.urls.shareRevoke });
    $('[data-pax-share]')?.addEventListener('click', (e) => share.open(e.currentTarget));
    $('[data-pax-share-pre]')?.addEventListener('click', (e) => share.open(e.currentTarget));

    const openSos = bindSos(doc.getElementById('pax-sos'));
    doc.querySelector('[data-pax-sos-open]')?.addEventListener('click', openSos);

    $('[data-pax-pay-now]')?.addEventListener('click', (e) => payNow(e.currentTarget));

    // Rating: stars, tags, comment. Tags travel inside the comment (the ratings API stores one comment).
    $('[data-pax-tags]')?.addEventListener('click', (event) => {
        const chip = event.target.closest('[data-tag]');
        if (chip) chip.setAttribute('aria-pressed', String(chip.getAttribute('aria-pressed') !== 'true'));
    });
    $('[data-pax-rate]')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        const status = form.querySelector('[data-pax-rate-status]');
        const rating = Number(form.querySelector('input[name="rating"]:checked')?.value);
        if (!rating) {
            status.textContent = 'Choose from one to five stars.';
            return;
        }
        const tags = [...form.querySelectorAll('[data-tag][aria-pressed="true"]')].map((t) => t.dataset.tag);
        const comment = [tags.length ? `${tags.join(', ')}.` : '', form.querySelector('textarea').value.trim()].filter(Boolean).join(' ').slice(0, 500);
        try {
            const { data: saved } = await api(data.urls.rating, { method: 'POST', body: { rating, comment: comment || null } });
            form.hidden = true;
            $('[data-pax-rated]').hidden = false;
            announce(saved.message || 'Rating saved.');
        } catch (error) {
            status.textContent = error.message;
        }
    });

    $('[data-pax-report]')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        const status = form.querySelector('[data-pax-report-status]');
        try {
            const { data: saved } = await api(data.urls.report, { method: 'POST', body: { category: form.category.value, description: form.description.value || null } });
            status.textContent = saved.message;
            form.querySelector('button[type="submit"]').disabled = true;
        } catch (error) {
            status.textContent = error.message;
        }
    });

    // Scheduled ride: reschedule and accept a new price.
    const reschedule = $('[data-pax-reschedule]');
    reschedule?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const input = reschedule.querySelector('input');
        const out = reschedule.querySelector('[data-pax-reschedule-status]');
        try {
            const { data: body } = await api(data.urls.reschedule, { method: 'PATCH', body: { scheduled_for: new Date(input.value).toISOString() } });
            snapshot = { ...snapshot, ...body };
            out.textContent = 'Pickup time changed.';
            render();
        } catch (error) {
            out.textContent = error.message;
        }
    });
    $('[data-pax-requote-accept]')?.addEventListener('click', async (event) => {
        try {
            const { data: body } = await api(data.urls.requote, { method: 'POST' });
            snapshot = { ...snapshot, ...body };
            render();
            window.ehToast?.('New price accepted. Your ride stays booked.', { tone: 'go' });
        } catch (error) {
            window.ehToast?.(error.message, { tone: 'stop' });
        }
        event.currentTarget?.blur();
    });
}

// ------------------------------------------------------------------ map
function fitRide() {
    if (!map) return;
    const ride = snapshot.ride;
    const pts = [ride.pickup, ride.dropoff, snapshot.driver_position].filter((p) => p && p.lat != null);
    if (!pts.length) return;
    const wide = matchMedia('(min-width: 1024px)').matches;
    const cover = wide ? 0 : Math.max(0, innerHeight - sheet.getBoundingClientRect().top);
    map.map.fitBounds(pts.map((p) => [p.lat, p.lng]), { paddingTopLeft: [wide ? 448 : 32, 48], paddingBottomRight: [32, cover + 32], maxZoom: 16 });
}

async function startMap() {
    try {
        const config = await loadGeoConfig();
        map = await mountMap(frame.querySelector('[data-eh-map-canvas]'), { config });
        frame.dataset.mapState = 'ready';
        render();
        fitRide();
    } catch {
        frame.dataset.mapState = 'unavailable';
    }
}

// ------------------------------------------------------------------ start
function start() {
    if (!data || !sheet) return;
    bindActions();
    render();
    if (holdsDriver(snapshot.ride.status) || snapshot.ride.status === 'completed') loadSafety();

    const net = $('[data-pax-net]');
    watchConnection([net]);
    feed = new FeedClient({
        url: data.urls.feed,
        initial: snapshot,
        onUpdate: async (next, delta) => {
            snapshot = next;
            if (delta.ride && (isPreTrip(delta.ride.status) || isTerminal(delta.ride.status))) {
                try {
                    overview = (await api(data.urls.overview)).data;
                } catch {
                    // Keep the last overview.
                }
            }
            render();
        },
        onState: (state, info) => {
            net.hidden = !(state === 'offline');
            if (state === 'offline') {
                const since = info.lastOkAt ? ` Last update ${clock(Math.round((Date.now() - info.lastOkAt) / 1000))} ago.` : '';
                $('[data-pax-net-text]').textContent = `Connection lost. Showing the last update; we keep trying.${since}`;
            }
            if (state === 'stopped') {
                net.hidden = false;
                $('[data-pax-net-text]').textContent = 'This ride is no longer available to this account. Reload the page.';
            }
        },
    });
    feed.start();

    clockTimer = setInterval(tick, 1000);
    const idle = window.requestIdleCallback || ((fn) => setTimeout(fn, 200));
    idle(startMap);

    window.addEventListener('pagehide', () => {
        feed.stop();
        clearInterval(clockTimer);
    });
    // Back to this page from the browser's cache: the feed was stopped, so start from the server's truth.
    window.addEventListener('pageshow', (event) => event.persisted && window.location.reload());
}

start();

