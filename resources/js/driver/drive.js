// Dot.Ehail driver console (/drive). Polls the dispatch feed (versioned, ETag), reports the phone's position while
// online, shows offers with their earnings, and walks the driver through the trip. Every decision is the server's:
// this script only asks and shows the answer. See docs/R46_UI_DRIVER.md.

import { api, ApiError, fill } from './api.js';
import { clockOffset, startCountdown } from './countdown.js';
import { destinationArea, formatClock, formatDistance, formatDuration, formatMoney, haversine } from './geometry.js';
import { MotionMode, shareMoving } from './motion.js';
import { navLink, NAV_APPS, readPrefs } from './nav.js';
import { PinPad } from './pin.js';
import { LocationThrottler, shouldSave } from './throttle.js';

const boot = JSON.parse(document.getElementById('dr-boot')?.textContent || '{}');
const root = document.querySelector('[data-dr-console]');
const $ = (sel, el = document) => el.querySelector(sel);
const $$ = (sel, el = document) => [...el.querySelectorAll(sel)];
const urls = boot.urls || {};
const policy = boot.policy || {};
const prefs = readPrefs();

const state = {
    version: 0,
    offset: 0,
    availability: { state: 'offline' },
    offer: null,
    ride: null,
    summaryRideId: null,
    failures: 0,
    lastFix: null,
    watchId: null,
    wakeLock: null,
    hiddenAt: null,
    pollTimer: null,
    shownOfferId: null,
    stopCountdown: null,
    earnings: {},
    riders: {},
    routeFor: null,
    map: null,
};

const throttler = new LocationThrottler({ everyS: policy.post_every_s ?? 4, saverEveryS: policy.saver_post_every_s ?? 8, maxBatch: policy.max_batch ?? 20 });
const motion = new MotionMode({ enterKmh: policy.moving?.enter_kmh ?? 10, exitKmh: policy.moving?.exit_kmh ?? 5, exitAfterS: policy.moving?.exit_after_s ?? 10 });
const pin = new PinPad(4);

// ---------- small helpers ----------

function setText(sel, text) {
    const el = typeof sel === 'string' ? $(sel) : sel;
    if (el) el.textContent = text ?? '';
}

function toast(message, tone = 'info') {
    if (typeof window.ehToast === 'function') window.ehToast(message, { tone });
}

function clockTime(d) {
    return [d.getHours(), d.getMinutes(), d.getSeconds()].map((n) => String(n).padStart(2, '0')).join(':');
}

function online() {
    return ['online', 'paused', 'on_trip'].includes(state.availability?.state);
}

const alerts = new Map();
function alertOn(key, tone, text) {
    alerts.set(key, { tone, text });
    renderAlerts();
}
function alertOff(key) {
    if (alerts.delete(key)) renderAlerts();
}
function renderAlerts() {
    const box = $('[data-dr-alerts]');
    if (!box) return;
    box.replaceChildren(
        ...[...alerts.values()].map(({ tone, text }) => {
            const p = document.createElement('p');
            p.className = `dr-alert dr-alert--${tone}`;
            p.textContent = text;

            return p;
        }),
    );
}

function setStatebar(kind, title, sub) {
    const bar = $('[data-dr-statebar]');
    if (!bar) return;
    bar.dataset.state = kind;
    const mark = { online: 'online', busy: 'route' }[kind] || 'offline';
    $$('[data-dr-mark]', bar).forEach((el) => (el.hidden = el.dataset.drMark !== mark));
    setText('[data-dr-state-title]', title);
    setText('[data-dr-state-sub]', sub);
}

// ---------- sound and vibration for offers (user setting) ----------

let audio = null;
function unlockAudio() {
    if (!prefs.sound || audio) return;
    try {
        const Ctx = window.AudioContext || window.webkitAudioContext;
        if (Ctx) audio = new Ctx();
    } catch {
        audio = null;
    }
}
function chime() {
    if (prefs.vibrate && navigator.vibrate) navigator.vibrate([200, 100, 200]);
    if (!prefs.sound || !audio) return;
    try {
        audio.resume?.();
        [0, 0.22].forEach((at, i) => {
            const osc = audio.createOscillator();
            const gain = audio.createGain();
            osc.frequency.value = i === 0 ? 660 : 880;
            gain.gain.setValueAtTime(0.0001, audio.currentTime + at);
            gain.gain.exponentialRampToValueAtTime(0.25, audio.currentTime + at + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.0001, audio.currentTime + at + 0.18);
            osc.connect(gain).connect(audio.destination);
            osc.start(audio.currentTime + at);
            osc.stop(audio.currentTime + at + 0.2);
        });
    } catch {
        /* sound is a courtesy; the card is the signal */
    }
}

// ---------- the feed ----------

async function poll() {
    clearTimeout(state.pollTimer);
    let wait = online() ? 2 : 30;

    try {
        const res = await api(urls.feed, { headers: state.version ? { 'If-None-Match': `"v${state.version}"` } : {} });
        wait = Number(res.headers.get('Retry-After')) || wait;
        if (res.status !== 304 && res.data) applyFeed(res.data);
        state.failures = 0;
        alertOff('connection');
        setText('[data-dr-conn-status]', `Checked at ${clockTime(new Date())}`);
    } catch (e) {
        state.failures++;
        if (e instanceof ApiError && e.status === 401) {
            alertOn('connection', 'stop', 'You are signed out. Sign in again to keep receiving offers.');
            return;
        }
        if (state.failures >= 2) alertOn('connection', 'caution', 'No connection to Dot.Ehail. Offers cannot reach you until it returns. Retrying.');
        setText('[data-dr-conn-status]', 'No connection, retrying');
        wait = Math.min(15, 2 + state.failures * 2);
    }

    state.pollTimer = setTimeout(poll, wait * 1000);
}

