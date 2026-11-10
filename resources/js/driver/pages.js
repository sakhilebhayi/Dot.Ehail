// Dot.Ehail driver app: behaviour for the pages around the console (documents, trips, safety, account).
// Small on purpose; every form here also works as a plain form where it can.

import { api, ApiError } from './api.js';
import { MotionMode, sharedMoving, shareMoving } from './motion.js';
import { NAV_APPS, readPrefs, writePrefs } from './nav.js';

const $ = (sel, el = document) => el.querySelector(sel);
const $$ = (sel, el = document) => [...el.querySelectorAll(sel)];

function toast(message, tone = 'info') {
    if (typeof window.ehToast === 'function') window.ehToast(message, { tone });
}

// ---------- minimal-distraction mode on pages with typing-heavy forms ----------
// The console shares its mode for 30 s. A page with forms also watches speed itself, but only when location is
// already allowed (it never asks here: a document page is not a reason to prompt for location).

function setMoving(moving) {
    document.documentElement.dataset.moving = String(moving);
}

async function watchMotion() {
    setMoving(sharedMoving());
    if (!$('[data-dr-typing]') || !navigator.geolocation || !navigator.permissions?.query) return;

    try {
        const status = await navigator.permissions.query({ name: 'geolocation' });
        if (status.state !== 'granted') return;
    } catch {
        return;
    }

    const mode = new MotionMode();
    navigator.geolocation.watchPosition(
        (pos) => {
            const c = pos.coords;
            const moving = mode.update({ lat: c.latitude, lng: c.longitude, speed: Number.isFinite(c.speed) ? c.speed : null, accuracy: c.accuracy, recorded_at: pos.timestamp || Date.now() });
            setMoving(moving);
            shareMoving(moving);
        },
        () => {},
        { enableHighAccuracy: false, maximumAge: 5000, timeout: 30000 },
    );
}

// ---------- document upload: progress, retry, size and type errors ----------

const IMAGE = /^image\/(jpeg|png|webp|heic|heif)$/i;

function checkFile(form, file) {
    const maxImage = Number(form.dataset.maxImageKb) * 1024;
    const maxPdf = Number(form.dataset.maxPdfKb) * 1024;
    const isPdf = file.type === 'application/pdf' || /\.pdf$/i.test(file.name);
    const isImage = IMAGE.test(file.type) || /\.(jpe?g|png|webp|heic|heif)$/i.test(file.name);

    if (!isPdf && !isImage) return 'That file type cannot be used. Send a photo (JPEG, PNG, WebP, HEIC) or a PDF.';
    if (isPdf && file.size > maxPdf) return `That PDF is ${(file.size / 1048576).toFixed(1)} MB; the limit is ${Math.floor(maxPdf / 1048576)} MB.`;
    if (isImage && file.size > maxImage) return `That photo is ${(file.size / 1048576).toFixed(1)} MB; the limit is ${Math.floor(maxImage / 1048576)} MB. Take it again at a lower resolution.`;

    return null;
}

function setMsg(form, text, tone) {
    const box = $('[data-dr-progress]', form);
    box.hidden = false;
    const msg = $('[data-dr-upload-msg]', form);
    msg.textContent = text;
    msg.dataset.tone = tone || '';
}

function upload(form, file) {
    const error = checkFile(form, file);
    if (error) {
        setMsg(form, error, 'stop');
        return;
    }
    const expiry = form.querySelector('input[name="expires_on"]');
    if (expiry && !expiry.value) {
        setMsg(form, 'Enter the expiry date printed on the document first, then choose the file again.', 'stop');
        expiry.focus();
        return;
    }

    const data = new FormData();
    data.append('_token', form.querySelector('input[name="_token"]').value);
    data.append('type', form.querySelector('input[name="type"]').value);
    const vehicle = form.querySelector('input[name="vehicle_id"]');
    if (vehicle) data.append('vehicle_id', vehicle.value);
    if (expiry?.value) data.append('expires_on', expiry.value);
    data.append('document', file, file.name);

    const bar = $('.dr-progress__bar span', form);
    const retry = $('[data-dr-upload-retry]', form);
    retry.hidden = true;
    form.setAttribute('aria-busy', 'true');
    setMsg(form, 'Sending…');
    bar.style.transform = 'scaleX(0)';

    const xhr = new XMLHttpRequest();
    xhr.open('POST', form.action);
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.upload.addEventListener('progress', (e) => {
        if (e.lengthComputable) {
            const pct = Math.round((e.loaded / e.total) * 100);
            bar.style.transform = `scaleX(${pct / 100})`;
            setMsg(form, `Sending… ${pct}%`);
        }
    });
    xhr.addEventListener('load', () => {
        form.removeAttribute('aria-busy');
        let body = {};
        try {
            body = JSON.parse(xhr.responseText || '{}');
        } catch {
            body = {};
        }
        if (xhr.status >= 200 && xhr.status < 300) {
            bar.style.transform = 'scaleX(1)';
            setMsg(form, body.message || 'Sent.', 'go');
            setTimeout(() => window.location.reload(), 1200);
        } else if (xhr.status === 422) {
            const first = Object.values(body.errors || {})[0];
            setMsg(form, (Array.isArray(first) ? first[0] : body.message) || 'That file was refused.', 'stop');
        } else if (xhr.status === 429) {
            setMsg(form, 'Too many uploads in a short time. Wait a minute, then try again.', 'stop');
            retry.hidden = false;
        } else {
            setMsg(form, body.message || `The upload failed (${xhr.status}). Try again.`, 'stop');
            retry.hidden = false;
        }
    });
    xhr.addEventListener('error', () => {
        form.removeAttribute('aria-busy');
        setMsg(form, 'The upload did not reach Dot.Ehail (no connection). Your file is kept here; try again when you have signal.', 'stop');
        retry.hidden = false;
    });
    form._pending = file;
    xhr.send(data);
}

