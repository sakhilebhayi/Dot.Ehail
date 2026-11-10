// The passenger app's list pages: safety centre, "what we did", account (cards, receipts, saved places) and help
// cases. Each page names itself with data-pax-page; only its part runs.

import { createPlaceSearch, loadGeoConfig, locateOnce } from '../geo/index.js';
import { announce, api, island } from './api.js';
import { el, icon } from './dom.js';
import { formatMinor, parseAmountToMinor } from './money.js';
import { bindShare, bindSos } from './safety.js';

const page = document.querySelector('[data-pax-page]')?.dataset.paxPage;
const data = island('pax-page-data') || {};
const url = (template, id) => template.replace('__ID__', String(id));
const toast = (message, tone = 'neutral') => window.ehToast?.(message, { tone });
const busy = (button, on) => {
    if (!button) return;
    button.disabled = on;
    on ? button.setAttribute('aria-busy', 'true') : button.removeAttribute('aria-busy');
};
const when = (iso) => (iso ? new Date(iso).toLocaleString('en-ZA', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '');
const row = (title, meta, trail = null, lead = null) =>
    el('li', { class: 'eh-row' }, [lead ? el('span', { class: 'eh-row__icon' }, lead) : null, el('span', { class: 'eh-row__main' }, [el('span', { class: 'eh-row__title' }, title), meta ? el('span', { class: 'eh-row__meta' }, meta) : null]), trail ? el('span', { class: 'eh-row__trail' }, trail) : null]);
const textButton = (label, onclick, variant = 'ghost') => el('button', { type: 'button', class: `eh-btn eh-btn--${variant} eh-btn--sm`, onclick }, el('span', { class: 'eh-btn__label' }, label));
const formData = (form) => Object.fromEntries([...new FormData(form).entries()].filter(([, v]) => typeof v === 'string' && v.trim() !== ''));

// ------------------------------------------------------------------ safety centre
async function safetyPage() {
    const list = document.querySelector('[data-pax-contacts]');
    const form = document.querySelector('[data-pax-contact-form]');

    const draw = (body) => {
        list.removeAttribute('aria-busy');
        const contacts = body?.data || [];
        if (!contacts.length) {
            list.replaceChildren(row('No trusted contacts yet', 'Add someone below. They confirm by email before they receive anything.'));
        } else {
            list.replaceChildren(
                ...contacts.map((c) => {
                    const state = c.state === 'confirmed' ? el('span', { class: 'eh-badge eh-badge--go' }, 'Confirmed') : c.state === 'awaiting_confirmation' ? el('span', { class: 'eh-badge eh-badge--caution' }, 'Waiting for them to confirm') : el('span', { class: 'eh-badge' }, c.state.replaceAll('_', ' '));
                    const actions = el('span', { style: 'display:flex;gap:var(--eh-space-1);flex-wrap:wrap;justify-content:flex-end' }, [
                        c.state === 'awaiting_confirmation' ? textButton('Resend', (e) => act(e.currentTarget, 'POST', url(data.urls.contactResend, c.id), 'Confirmation email sent again.')) : null,
                        textButton('Remove', (e) => act(e.currentTarget, 'DELETE', url(data.urls.contactDestroy, c.id), `${c.name} removed.`)),
                    ]);
                    return el('li', { class: 'eh-row' }, [el('span', { class: 'eh-row__main' }, [el('span', { class: 'eh-row__title' }, [c.name, ' ', state]), el('span', { class: 'eh-row__meta' }, [c.email, c.phone ? `, ${c.phone}` : '', c.relationship ? `, ${c.relationship}` : ''].join(''))]), el('span', { class: 'eh-row__trail' }, actions)]);
                }),
            );
        }
        form.hidden = contacts.length >= (body?.max ?? 5);
    };

    const load = async () => {
        try {
            draw((await api(data.urls.contacts)).data);
        } catch (error) {
            list.removeAttribute('aria-busy');
            list.replaceChildren(row('Contacts could not load', error.message, textButton('Try again', load)));
        }
    };

    const act = async (button, method, target, done) => {
        busy(button, true);
        try {
            await api(target, { method });
            toast(done, 'go');
            await load();
        } catch (error) {
            toast(error.message, 'stop');
            busy(button, false);
        }
    };

    form?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const status = form.querySelector('[data-pax-contact-status]');
        const button = form.querySelector('button[type="submit"]');
        busy(button, true);
        try {
            const { data: saved } = await api(data.urls.contactsStore, { method: 'POST', body: formData(form) });
            status.textContent = saved.message;
            form.reset();
            await load();
        } catch (error) {
            status.textContent = error.message;
        } finally {
            busy(button, false);
        }
    });

    const incident = document.querySelector('[data-pax-incident-form]');
    incident?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const status = incident.querySelector('[data-pax-incident-status]');
        const button = incident.querySelector('button[type="submit"]');
        const body = formData(incident);
        if (body.ride_id) body.ride_id = Number(body.ride_id);
        busy(button, true);
        try {
            const { data: saved } = await api(data.urls.incidents, { method: 'POST', body });
            window.location.assign(url(data.urls.incidentPage, saved.what_we_did.incident.id));
        } catch (error) {
            status.textContent = error.message;
            busy(button, false);
        }
    });

    // Pending check-ins (answering them happens on the ride page; here they are listed with a link).
    try {
        const { data: pending } = await api(data.urls.checkIns);
        if (pending?.data?.length) {
            document.querySelector('[data-pax-checkin-list]').hidden = false;
            document.querySelector('[data-pax-checkins]').replaceChildren(...pending.data.map((c) => el('li', {}, el('a', { class: 'eh-row', href: `/ride/trips/${c.ride_id}` }, [el('span', { class: 'eh-row__main' }, [el('span', { class: 'eh-row__title' }, c.question), el('span', { class: 'eh-row__meta' }, 'Open the ride to answer')])]))));
        }
    } catch {
        // Not essential for this page.
    }

    if (data.urls.shares) {
        const share = bindShare(document.querySelector('[data-pax-share-box]'), { createUrl: data.urls.shares, revokeUrl: data.urls.shareRevoke });
        document.querySelector('[data-pax-share-open]')?.addEventListener('click', (e) => share.open(e.currentTarget));
    }
    const openSos = bindSos(document.getElementById('pax-sos'));
    document.querySelectorAll('[data-pax-sos-open]').forEach((b) => b.addEventListener('click', openSos));

    load();
}