function applyFeed(body) {
    if (!body) return;
    if (body.version) state.version = body.version;
    if (body.server_time) state.offset = clockOffset(body.server_time, Date.now());
    if (body.availability) state.availability = body.availability;
    state.offer = body.offer ?? null;
    const previousRide = state.ride;
    state.ride = body.ride ?? null;

    // A ride that left the feed after being completed by us opens the summary (also after a reload: sessionStorage).
    if (previousRide && !state.ride && previousRide.status === 'in_progress') {
        openSummary(previousRide.id);
    }

    // A new trip (an offer accepted from the summary screen) takes the screen from the last trip's summary:
    // otherwise the summary stays up and the driver never sees the pickup (QA, D-1614).
    if (state.ride && state.summaryRideId && String(state.ride.id) !== String(state.summaryRideId)) {
        closeSummary();
        return;
    }

    render();
}

// ---------- rendering ----------

function render() {
    const view = state.summaryRideId ? 'summary' : state.ride ? 'trip' : online() ? 'online' : 'offline';
    if (root) root.dataset.view = view;
    $$('[data-dr-view]').forEach((el) => (el.hidden = el.dataset.drView !== view));
    $('[data-dr-sos-open]').hidden = !state.ride;
    document.documentElement.classList.toggle('dr-on-trip', Boolean(state.ride));

    const a = state.availability || {};
    if (state.summaryRideId) {
        setStatebar('online', 'Trip complete', 'Check the fare and your earnings, then go back to offers.');
    } else if (state.ride) {
        setStatebar('busy', 'On a trip', DRIVER_LABELS[state.ride.status] || state.ride.status_label || 'Trip in progress');
    } else if (a.state === 'online') {
        setStatebar('online', 'You are online', 'Waiting for ride offers. Keep this screen open.');
    } else if (a.state === 'paused') {
        setStatebar('offline', 'Paused', 'No offers until you resume. You are still signed in.');
    } else {
        setStatebar('offline', 'You are offline', 'Go online when you are ready to take rides.');
    }

    $('[data-dr-pause]')?.toggleAttribute('hidden', a.state !== 'online');
    $('[data-dr-resume]')?.toggleAttribute('hidden', a.state !== 'paused');

    if (online()) {
        ensureLocation();
        // Stale: our own heartbeats have not been getting through (the feed's flag can lag behind a fresh heartbeat).
        const staleMs = (policy.heartbeat_stale_s ?? 45) * 1000;
        if (lastHeartbeatOk && Date.now() - lastHeartbeatOk > staleMs) alertOn('stale', 'caution', `Dispatch has not heard from your phone for over ${policy.heartbeat_stale_s ?? 45} seconds. You get no offers until it does.`);
        else alertOff('stale');
    } else {
        stopLocation();
        alertOff('stale');
        alertOff('background');
    }

    renderOffer();
    if (state.ride) renderTrip();
}

// ---------- going online and offline ----------

function locate() {
    return new Promise((resolve, reject) => {
        if (!navigator.geolocation) {
            reject(Object.assign(new Error('This browser cannot share its location.'), { code: 0 }));
            return;
        }
        navigator.geolocation.getCurrentPosition(resolve, reject, { enableHighAccuracy: true, timeout: 15000, maximumAge: 10000 });
    });
}

function locationErrorText(err) {
    if (err?.code === 1) return 'Location is blocked for Dot.Ehail. Dispatch cannot send you offers without it. Allow location for this site in your browser settings, then go online again.';
    if (err?.code === 0) return err.message;

    return 'Your phone has not found its position yet. Offers start once it does; stay where the sky is visible if you can.';
}

async function goOnline(button) {
    unlockAudio();
    button.disabled = true;
    button.setAttribute('aria-busy', 'true');

    try {
        try {
            onFix(await locate());
        } catch (err) {
            if (err?.code === 1 || err?.code === 0) {
                alertOn('location', 'stop', locationErrorText(err));
                return;
            }
            alertOn('location', 'caution', locationErrorText(err));
        }

        const res = await api(urls.online, { method: 'POST', body: boot.vehicle ? { vehicle_id: boot.vehicle.id } : {} });
        applyFeed(res.data);
        sendHeartbeat();
        keepAwake();
        // Online polls every 2 s: restart the loop now instead of waiting out the offline interval.
        poll();
    } catch (e) {
        const message = e instanceof ApiError ? e.message : 'Could not reach Dot.Ehail. Check your connection and try again.';
        alertOn('online', 'stop', message);
        // The server refused (documents, vehicle): reload shows the exact list of what is missing.
        if (e instanceof ApiError && e.status === 403) setTimeout(() => window.location.reload(), 2500);
    } finally {
        button.disabled = false;
        button.removeAttribute('aria-busy');
    }
}

async function goOffline(button) {
    button.disabled = true;
    try {
        const res = await api(urls.offline, { method: 'POST' });
        applyFeed(res.data);
        releaseAwake();
        alertOff('online');
    } catch (e) {
        alertOn('online', 'stop', e.message);
    } finally {
        button.disabled = false;
    }
}

async function keepAwake() {
    try {
        if ('wakeLock' in navigator && !state.wakeLock) {
            state.wakeLock = await navigator.wakeLock.request('screen');
            state.wakeLock.addEventListener?.('release', () => (state.wakeLock = null));
        }
    } catch {
        state.wakeLock = null;
    }
}

function releaseAwake() {
    state.wakeLock?.release?.();
    state.wakeLock = null;
}

// ---------- location while online ----------