function bindUploads() {
    $$('[data-dr-upload]').forEach((form) => {
        // With script the file sends as soon as it is chosen; the Send button is for a plain form post.
        $('[data-dr-upload-submit]', form).hidden = true;
        $$('[data-dr-file]', form).forEach((input) =>
            input.addEventListener('change', () => {
                const file = input.files?.[0];
                if (file) upload(form, file);
                input.value = '';
            }),
        );
        $('[data-dr-upload-retry]', form).addEventListener('click', () => form._pending && upload(form, form._pending));
    });
}

// ---------- small JSON forms (rating, report, appeal, support) ----------

function bindJsonForms() {
    $$('form[data-dr-json]').forEach((form) => {
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const button = form.querySelector('[type="submit"]');
            if (button) button.disabled = true;
            const body = Object.fromEntries([...new FormData(form).entries()].filter(([k, v]) => k !== '_token' && v !== ''));
            try {
                const { data } = await api(form.action, { method: form.dataset.method || 'POST', body });
                const done = form.dataset.done || data?.message || 'Sent.';
                const out = document.createElement('p');
                out.className = 'dr-note';
                out.setAttribute('role', 'status');
                out.textContent = done;
                form.replaceWith(out);
                toast(done, 'success');
                if (form.dataset.reload) setTimeout(() => window.location.reload(), 900);
            } catch (err) {
                const first = err instanceof ApiError ? Object.values(err.data.errors || {})[0] : null;
                toast((Array.isArray(first) ? first[0] : err.message) || 'Could not send.', 'error');
                if (button) button.disabled = false;
            }
        });
    });
}

// ---------- this phone's app settings (account page) ----------

function bindPrefs() {
    const form = $('[data-dr-prefs]');
    if (!form) return;
    const prefs = readPrefs();
    $$('input[name="nav"]', form).forEach((r) => (r.checked = r.value === prefs.nav));
    $('input[name="sound"]', form).checked = prefs.sound;
    $('input[name="vibrate"]', form).checked = prefs.vibrate;
    const sync = () => {
        $$('[data-dr-switch-state]', form).forEach((el) => (el.textContent = $(`input[name="${el.dataset.drSwitchState}"]`, form).checked ? 'On' : 'Off'));
    };
    sync();
    form.addEventListener('change', () => {
        const ok = writePrefs({
            nav: $('input[name="nav"]:checked', form)?.value || 'google',
            sound: $('input[name="sound"]', form).checked,
            vibrate: $('input[name="vibrate"]', form).checked,
        });
        sync();
        toast(ok ? `Saved on this phone. Navigation opens in ${NAV_APPS[readPrefs().nav]}.` : 'This browser would not save settings (private mode?).', ok ? 'success' : 'error');
    });
}

// ---------- SOS from the safety page (only when on a trip) ----------

function bindSos() {
    const open = $('[data-dr-page-sos]');
    if (!open) return;
    const box = $('[data-dr-sos]');
    let timer = null;

    open.addEventListener('click', () => {
        box.hidden = false;
        $('[data-dr-sos-arming]').hidden = false;
        $('[data-dr-sos-result]').hidden = true;
        let n = 3;
        $('[data-dr-sos-count]').textContent = String(n);
        $('[data-dr-sos-cancel]').focus();
        timer = setInterval(async () => {
            n--;
            $('[data-dr-sos-count]').textContent = String(Math.max(0, n));
            if (n > 0) return;
            clearInterval(timer);
            $('[data-dr-sos-arming]').hidden = true;
            $('[data-dr-sos-result]').hidden = false;
            const list = $('[data-dr-sos-done]');
            try {
                const { data } = await api(open.dataset.url, { method: 'POST', body: {} });
                list.replaceChildren(
                    ...data.what_we_did.actions.map((a) => {
                        const li = document.createElement('li');
                        li.textContent = `${a.what}${a.status_text ? `: ${a.status_text}` : ''}${a.why ? ` (${a.why})` : ''}`;

                        return li;
                    }),
                );
                $('[data-dr-sos-not]').textContent = (data.what_we_did.not_done || []).join(' ');
            } catch {
                const li = document.createElement('li');
                li.textContent = 'The SOS could not reach Dot.Ehail (no connection). Call for help with the numbers below.';
                list.replaceChildren(li);
            }
        }, 1000);
    });
    $('[data-dr-sos-cancel]').addEventListener('click', () => {
        clearInterval(timer);
        box.hidden = true;
        toast('SOS cancelled. Nothing was sent.', 'info');
    });
    $('[data-dr-sos-close]').addEventListener('click', () => (box.hidden = true));
}

watchMotion();
bindUploads();
bindJsonForms();
bindPrefs();
bindSos();