// ------------------------------------------------------------------ "what we did"
function incidentPage() {
    document.querySelector('[data-pax-focus]')?.focus();
    const out = document.querySelector('[data-pax-incident-action]');
    document.querySelector('[data-pax-safe]')?.addEventListener('click', async (event) => {
        const button = event.currentTarget;
        busy(button, true);
        try {
            const { data: done } = await api(button.dataset.url, { method: 'POST' });
            out.textContent = done.message;
            button.hidden = true;
        } catch (error) {
            out.textContent = error.message;
            busy(button, false);
        }
    });
    document.querySelector('[data-pax-incident-location]')?.addEventListener('click', async (event) => {
        const button = event.currentTarget;
        busy(button, true);
        try {
            const fix = await locateOnce({ timeoutMs: 10000 });
            await api(button.dataset.url, { method: 'POST', body: { lat: fix.lat, lng: fix.lng, accuracy_m: Math.round(fix.accuracy || 0) } });
            out.textContent = `Location sent (within ${Math.round(fix.accuracy)} m).`;
        } catch (error) {
            out.textContent = error.message;
        } finally {
            busy(button, false);
        }
    });
}

// ------------------------------------------------------------------ account
async function accountPage() {
    const methods = document.querySelector('[data-pax-methods]');
    const actions = document.querySelector('[data-pax-wallet-actions]');
    const note = document.querySelector('[data-pax-card-note]');

    const drawWallet = (wallet) => {
        methods.removeAttribute('aria-busy');
        const items = (wallet.methods || []).map((m) => {
            const trail = el('span', { style: 'display:flex;gap:var(--eh-space-1);flex-wrap:wrap;justify-content:flex-end' }, [
                m.is_default ? el('span', { class: 'eh-badge eh-badge--go' }, 'Default') : m.type === 'card' && m.chargeable ? textButton('Make default', (e) => walletAct(e.currentTarget, 'POST', url(data.urls.methodDefault, m.id), 'Default card changed.')) : null,
                textButton('Remove', (e) => walletAct(e.currentTarget, 'DELETE', url(data.urls.methodDestroy, m.id), 'Card removed. Its saved token was deleted.')),
            ]);
            const meta = m.type === 'card' ? `${m.expired ? 'Expired' : `Expires ${m.exp_month}/${String(m.exp_year).slice(-2)}`}${m.bank ? `, ${m.bank}` : ''}${!m.chargeable && !m.expired ? ', cannot be charged' : ''}` : 'Business account';
            return row(m.label, meta, trail, icon(m.type === 'card' ? 'card' : 'briefcase'));
        });
        if (wallet.cash?.enabled) items.push(row('Cash', wallet.cash.categories?.length ? `Accepted on: ${wallet.cash.categories.join(', ')}` : 'Accepted by drivers who take cash. Choose it when you book.', null, icon('cash')));
        if (!items.length) items.push(row('No payment methods yet', wallet.card_payments?.available ? 'Add a card to pay automatically after each trip.' : 'Card payments are not switched on yet.'));
        methods.replaceChildren(...items);
        actions.hidden = !wallet.card_payments?.available;
        note.textContent = wallet.card_payments?.available ? `Adding a card makes a ${formatMinor(wallet.card_setup_charge_minor || 100)} check through Paystack that is refunded straight away. Dot.Ehail never sees your card number.${wallet.card_payments.test_mode ? ' Payments are in test mode.' : ''}` : wallet.card_payments?.notice || '';
        const notice = document.querySelector('[data-pax-wallet-notice]');
        if (wallet.payment_due?.blocked) notice.textContent = wallet.payment_due.reason;
    };

    const loadWallet = async () => {
        try {
            drawWallet((await api(data.urls.wallet)).data);
        } catch (error) {
            methods.removeAttribute('aria-busy');
            methods.replaceChildren(row('Payment methods could not load', error.message, textButton('Try again', loadWallet)));
        }
    };

    const walletAct = async (button, method, target, done) => {
        busy(button, true);
        try {
            await api(target, { method });
            toast(done, 'go');
            await loadWallet();
        } catch (error) {
            toast(error.message, 'stop');
            busy(button, false);
        }
    };

    document.querySelector('[data-pax-add-card]')?.addEventListener('click', async (event) => {
        const button = event.currentTarget;
        busy(button, true);
        try {
            const { data: started } = await api(data.urls.addCard, { method: 'POST' });
            window.location.assign(started.authorization_url);
        } catch (error) {
            toast(error.message, 'stop');
            busy(button, false);
        }
    });

    // Receipts
    const receipts = document.querySelector('[data-pax-receipts]');
    api(data.urls.receipts)
        .then(({ data: page }) => {
            receipts.removeAttribute('aria-busy');
            const items = (page.data || []).map((r) =>
                el('li', {}, el('a', { class: 'eh-row', href: r.url }, [el('span', { class: 'eh-row__icon' }, icon('receipt')), el('span', { class: 'eh-row__main' }, [el('span', { class: 'eh-row__title' }, `${r.number}, ${formatMinor(r.total_minor, r.currency)}`), el('span', { class: 'eh-row__meta' }, `${r.kind === 'ride' ? 'Ride' : r.kind.replaceAll('_', ' ')} ${r.ride_id ?? ''}, ${when(r.issued_at)}`)]), el('span', { class: 'eh-row__trail' }, icon('chevron-right'))])),
            );
            receipts.replaceChildren(...(items.length ? items : [row('No receipts yet', 'A receipt is issued for every paid trip.')]));
        })
        .catch((error) => {
            receipts.removeAttribute('aria-busy');
            receipts.replaceChildren(row('Receipts could not load', error.message));
        });

    // Saved places
    const placeForm = document.querySelector('[data-pax-place-form]');
    const status = placeForm.querySelector('[data-pax-place-status]');
    const save = placeForm.querySelector('[data-pax-place-save]');
    const labelBox = placeForm.querySelector('[data-pax-place-label]');
    let chosen = null;
    placeForm.querySelectorAll('input[name="kind"]').forEach((radio) => radio.addEventListener('change', () => (labelBox.hidden = radio.value !== 'other' || !radio.checked)));
    const config = await loadGeoConfig();
    createPlaceSearch(placeForm.querySelector('[data-pax-search="place"]'), {
        config,
        onSelect: (place) => {
            chosen = place;
            save.disabled = false;
            status.textContent = `Ready to save: ${place.label}`;
        },
    });
    placeForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!chosen) return;
        const kind = placeForm.querySelector('input[name="kind"]:checked')?.value || 'other';
        busy(save, true);
        try {
            await api(data.urls.places, { method: 'POST', body: { kind, label: placeForm.querySelector('#place-label').value || null, address: chosen.label, lat: chosen.lat, lng: chosen.lng } });
            window.location.reload();
        } catch (error) {
            status.textContent = error.message;
            busy(save, false);
        }
    });
    document.querySelectorAll('[data-pax-place-remove]').forEach((button) =>
        button.addEventListener('click', async () => {
            busy(button, true);
            try {
                await api(url(data.urls.placeDestroy, button.dataset.paxPlaceRemove), { method: 'DELETE' });
                button.closest('li').remove();
                announce('Place removed.');
            } catch (error) {
                toast(error.message, 'stop');
                busy(button, false);
            }
        }),
    );

    loadWallet();
}