function onFix(pos) {
    const c = pos.coords;
    const fix = {
        lat: c.latitude,
        lng: c.longitude,
        accuracy: Number.isFinite(c.accuracy) ? Math.round(c.accuracy) : null,
        speed: Number.isFinite(c.speed) ? c.speed : null,
        heading: Number.isFinite(c.heading) ? Math.round(c.heading) % 360 : null,
        recorded_at: pos.timestamp || Date.now(),
    };
    state.lastFix = fix;
    alertOff('location');
    throttler.push(fix);

    const moving = motion.update(fix);
    if (document.documentElement.dataset.moving !== String(moving)) {
        document.documentElement.dataset.moving = String(moving);
        shareMoving(moving);
        if (moving && state.map) $('[data-dr-map-wrap]')?.setAttribute('data-paused', 'true');
    }

    setText('[data-dr-loc-status]', fix.accuracy ? `Found, within about ${fix.accuracy} m` : 'Found');
    if (state.map && state.ride) state.map.setDriver({ lat: fix.lat, lng: fix.lng, heading: fix.heading });
    if (state.ride) renderTrip();
}

function onFixError(err) {
    alertOn('location', err?.code === 1 ? 'stop' : 'caution', locationErrorText(err));
    setText('[data-dr-loc-status]', err?.code === 1 ? 'Blocked' : 'Searching');
    // Blocked: stop this watch and try again a little later, so allowing location in the browser settings
    // takes effect without reloading the app.
    if (err?.code === 1) stopLocation();
}

let lastWatchAttempt = 0;
function ensureLocation() {
    if (state.watchId !== null || !navigator.geolocation || Date.now() - lastWatchAttempt < 10000) return;
    lastWatchAttempt = Date.now();
    state.watchId = navigator.geolocation.watchPosition(onFix, onFixError, { enableHighAccuracy: true, maximumAge: 3000, timeout: 30000 });
}

function stopLocation() {
    if (state.watchId !== null) navigator.geolocation.clearWatch(state.watchId);
    state.watchId = null;
}

async function postLocations() {
    if (!online()) return;
    const now = Date.now();
    const batch = throttler.take(now);
    if (!batch.length) return;

    try {
        await api(urls.location, {
            method: 'POST',
            body: {
                samples: batch.map((f) => ({
                    lat: f.lat,
                    lng: f.lng,
                    accuracy: f.accuracy,
                    speed: f.speed ?? undefined,
                    heading: f.heading ?? undefined,
                    recorded_at: f.recorded_at,
                })),
            },
        });
        throttler.sent(batch.length);
    } catch (e) {
        // 409 not_online: dispatch thinks we are offline; the next feed says so. Drop what we had.
        if (e instanceof ApiError && e.status === 409) throttler.sent(batch.length);
        else throttler.failed(Date.now(), e.retryAfter || 0);
    }
}

let lastHeartbeat = 0;
let lastHeartbeatOk = 0;
async function sendHeartbeat() {
    if (!online()) return;
    lastHeartbeat = Date.now();
    const f = state.lastFix && Date.now() - state.lastFix.recorded_at < 30000 ? state.lastFix : null;

    try {
        await api(urls.heartbeat, {
            method: 'POST',
            body: f ? { lat: f.lat, lng: f.lng, heading: f.heading ?? undefined, speed_ms: f.speed ?? undefined, accuracy_m: f.accuracy ?? undefined } : {},
        });
        lastHeartbeatOk = Date.now();
        alertOff('stale');
    } catch {
        /* the feed's staleness flag tells the driver */
    }
}

setInterval(() => {
    // Some browsers report a position only when it changes: no fix for a while while "moving" means stopped.
    const exitMs = ((policy.moving?.exit_after_s ?? 10) + 5) * 1000;
    if (document.documentElement.dataset.moving === 'true' && state.lastFix && Date.now() - state.lastFix.recorded_at > exitMs) {
        motion.moving = false;
        motion.fastCount = 0;
        document.documentElement.dataset.moving = 'false';
        shareMoving(false);
    }
    postLocations();
    if (online() && Date.now() - lastHeartbeat >= (policy.heartbeat_every_s ?? 10) * 1000) sendHeartbeat();
}, 1000);

async function saverCheck() {
    let battery = null;
    try {
        if (navigator.getBattery) {
            const b = await navigator.getBattery();
            battery = { level: b.level, charging: b.charging };
            b.addEventListener?.('levelchange', saverCheck);
            b.addEventListener?.('chargingchange', saverCheck);
        }
    } catch {
        battery = null;
    }
    throttler.setSaver(shouldSave({ saveData: Boolean(navigator.connection?.saveData), battery }));
}

// The honest limit of a web app: in the background the browser may stop this page.
document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
        state.hiddenAt = Date.now();
        return;
    }
    if (state.hiddenAt && online() && Date.now() - state.hiddenAt > 5000) {
        alertOn('background', 'caution', 'Dot.Ehail was in the background. Your phone may have stopped sending your location, so offers could not reach you. Keep this screen open while you are online.');
    }
    state.hiddenAt = null;
    if (online()) keepAwake();
    poll();
});

// ---------- offers ----------

function paymentLabel(kind) {
    return { cash: 'Cash', card: 'Card', business_account: 'Business account' }[kind] || (kind ? kind : 'Payment not set');
}

