// R-46 comms: the notification settings page. Push on this device, switch words, and the SMS number.
// Pure helpers are exported for tests (tests/js/comms.test.mjs); the page code runs only in a browser.

/** What this browser can do with push, from facts the page reads. */
export function pushSupport({ hasServiceWorker, hasPushManager, hasNotification, userAgent = '', standalone = false, permission = 'default', configured = true }) {
    if (!configured) return { state: 'not_configured', tone: 'neutral', text: 'Push notifications are not set up on this service yet, so this device cannot be switched on.' };
    const ios = /iPhone|iPad|iPod/.test(userAgent);
    if (!hasServiceWorker || !hasPushManager || !hasNotification) {
        if (ios && !standalone) {
            return { state: 'needs_home_screen', tone: 'caution', text: 'On iPhone and iPad, push works once Dot.Ehail is on your home screen: tap Share, then Add to Home Screen, open it from there and come back here.' };
        }
        return { state: 'unsupported', tone: 'caution', text: 'This browser cannot receive push notifications. Your inbox and email still reach you.' };
    }
    if (permission === 'denied') {
        return { state: 'blocked', tone: 'caution', text: 'Notifications are blocked for this site in your browser settings. Allow them there, then come back to turn push on.' };
    }
    return { state: 'supported', tone: 'neutral', text: '' };
}

/** The VAPID public key (base64url) as the bytes pushManager.subscribe wants. */
export function urlBase64ToUint8Array(base64) {
    const padding = '='.repeat((4 - (base64.length % 4)) % 4);
    const raw = atob((base64 + padding).replace(/-/g, '+').replace(/_/g, '/'));
    return Uint8Array.from(raw, (c) => c.charCodeAt(0));
}

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

async function call(url, method = 'POST', body = null) {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
        body: body ? JSON.stringify(body) : null,
    });
    let data = {};
    try { data = await response.json(); } catch { /* empty body */ }
    return { ok: response.ok, status: response.status, data };
}

function initPush(root) {
    const stateEl = root.querySelector('[data-push-state]');
    const onBtn = root.querySelector('[data-push-on]');
    const offBtn = root.querySelector('[data-push-off]');
    const testBtn = root.querySelector('[data-push-test]');
    const say = (text, tone = 'neutral') => { stateEl.textContent = text; stateEl.dataset.tone = tone; };
    const show = (on) => {
        if (onBtn) onBtn.hidden = on;
        if (offBtn) offBtn.hidden = !on;
        if (testBtn) testBtn.hidden = !on;
    };
    const busy = (button, value) => { if (button) { button.disabled = value; button.setAttribute('aria-busy', value ? 'true' : 'false'); } };

    const support = pushSupport({
        hasServiceWorker: 'serviceWorker' in navigator,
        hasPushManager: 'PushManager' in window,
        hasNotification: 'Notification' in window,
        userAgent: navigator.userAgent,
        standalone: window.matchMedia?.('(display-mode: standalone)').matches || navigator.standalone === true,
        permission: 'Notification' in window ? Notification.permission : 'default',
        configured: root.dataset.configured === 'true',
    });

    if (support.state !== 'supported') {
        say(support.text, support.tone);
        show(false);
        if (onBtn) onBtn.hidden = true;
        return;
    }

    let registration = null;

    const refresh = async () => {
        registration = await navigator.serviceWorker.register('/sw.js', { scope: '/' });
        await navigator.serviceWorker.ready;
        const subscription = await registration.pushManager.getSubscription();
        if (!subscription) {
            say('Push is off for this device.');
            show(false);
            return;
        }
        const { data } = await call(root.dataset.statusUrl, 'POST', { endpoint: subscription.endpoint });
        if (data.subscribed) {
            say('Push is on for this device.', 'go');
            show(true);
        } else {
            say('This browser has push from another account or an older key. Turn it on to receive your own.');
            show(false);
        }
    };

    onBtn?.addEventListener('click', async () => {
        busy(onBtn, true);
        try {
            const permission = await Notification.requestPermission();
            if (permission !== 'granted') {
                say(permission === 'denied'
                    ? 'Notifications are blocked for this site in your browser settings. Allow them there, then try again.'
                    : 'Push stays off: the browser did not get permission.', 'caution');
                return;
            }
            const existing = await registration.pushManager.getSubscription();
            if (existing) await existing.unsubscribe();
            const subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(root.dataset.publicKey),
            });
            const { ok, data } = await call(root.dataset.subscribeUrl, 'POST', subscription.toJSON());
            if (!ok) {
                await subscription.unsubscribe();
                say(data.message ?? 'Push could not be turned on. Try again.', 'caution');
                return;
            }
            say('Push is on for this device.', 'go');
            show(true);
        } catch {
            say('Push could not be turned on in this browser. Try again, or use another browser.', 'caution');
        } finally {
            busy(onBtn, false);
        }
    });

    offBtn?.addEventListener('click', async () => {
        busy(offBtn, true);
        try {
            const subscription = await registration.pushManager.getSubscription();
            if (subscription) {
                await call(root.dataset.subscribeUrl, 'DELETE', { endpoint: subscription.endpoint });
                await subscription.unsubscribe();
            }
            say('Push is off for this device.');
            show(false);
        } finally {
            busy(offBtn, false);
        }
    });

    testBtn?.addEventListener('click', async () => {
        busy(testBtn, true);
        try {
            const { data } = await call(root.dataset.testUrl);
            say(data.message ?? 'The test could not be sent.', data.accepted > 0 ? 'go' : 'caution');
        } finally {
            busy(testBtn, false);
        }
    });

    refresh().catch(() => say('Push could not be checked in this browser.', 'caution'));
}

function initSwitchWords(root) {
    root.querySelectorAll('[data-comms-switch]').forEach((input) => {
        const desc = document.getElementById(`${input.id}-desc`);
        input.addEventListener('change', () => { if (desc) desc.textContent = input.checked ? 'On' : 'Off'; });
    });
}

function initSms(root) {
    const message = root.querySelector('[data-sms-message]');
    const step = (name) => root.querySelectorAll('[data-sms-step]').forEach((el) => { el.hidden = el.dataset.smsStep !== name; });
    const value = (name) => root.querySelector(`[name="${name}"]`)?.value ?? '';

    root.querySelector('[data-sms-start]')?.addEventListener('click', async () => {
        const { ok, data } = await call(root.dataset.startUrl, 'POST', { number: value('sms_number') });
        message.textContent = ok ? `We sent a code to ${data.number}.` : (data.message ?? 'The code could not be sent.');
        if (ok) step('code');
    });
    root.querySelector('[data-sms-confirm]')?.addEventListener('click', async () => {
        const { ok, data } = await call(root.dataset.confirmUrl, 'POST', { code: value('sms_code') });
        message.textContent = ok ? `Confirmed. Safety SMS go to ${data.number}.` : (data.message ?? 'That code did not work.');
        if (ok) window.location.reload();
    });
    root.querySelector('[data-sms-remove]')?.addEventListener('click', async () => {
        const { ok } = await call(root.dataset.removeUrl, 'DELETE');
        if (ok) window.location.reload();
    });
}

if (typeof document !== 'undefined') {
    const start = () => {
        document.querySelectorAll('[data-comms-push]').forEach(initPush);
        initSwitchWords(document);
        document.querySelectorAll('[data-comms-sms]').forEach(initSms);
    };
    document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', start) : start();
}