// ------------------------------------------------------------------ help cases
const STATUS_WORDS = { new: 'Received', triaged: 'Being looked at', in_progress: 'In progress', waiting_on_user: 'Waiting for your reply', resolved: 'Resolved', closed: 'Closed' };

async function supportIndex() {
    const list = document.querySelector('[data-pax-cases]');
    try {
        const { data: body } = await api(data.urls.cases);
        list.removeAttribute('aria-busy');
        const cases = body.data || [];
        list.replaceChildren(
            ...(cases.length
                ? cases.map((c) => el('li', {}, el('a', { class: 'eh-row', href: url(data.urls.case, c.id) }, [el('span', { class: 'eh-row__main' }, [el('span', { class: 'eh-row__title' }, c.subject), el('span', { class: 'eh-row__meta' }, `${c.reference}, ${c.category_label}, opened ${when(c.opened_at)}`)]), el('span', { class: 'eh-row__trail' }, el('span', { class: `eh-badge ${c.status === 'waiting_on_user' ? 'eh-badge--caution' : c.status === 'resolved' ? 'eh-badge--go' : ''}` }, STATUS_WORDS[c.status] || c.status))])))
                : [row('No help cases', 'When you open one, you can follow it and reply here.')]),
        );
    } catch (error) {
        list.removeAttribute('aria-busy');
        list.replaceChildren(row('Cases could not load', error.message));
    }
}