function renderOffer() {
    const card = $('[data-dr-offer]');
    const offer = state.offer;

    if (!offer) {
        if (state.shownOfferId && !state.ride) toast('That offer is no longer available.', 'info');
        hideOffer();
        return;
    }
    if (state.shownOfferId === offer.id) return;

    state.shownOfferId = offer.id;
    card.hidden = false;
    document.documentElement.classList.add('dr-offer-open');
    $('[data-dr-decline-form]').hidden = true;
    $('.dr-offer__actions').hidden = false;

    // Earnings first: the number before the ask.
    const net = offer.earnings_estimate_minor;
    setText('[data-dr-offer-net]', net !== null && net !== undefined ? formatMoney(net, offer.currency) : formatMoney(offer.fare_total_minor, offer.currency) ?? 'Fare not shown');
    setText('[data-dr-offer-net-label]', net !== null && net !== undefined ? 'You earn (estimate)' : 'Passenger fare. Your net is being worked out.');
    $('[data-dr-offer-ledger]').replaceChildren();
    loadOfferEarnings(offer);

    const pickupBits = [formatDistance(offer.pickup_distance_m), offer.pickup_eta_s ? `about ${formatDuration(offer.pickup_eta_s)}` : null].filter(Boolean).join(', ');
    setText('[data-dr-offer-pickup-distance]', pickupBits ? `${pickupBits} away${offer.eta_method === 'straight_line_estimate' ? ' (straight-line estimate)' : ''}` : '');
    setText('[data-dr-offer-pickup]', offer.pickup?.address);
    const tripBits = [formatDistance(offer.trip_distance_m), formatDuration(offer.trip_duration_s)].filter(Boolean).join(', ');
    setText('[data-dr-offer-trip]', tripBits || 'distance not known');
    setText('[data-dr-offer-dropoff]', policy.destination_detail === 'address' ? offer.dropoff?.address : `Towards ${destinationArea(offer.dropoff?.address) || 'the destination'} (full address after you accept)`);

    const category = offer.category ? String(offer.category).replace(/_/g, ' ') : null;
    const tags = [category ? category.charAt(0).toUpperCase() + category.slice(1) : null, paymentLabel(offer.payment_method), offer.passenger?.first_name ? `${offer.passenger.first_name}, rating ${offer.passenger.rating_band}` : null].filter(Boolean);
    setText('[data-dr-offer-tags]', tags.join(' · '));

    state.stopCountdown?.();
    const fillEl = $('[data-dr-countdown-fill]');
    state.stopCountdown = startCountdown({
        sentAt: offer.sent_at,
        expiresAt: offer.expires_at,
        offsetMs: state.offset,
        onTick: (s, f) => {
            setText('[data-dr-countdown-seconds]', String(s));
            if (fillEl) fillEl.style.transform = `scaleX(${f})`;
        },
        onExpire: () => {
            hideOffer();
            toast('The offer timed out. It does not count against you more than a decline.', 'info');
            poll();
        },
    });

    chime();
    $('[data-dr-accept]')?.focus();
    api(fill(urls.offer_seen, offer.id), { method: 'POST' }).catch(() => {});
}

function hideOffer() {
    state.stopCountdown?.();
    state.stopCountdown = null;
    state.shownOfferId = null;
    const card = $('[data-dr-offer]');
    if (card) card.hidden = true;
    document.documentElement.classList.remove('dr-offer-open');
}

async function loadOfferEarnings(offer) {
    const ledger = $('[data-dr-offer-ledger]');
    try {
        const { data } = state.earnings[offer.id] ? { data: state.earnings[offer.id] } : await api(fill(urls.offer_earnings, offer.id));
        state.earnings[offer.id] = data;
        if (state.shownOfferId !== offer.id) return;
        const rows = [['Fare (estimate)', formatMoney(data.fare_minor, data.currency)]];
        if (data.available) {
            if (data.tolls_minor) rows.push(['Tolls, paid back to you', formatMoney(data.tolls_minor, data.currency)]);
            (data.deductions || []).forEach((d) => rows.push([d.label + (d.detail ? ` (${d.detail})` : ''), formatMoney(-d.amount_minor, data.currency)]));
            rows.push(['You earn', data.net_minor !== null ? formatMoney(data.net_minor, data.currency) : 'not configured', true]);
        }
        ledger.replaceChildren(...rows.map(([k, v, total]) => ledgerRow(k, v, total)));
        const plan = $('[data-dr-offer-plan]');
        if (plan) plan.hidden = !data.plan?.is_example;
        if (data.net_minor !== null && data.net_minor !== undefined) {
            setText('[data-dr-offer-net]', formatMoney(data.net_minor, data.currency));
            setText('[data-dr-offer-net-label]', 'You earn (estimate)');
        } else if (data.note) {
            setText('[data-dr-offer-net-label]', `Passenger fare. ${data.note}`);
        }
    } catch {
        ledger.replaceChildren(ledgerRow('Breakdown', 'Could not load. The figure above is the estimate dispatch sent.'));
    }
}

function ledgerRow(label, value, strong = false) {
    const div = document.createElement('div');
    if (strong) div.className = 'is-total';
    const dt = document.createElement('dt');
    dt.textContent = label;
    const dd = document.createElement('dd');
    dd.textContent = value ?? '';
    div.append(dt, dd);

    return div;
}

async function acceptOffer(button) {
    const offer = state.offer;
    if (!offer) return;
    button.disabled = true;
    button.setAttribute('aria-busy', 'true');
    try {
        const res = await api(fill(urls.offer_accept, offer.id), { method: 'POST' });
        hideOffer();
        applyFeed(res.data);
        // Accepting means heading to the pickup now.
        if (state.ride?.status === 'driver_assigned') {
            const r = await api(fill(urls.stage, state.ride.id, { stage: 'arriving' }), { method: 'POST' });
            applyFeed(r.data);
        }
    } catch (e) {
        hideOffer();
        toast(e instanceof ApiError ? e.message : 'Could not accept: no connection.', 'error');
        poll();
    } finally {
        button.disabled = false;
        button.removeAttribute('aria-busy');
    }
}

