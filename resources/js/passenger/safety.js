// Safety tools used on the live ride and the safety centre: the SOS dialog (3-second cancellable countdown),
// "are you OK?" check-ins, and trip share links (create, copy, share sheet, revoke).

import { locateOnce } from '../geo/locate.js';
import { announce, api } from './api.js';
import { copyText } from './dom.js';

export const SOS_COUNTDOWN_S = 3;

/** The countdown's states as a pure sequence: 3, 2, 1, then send. */
export function countdownSteps(seconds = SOS_COUNTDOWN_S) {
    return Array.from({ length: seconds }, (_, i) => seconds - i);
}

/** Opens the SOS dialog and runs the flow. Returns a function that opens it. */
export function bindSos(dialog) {
    if (!dialog) return () => {};
    const steps = dialog.querySelectorAll('[data-sos-step]');
    const show = (name) => steps.forEach((s) => (s.hidden = s.dataset.sosStep !== name));
    let timer = null;

    const cancel = () => {
        clearInterval(timer);
        timer = null;
        show('ask');
        announce('SOS cancelled. Nothing was sent.', { assertive: true });
        dialog.querySelector('[data-sos-start]')?.focus();
    };

    const send = async () => {
        show('sending');
        // A position helps only if it comes quickly; never hold the alert for it.
        const fix = await Promise.race([locateOnce({ timeoutMs: 2500, maximumAgeMs: 60000 }).catch(() => null), new Promise((r) => setTimeout(() => r(null), 2500))]);
        try {
            const body = fix ? { lat: fix.lat, lng: fix.lng, accuracy_m: Math.round(fix.accuracy || 0) } : {};
            const { data } = await api(dialog.dataset.url, { method: 'POST', body, timeoutMs: 20000 });
            const id = data?.what_we_did?.incident?.id;
            announce('SOS sent. Showing what we did.', { assertive: true });
            window.location.assign(dialog.dataset.incidentUrl.replace('__ID__', String(id)));
        } catch (error) {
            show('failed');
            const message = dialog.querySelector('[data-sos-error]');
            if (message) message.textContent = `The SOS could not be sent: ${error.message}`;
            dialog.querySelector('[data-sos-retry]')?.focus();
        }
    };

    const start = () => {
        show('count');
        const sequence = countdownSteps();
        let index = 0;
        const count = dialog.querySelector('[data-sos-count]');
        const text = dialog.querySelector('[data-sos-count-text]');
        const render = () => {
            count.textContent = String(sequence[index]);
            text.textContent = `Sending SOS in ${sequence[index]} ${sequence[index] === 1 ? 'second' : 'seconds'}.`;
        };
        render();
        dialog.querySelector('[data-sos-cancel]')?.focus();
        timer = setInterval(() => {
            index++;
            if (index >= sequence.length) {
                clearInterval(timer);
                timer = null;
                send();
            } else {
                render();
            }
        }, 1000);
    };

    dialog.querySelector('[data-sos-start]')?.addEventListener('click', start);
    dialog.querySelector('[data-sos-cancel]')?.addEventListener('click', cancel);
    dialog.querySelector('[data-sos-retry]')?.addEventListener('click', send);
    dialog.addEventListener('close', () => {
        if (timer) cancel();
        show('ask');
    });

    return () => {
        show('ask');
        dialog.showModal();
    };
}

/** Polls the person's pending check-ins while a ride is on, and asks. */
export function bindCheckIns(dialog, { rideId = null, every = 20000 } = {}) {
    if (!dialog) return { stop() {} };
    let current = null;
    let timer = null;
    let stopped = false;
    const error = dialog.querySelector('[data-checkin-error]');

    const poll = async () => {
        if (stopped) return;
        if (!document.hidden && !dialog.open) {
            try {
                const { data } = await api(dialog.dataset.listUrl);
                current = (data?.data || []).find((c) => rideId === null || c.ride_id === rideId) || null;
                if (current) {
                    dialog.querySelector('[data-checkin-why]').textContent = current.question;
                    error.textContent = '';
                    dialog.showModal();
                    announce(current.question, { assertive: true });
                }
            } catch {
                // Try again on the next round.
            }
        }
        timer = setTimeout(poll, every);
    };

    dialog.querySelectorAll('[data-checkin-answer]').forEach((button) =>
        button.addEventListener('click', async () => {
            if (!current) return dialog.close();
            const answer = button.dataset.checkinAnswer;
            button.setAttribute('aria-busy', 'true');
            try {
                let body = { answer };
                if (answer === 'need_help') {
                    const fix = await locateOnce({ timeoutMs: 2500 }).catch(() => null);
                    if (fix) body = { ...body, lat: fix.lat, lng: fix.lng };
                }
                const { data } = await api(dialog.dataset.answerUrl.replace('__ID__', String(current.id)), { method: 'POST', body });
                dialog.close();
                if (answer === 'need_help' && data?.incident_id) {
                    window.location.assign(dialog.dataset.incidentUrl.replace('__ID__', String(data.incident_id)));
                } else {
                    window.ehToast?.('Thanks. We will keep following the trip.', { tone: 'go' });
                }
                current = null;
            } catch (failure) {
                error.textContent = failure.message;
            } finally {
                button.removeAttribute('aria-busy');
            }
        }),
    );

    timer = setTimeout(poll, 1500);
    return {
        stop() {
            stopped = true;
            clearTimeout(timer);
        },
    };
}

/**
 * Trip share: create a link (the URL is shown once by the server), copy it, hand it to the phone's share sheet
 * where the browser has one, revoke it.
 */
export function bindShare(box, { createUrl, revokeUrl, title = 'My Dot.Ehail trip' }) {
    if (!box) return { open() {} };
    const input = box.querySelector('[data-pax-share-url]');
    const native = box.querySelector('[data-pax-share-native]');
    let share = null;

    if (native) native.hidden = typeof navigator.share !== 'function';

    const open = async (trigger) => {
        trigger?.setAttribute('aria-busy', 'true');
        try {
            const { data } = await api(createUrl, { method: 'POST', body: {} });
            share = { id: data.data.id, url: data.url };
            input.value = data.url;
            box.hidden = false;
            input.focus();
            input.select();
            announce('Share link ready. Copy it or send it with another app.');
        } catch (error) {
            window.ehToast?.(error.message, { tone: 'stop' });
        } finally {
            trigger?.removeAttribute('aria-busy');
        }
    };

    box.querySelector('[data-pax-share-copy]')?.addEventListener('click', async () => {
        const ok = await copyText(input.value, input);
        window.ehToast?.(ok ? 'Link copied.' : 'Select the link and copy it.', { tone: ok ? 'go' : 'neutral' });
    });
    native?.addEventListener('click', () => navigator.share?.({ title, text: 'Follow my trip live:', url: input.value }).catch(() => {}));
    box.querySelector('[data-pax-share-revoke]')?.addEventListener('click', async (event) => {
        if (!share) return;
        const button = event.currentTarget;
        button.setAttribute('aria-busy', 'true');
        try {
            await api(revokeUrl.replace('__ID__', String(share.id)), { method: 'DELETE' });
            share = null;
            input.value = '';
            box.hidden = true;
            window.ehToast?.('Link revoked. Nobody can open it now.', { tone: 'go' });
        } catch (error) {
            window.ehToast?.(error.message, { tone: 'stop' });
        } finally {
            button.removeAttribute('aria-busy');
        }
    });

    return { open };
}