function supportCreate() {
    const form = document.querySelector('[data-pax-case-form]');
    const category = form.querySelector('[name="category"]');
    const rideBox = form.querySelector('[data-pax-case-ride]');
    const amountBox = form.querySelector('[data-pax-case-amount]');
    const sync = () => {
        rideBox.querySelector('select').required = (data.needsRide || []).includes(category.value);
        amountBox.hidden = category.value !== 'fare_dispute';
    };
    category.addEventListener('change', sync);
    sync();
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const status = form.querySelector('[data-pax-case-status]');
        const button = form.querySelector('button[type="submit"]');
        const body = new FormData(form);
        body.delete('requested_amount');
        const amount = form.querySelector('[name="requested_amount"]').value.trim();
        if (category.value === 'fare_dispute' && amount) {
            const minor = parseAmountToMinor(amount);
            if (minor === null) {
                status.textContent = 'Write the amount like 85.00, or leave it empty.';
                return;
            }
            body.append('requested_amount_minor', String(minor));
        }
        if (!body.get('ride_id')) body.delete('ride_id');
        if (!(body.get('attachment') instanceof File) || !body.get('attachment').size) body.delete('attachment');
        busy(button, true);
        try {
            const { data: saved } = await api(data.urls.store, { method: 'POST', body, form: true, timeoutMs: 30000 });
            window.location.assign(url(data.urls.case, saved.data.id));
        } catch (error) {
            status.textContent = error.message;
            busy(button, false);
        }
    });
}

async function supportShow() {
    const thread = document.querySelector('[data-pax-thread]');
    const meta = document.querySelector('[data-pax-case-meta]');
    const load = async () => {
        const { data: body } = await api(data.urls.show);
        const c = body.data;
        document.querySelector('[data-pax-case-subject]').textContent = c.subject;
        meta.textContent = `${c.reference}, ${c.category_label}, ${STATUS_WORDS[c.status] || c.status}${c.resolution ? `: ${c.resolution}` : ''}`;
        thread.removeAttribute('aria-busy');
        thread.replaceChildren(
            ...body.messages.map((m) =>
                el('li', { class: 'pax-msg', 'data-mine': String(m.from === 'you') }, [
                    el('p', { class: 'pax-msg__who' }, `${m.from === 'you' ? 'You' : m.from}, ${when(m.at)}`),
                    m.body ? el('p', { class: 'pax-msg__body' }, m.body) : null,
                    m.attachment ? el('a', { class: 'eh-link', href: m.attachment.url }, `Attachment: ${m.attachment.name}`) : null,
                ]),
            ),
        );
    };
    try {
        await load();
    } catch (error) {
        thread.removeAttribute('aria-busy');
        thread.replaceChildren(el('li', {}, error.message));
    }
    const form = document.querySelector('[data-pax-reply]');
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const status = form.querySelector('[data-pax-reply-status]');
        const button = form.querySelector('button[type="submit"]');
        const body = new FormData(form);
        if (!(body.get('attachment') instanceof File) || !body.get('attachment').size) body.delete('attachment');
        busy(button, true);
        try {
            await api(data.urls.reply, { method: 'POST', body, form: true, timeoutMs: 30000 });
            form.reset();
            status.textContent = 'Sent.';
            await load();
        } catch (error) {
            status.textContent = error.message;
        } finally {
            busy(button, false);
        }
    });
}

({ safety: safetyPage, incident: incidentPage, account: accountPage, 'support-index': supportIndex, 'support-create': supportCreate, 'support-show': supportShow })[page]?.();