async function declineOffer(reason) {
    const offer = state.offer;
    if (!offer) return;
    hideOffer();
    try {
        const res = await api(fill(urls.offer_decline, offer.id), { method: 'POST', body: reason ? { reason } : {} });
        applyFeed(res.data);
    } catch (e) {
        if (e instanceof ApiError && e.status !== 409) toast(e.message, 'error');
        poll();
    }
}

// ---------- the trip ----------

// The ride's state in the driver's words (the feed's labels are written for passengers).
const DRIVER_LABELS = {
    driver_assigned: 'Heading to the pickup',
    driver_arriving: 'Heading to the pickup',
    driver_arrived: 'Waiting at the pickup',
    in_progress: 'Passenger on board',
};

function tripTarget(ride) {
    return ride.status === 'in_progress' ? ride.dropoff : ride.pickup;
}

function renderTrip() {
    const ride = state.ride;
    if (!ride) return;
    const stage = ride.status === 'in_progress' ? 'in_progress' : ride.status === 'driver_arrived' ? 'arrived' : 'arriving';
    $$('[data-dr-stage]').forEach((el) => (el.hidden = el.dataset.drStage !== stage));

    const steps = { arriving: 0, arrived: 1, in_progress: 2 }[stage];
    $$('[data-dr-route] li').forEach((li, i) => {
        li.dataset.state = i < steps ? 'done' : i === steps ? 'current' : 'todo';
        if (i === steps) li.setAttribute('aria-current', 'step');
        else li.removeAttribute('aria-current');
    });

    const target = tripTarget(ride);
    setText('[data-dr-stop-label]', stage === 'in_progress' ? 'Drop-off' : stage === 'arrived' ? 'At the pickup' : 'Pickup');
    setText('[data-dr-stop-address]', target?.address || 'Address not given');
    const rider = state.riders[ride.id];
    if (rider === undefined) loadRider(ride.id);
    const about = rider?.on_behalf ? [rider.guest ? 'guest' : null, rider.account || null].filter(Boolean) : [];
    const who = rider?.on_behalf
        ? `Pick up ${rider.name}${about.length ? ` (${about.join(', ')})` : ''}, booked by ${ride.passenger?.first_name || 'their company'}${rider.phone_masked ? `, phone ${rider.phone_masked}` : ''}`
        : ride.passenger?.first_name;
    setText('[data-dr-passenger]', who ? `${who} · ${paymentLabel(ride.payment_method)}` : paymentLabel(ride.payment_method));

    // Distance: the road route when a routing provider is configured, else the straight line, labelled.
    const fix = state.lastFix;
    let distance = null;
    if (fix && Number.isFinite(target?.lat) && Number.isFinite(target?.lng)) distance = haversine(fix, target);
    const routeKey = `${ride.id}:${stage}`;
    const road = state.routeFor?.key === routeKey ? state.routeFor : null;
    if (road?.distance_m) setText('[data-dr-stop-distance]', `${formatDistance(road.distance_m)} by road, about ${formatDuration(road.duration_s)}.`);
    else if (distance !== null) setText('[data-dr-stop-distance]', `${formatDistance(distance)} away in a straight line.`);
    else setText('[data-dr-stop-distance]', '');
    if (policy.routing_configured && fix && !road && state.routeFor?.key !== routeKey && stage !== 'arrived') loadRoute(routeKey, fix, target);

    const nav = $('[data-dr-navigate]');
    const href = target ? navLink(prefs.nav, target.lat, target.lng) : null;
    if (nav) {
        nav.hidden = !href || stage === 'arrived';
        if (href) nav.href = href;
        setText('[data-dr-navigate-label]', `Navigate in ${NAV_APPS[prefs.nav] || 'Google Maps'}`);
    }

    if (stage === 'arriving') renderArrival(distance);
    if (stage === 'arrived') renderWaiting();
    updateMap();
}

async function loadRider(id) {
    state.riders[id] = null;
    try {
        const { data } = await api(fill(urls.rider, id));
        state.riders[id] = data;
        if (state.ride?.id === id) renderTrip();
    } catch {
        state.riders[id] = { on_behalf: false };
    }
}

async function loadRoute(key, from, to) {
    state.routeFor = { key };
    try {
        const q = new URLSearchParams({ from_lat: from.lat, from_lng: from.lng, to_lat: to.lat, to_lng: to.lng });
        const { data } = await api(`${urls.route}?${q}`);
        state.routeFor = { key, distance_m: data.route?.distance_m, duration_s: data.route?.duration_s, polyline: data.route?.polyline };
        if (state.map && data.route?.polyline) state.map.drawRoute(data.route.polyline);
        renderTrip();
    } catch {
        state.routeFor = { key, failed: true };
    }
}

function renderArrival(distance) {
    const button = $('[data-dr-arrived]');
    const hint = $('[data-dr-arrived-hint]');
    const fix = state.lastFix;
    const tolerance = (policy.arrival_tolerance_m ?? 250) + Math.min(fix?.accuracy ?? 0, policy.arrival_accuracy_cap_m ?? 100);
    const target = tripTarget(state.ride);
    const knowsPoint = Number.isFinite(target?.lat);

    if (!knowsPoint) {
        button.disabled = false;
        hint.textContent = '';
    } else if (distance === null) {
        button.disabled = false;
        hint.textContent = 'Waiting for your phone’s position. You can still mark arrival; it will ask you to confirm.';
    } else if (distance <= tolerance) {
        button.disabled = false;
        hint.textContent = '';
    } else {
        button.disabled = true;
        hint.replaceChildren(document.createTextNode(`Enabled within ${tolerance} m of the pickup. `));
        const link = document.createElement('button');
        link.type = 'button';
        link.className = 'dr-textlink';
        link.textContent = 'I’m here, GPS is wrong';
        link.addEventListener('click', () => openFar(`Your phone places you about ${formatDistance(distance)} from the pickup.`));
        hint.append(link);
    }
}

function openFar(text) {
    setText('[data-dr-far-text]', text);
    document.getElementById('dr-far-dialog')?.showModal();
}

async function markArrived(confirmFar = false) {
    const ride = state.ride;
    if (!ride) return;
    // The server checks the distance against the last position it holds: send the newest fix first.
    await sendHeartbeat();
    try {
        const res = await api(fill(urls.arrived, ride.id), { method: 'POST', body: { confirm_far: confirmFar, accuracy_m: state.lastFix?.accuracy ?? undefined } });
        applyFeed(res.data);
    } catch (e) {
        if (e instanceof ApiError && (e.code === 'too_far' || e.code === 'position_unknown')) openFar(e.message);
        else toast(e.message || 'Could not reach Dot.Ehail.', 'error');
    }
}

let waitTimer = null;
function renderWaiting() {
    const ride = state.ride;
    clearInterval(waitTimer);
    const arrivedAt = Date.parse(ride.arrived_at);
    const noShowAt = Date.parse(ride.no_show_available_at);
    const tick = () => {
        const now = Date.now() + state.offset;
        if (Number.isFinite(arrivedAt)) setText('[data-dr-wait-clock]', formatClock((now - arrivedAt) / 1000));
        const noShow = $('[data-dr-noshow]');
        if (Number.isFinite(noShowAt)) {
            const ready = now >= noShowAt;
            noShow.disabled = !ready;
            setText('[data-dr-noshow-note]', ready
                ? `You have waited ${policy.no_show_wait_min ?? 5} minutes. If the passenger has not come, you may record a no-show.`
                : `You may record a no-show from ${clockTime(new Date(noShowAt - state.offset)).slice(0, 5)}, after ${policy.no_show_wait_min ?? 5} minutes of waiting.`);
        }
        if (!state.ride || state.ride.status !== 'driver_arrived') clearInterval(waitTimer);
    };
    tick();
    waitTimer = setInterval(tick, 1000);

    const pinBox = $('[data-dr-pin]');
    pinBox.hidden = !ride.pin_required;
    renderPin();
}

function renderPin() {
    const boxes = $$('[data-dr-pin-boxes] span');
    boxes.forEach((b, i) => {
        b.textContent = pin.digits[i] ? '•' : '';
        b.dataset.filled = pin.digits[i] ? 'true' : 'false';
    });
    $('[data-dr-pin-boxes]')?.setAttribute('aria-label', `${pin.digits.length} of 4 digits entered`);
    const locked = pin.locked();
    $$('[data-dr-key], [data-dr-key-back], [data-dr-key-clear]').forEach((b) => (b.disabled = locked));
    const start = $('[data-dr-start]');
    if (start) start.disabled = Boolean(state.ride?.pin_required) && (!pin.complete() || locked);
}

async function startTrip(button) {
    const ride = state.ride;
    if (!ride) return;
    button.disabled = true;
    try {
        const res = await api(fill(urls.stage, ride.id, { stage: 'start' }), { method: 'POST', body: ride.pin_required ? { pin: pin.digits } : {} });
        pin.clear();
        setText('[data-dr-pin-error]', '');
        applyFeed(res.data);
    } catch (e) {
        if (e instanceof ApiError && (e.code === 'pin_wrong' || e.code === 'pin_locked')) {
            pin.refused(e.code);
            setText('[data-dr-pin-error]', e.message);
            if (navigator.vibrate && prefs.vibrate) navigator.vibrate(150);
        } else {
            toast(e.message || 'Could not start the trip.', 'error');
        }
    } finally {
        renderPin();
    }
}

async function stageAction(url, body, okMessage) {
    try {
        const res = await api(url, { method: 'POST', body });
        applyFeed(res.data);
        poll();
        if (okMessage) toast(okMessage, 'success');

        return res.data;
    } catch (e) {
        toast(e.message || 'Could not reach Dot.Ehail.', 'error');

        return null;
    }
}

async function completeTrip() {
    const ride = state.ride;
    if (!ride) return;
    const id = ride.id;
    const data = await stageAction(fill(urls.stage, id, { stage: 'complete' }), {});
    if (data) openSummary(id);
}

// ---------- map (lazy: the engine loads only when asked, and never while driving fast) ----------

async function showMap() {
    const wrap = $('[data-dr-map-wrap]');
    const frame = $('#dr-map');
    if (!frame || state.map) return;
    $('[data-dr-map-show]').hidden = true;
    try {
        const { mountMap } = await import('../geo/index.js');
        const target = state.ride ? tripTarget(state.ride) : null;
        const center = state.lastFix ? { lat: state.lastFix.lat, lng: state.lastFix.lng } : target?.lat ? { lat: target.lat, lng: target.lng } : undefined;
        state.map = await mountMap(frame.querySelector('[data-eh-map-canvas]'), { center, zoom: 15 });
        frame.dataset.mapState = 'ready';
        wrap.dataset.open = 'true';
        updateMap(true);
    } catch {
        frame.dataset.mapState = 'unavailable';
        setText(frame.querySelector('[data-eh-map-message] span'), 'The map could not load on this connection. Navigation still works.');
    }
}

function updateMap(fit = false) {
    if (!state.map || !state.ride) return;
    const r = state.ride;
    if (Number.isFinite(r.pickup?.lat)) state.map.setPickup({ lat: r.pickup.lat, lng: r.pickup.lng });
    if (Number.isFinite(r.dropoff?.lat)) state.map.setDestination({ lat: r.dropoff.lat, lng: r.dropoff.lng });
    if (state.lastFix) state.map.setDriver({ lat: state.lastFix.lat, lng: state.lastFix.lng, heading: state.lastFix.heading });
    if (fit) state.map.fitToContent();
}

// ---------- after the trip: final fare, earnings, cash ----------

const SUMMARY_KEY = 'eh-drive-summary';
let summaryTimer = null;

function openSummary(rideId) {
    state.summaryRideId = rideId;
    try {
        sessionStorage.setItem(SUMMARY_KEY, String(rideId));
    } catch {
        /* reload just returns to offers */
    }
    setStatebar('online', 'Trip complete', 'Check the fare and your earnings.');
    render();
    loadSummary(0);
}

async function loadSummary(attempt) {
    clearTimeout(summaryTimer);
    const id = state.summaryRideId;
    if (!id) return;
    try {
        const { data } = await api(fill(urls.summary, id));
        // Settlement runs just after completion (queued): until it has, "not due" only means "not settled yet".
        const waitingForCash = data.payment_method === 'cash' && !data.cash;
        const waitingForFare = !data.fare.finalised || !data.earnings || data.earnings.source !== 'statement';
        const more = (waitingForCash || waitingForFare) && attempt < 12;
        renderSummary(data, !more);
        if (more) summaryTimer = setTimeout(() => loadSummary(attempt + 1), 3000);
    } catch {
        setText('[data-dr-summary-note]', 'Could not load the trip summary. It is in your trips list.');
    }
}

function renderSummary(s, settled = true) {
    const notCollected = settled && s.payment_status === 'not_due';
    $('[data-dr-summary-link]').href = fill(urls.trip, s.ride_id);
    const rows = [];
    if (s.fare.quoted_minor !== null) rows.push(ledgerRow('Fare agreed at request', formatMoney(s.fare.quoted_minor, s.currency)));
    if (s.fare.final_minor !== null) rows.push(ledgerRow('Final fare', formatMoney(s.fare.final_minor, s.currency), true));
    if (s.fare.difference_minor) rows.push(ledgerRow('Difference', `${formatMoney(s.fare.difference_minor, s.currency)}${s.fare.adjustments.length ? ` (${s.fare.adjustments.map((a) => a.text || a.label || a.reason || a.code).filter(Boolean).join(', ')})` : ''}`));
    if (s.earnings) {
        if (s.earnings.commission_minor !== null) rows.push(ledgerRow('Commission', formatMoney(-s.earnings.commission_minor, s.currency)));
        if (s.earnings.deductions_minor) rows.push(ledgerRow('Platform fees, tax and other', formatMoney(-s.earnings.deductions_minor, s.currency)));
        rows.push(ledgerRow('You earn', s.earnings.net_minor !== null ? formatMoney(s.earnings.net_minor, s.currency) : 'not configured', true));
    }
    $('[data-dr-summary-ledger]').replaceChildren(...rows);
    setText('[data-dr-summary-note]', notCollected
        ? 'Payments are not collected through Dot.Ehail on this platform yet, so no earnings are recorded for this trip.'
        : !s.earnings || s.earnings.source !== 'statement'
        ? (settled ? 'Your statement shows these earnings once the payment is recorded.' : 'Recording the fare in your statement. This takes a moment.')
        : s.earnings.status === 'pending'
            ? 'Earnings are pending until the payment settles, then count towards your next payout.'
            : '');

    const cashBox = $('[data-dr-cash]');
    if (s.payment_method === 'cash' && !notCollected) {
        cashBox.hidden = false;
        const amountEl = $('[data-dr-cash-amount]');
        amountEl.dataset.pending = String(!s.cash);
        if (!s.cash) {
            setText(amountEl, 'Working out the amount to collect from the final fare.');
            $('[data-dr-cash-form]').hidden = true;
        } else if (s.cash.confirmed) {
            $('[data-dr-cash-form]').hidden = true;
            setText('[data-dr-cash-amount]', formatMoney(s.cash.expected_minor, s.currency));
            const done = $('[data-dr-cash-done]');
            done.hidden = false;
            done.textContent = `Confirmed: ${formatMoney(s.cash.received_minor ?? s.cash.expected_minor, s.currency)} received.`;
        } else {
            setText('[data-dr-cash-amount]', formatMoney(s.cash.expected_minor, s.currency));
            const form = $('[data-dr-cash-form]');
            form.hidden = false;
            const input = form.querySelector('input');
            if (!input.value) input.value = (s.cash.expected_minor / 100).toFixed(2);
            form.dataset.ride = String(s.ride_id);
        }
    } else {
        cashBox.hidden = true;
    }
}

function randToMinor(text) {
    const clean = String(text).replace(/[\sR ]/g, '').replace(',', '.');
    if (!/^\d+(\.\d{1,2})?$/.test(clean)) return null;
    const [whole, cents = ''] = clean.split('.');

    return Number(whole) * 100 + Number(cents.padEnd(2, '0'));
}

async function confirmCash(form) {
    const minor = randToMinor(form.querySelector('input').value);
    if (minor === null) {
        toast('Enter the amount in rand, for example 85.00.', 'error');
        return;
    }
    try {
        await api(fill(urls.cash, form.dataset.ride), { method: 'POST', body: { amount_minor: minor } });
        toast('Cash confirmed.', 'success');
        loadSummary(10);
    } catch (e) {
        toast(e.message, 'error');
    }
}

function closeSummary() {
    clearTimeout(summaryTimer);
    state.summaryRideId = null;
    try {
        sessionStorage.removeItem(SUMMARY_KEY);
    } catch {
        /* nothing to clear */
    }
    render();
}

// ---------- SOS: one tap, 3 seconds to cancel, then the incident (same honest flow as passengers) ----------

let sosTimer = null;
function openSos() {
    const ride = state.ride;
    if (!ride) return;
    const box = $('[data-dr-sos]');
    box.hidden = false;
    $('[data-dr-sos-arming]').hidden = false;
    $('[data-dr-sos-result]').hidden = true;
    let n = 3;
    setText('[data-dr-sos-count]', String(n));
    $('[data-dr-sos-cancel]').focus();
    sosTimer = setInterval(() => {
        n--;
        setText('[data-dr-sos-count]', String(Math.max(0, n)));
        if (n <= 0) {
            clearInterval(sosTimer);
            sendSos(ride.id);
        }
    }, 1000);
}

async function sendSos(rideId) {
    const f = state.lastFix;
    $('[data-dr-sos-arming]').hidden = true;
    $('[data-dr-sos-result]').hidden = false;
    const list = $('[data-dr-sos-done]');
    list.replaceChildren(li('Sending to Dot.Ehail…'));
    try {
        const { data } = await api(fill(urls.sos, rideId), { method: 'POST', body: f ? { lat: f.lat, lng: f.lng, accuracy_m: f.accuracy ?? undefined } : {} });
        const w = data.what_we_did;
        list.replaceChildren(...w.actions.map((a) => li(`${a.what}${a.status_text ? `: ${a.status_text}` : ''}${a.why ? ` (${a.why})` : ''}`)));
        setText('[data-dr-sos-not]', (w.not_done || []).join(' '));
    } catch {
        list.replaceChildren(li('The SOS could not reach Dot.Ehail (no connection). Call for help with the numbers below.'));
    }
}

function li(text) {
    const el = document.createElement('li');
    el.textContent = text;

    return el;
}

// ---------- wiring ----------

document.addEventListener('click', (e) => {
    const t = e.target.closest('button, a');
    if (!t) return;

    if (t.matches('[data-dr-go-online]')) goOnline(t);
    else if (t.matches('[data-dr-go-offline]')) goOffline(t);
    else if (t.matches('[data-dr-pause]')) stageAction(urls.pause, undefined, 'Paused. No offers until you resume.');
    else if (t.matches('[data-dr-resume]')) stageAction(urls.resume, undefined, 'Offers resumed.');
    else if (t.matches('[data-dr-accept]')) acceptOffer(t);
    else if (t.matches('[data-dr-decline]')) {
        $('.dr-offer__actions').hidden = true;
        $('[data-dr-decline-form]').hidden = false;
    } else if (t.matches('[data-dr-decline-back]')) {
        $('.dr-offer__actions').hidden = false;
        $('[data-dr-decline-form]').hidden = true;
    } else if (t.matches('[data-dr-arrived]')) markArrived(false);
    else if (t.matches('[data-dr-far-confirm]')) {
        document.getElementById('dr-far-dialog')?.close();
        markArrived(true);
    } else if (t.matches('[data-dr-key]')) {
        pin.press(t.dataset.drKey);
        renderPin();
    } else if (t.matches('[data-dr-key-back]')) {
        pin.back();
        renderPin();
    } else if (t.matches('[data-dr-key-clear]')) {
        pin.clear();
        renderPin();
    } else if (t.matches('[data-dr-start]')) startTrip(t);
    else if (t.matches('[data-dr-noshow]')) document.getElementById('dr-noshow-dialog')?.showModal();
    else if (t.matches('[data-dr-noshow-confirm]')) {
        document.getElementById('dr-noshow-dialog')?.close();
        if (state.ride) stageAction(fill(urls.no_show, state.ride.id), {}, 'No-show recorded.');
    } else if (t.matches('[data-dr-complete]')) document.getElementById('dr-complete-dialog')?.showModal();
    else if (t.matches('[data-dr-complete-confirm]')) {
        document.getElementById('dr-complete-dialog')?.close();
        completeTrip();
    } else if (t.matches('[data-dr-cancel-confirm]')) {
        const reason = $('[data-dr-cancel-form] input[name="reason"]:checked')?.value;
        if (!reason) {
            toast('Choose a reason.', 'error');
            return;
        }
        document.getElementById('dr-cancel-dialog')?.close();
        if (state.ride) stageAction(fill(urls.cancel, state.ride.id), { reason }, 'Ride cancelled.');
    } else if (t.matches('[data-dr-map-show]')) showMap();
    else if (t.matches('[data-dr-summary-done]')) closeSummary();
    else if (t.matches('[data-dr-sos-open]')) openSos();
    else if (t.matches('[data-dr-sos-cancel]')) {
        clearInterval(sosTimer);
        $('[data-dr-sos]').hidden = true;
        toast('SOS cancelled. Nothing was sent.', 'info');
    } else if (t.matches('[data-dr-sos-close]')) $('[data-dr-sos]').hidden = true;
});

document.addEventListener('submit', (e) => {
    if (e.target.matches('[data-dr-decline-form]')) {
        e.preventDefault();
        declineOffer(new FormData(e.target).get('reason'));
    } else if (e.target.matches('[data-dr-cash-form]')) {
        e.preventDefault();
        confirmCash(e.target);
    }
});

// Keyboard digits on the PIN pad (hardware keyboards, accessibility).
document.addEventListener('keydown', (e) => {
    if (state.ride?.status !== 'driver_arrived' || $('[data-dr-pin]')?.hidden) return;
    if (e.target.matches('input, textarea, select')) return;
    if (/^[0-9]$/.test(e.key)) pin.press(e.key);
    else if (e.key === 'Backspace') pin.back();
    else return;
    renderPin();
});

if (root) {
    try {
        const pending = sessionStorage.getItem(SUMMARY_KEY);
        if (pending) state.summaryRideId = Number(pending);
    } catch {
        /* no storage */
    }
    saverCheck();
    applyFeed(boot.feed);
    if (state.summaryRideId) loadSummary(0);
    if (online()) keepAwake();
    state.pollTimer = setTimeout(poll, 1500);
}
