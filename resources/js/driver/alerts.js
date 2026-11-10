// Offer alerts on this phone (D-2104): a two-note chime made with Web Audio (no sound file, nothing fetched) and a
// vibration pattern, each switched on or off by the driver and kept in this browser. Browsers only let a page make
// sound after a tap, so the audio is unlocked on the first tap anywhere and by the "Test sound" button.

import { readPrefs, writePrefs } from './nav.js';

export const VIBRATION = [200, 100, 200];

export function canVibrate(nav = globalThis.navigator) {
    return typeof nav?.vibrate === 'function';
}

export function createChime(win = globalThis.window) {
    let ctx = null;

    const unlock = () => {
        if (ctx) {
            ctx.resume?.().catch?.(() => {});
            return true;
        }
        try {
            const Ctx = win?.AudioContext || win?.webkitAudioContext;
            if (!Ctx) return false;
            ctx = new Ctx();
            ctx.resume?.().catch?.(() => {});

            return true;
        } catch {
            ctx = null;
            return false;
        }
    };

    /** Plays the chime; true when the audio clock is running (a tap has unlocked it). */
    const play = () => {
        if (!ctx) return false;
        try {
            ctx.resume?.().catch?.(() => {});
            [0, 0.22].forEach((at, i) => {
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.frequency.value = i === 0 ? 660 : 880;
                gain.gain.setValueAtTime(0.0001, ctx.currentTime + at);
                gain.gain.exponentialRampToValueAtTime(0.3, ctx.currentTime + at + 0.02);
                gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + at + 0.18);
                osc.connect(gain).connect(ctx.destination);
                osc.start(ctx.currentTime + at);
                osc.stop(ctx.currentTime + at + 0.2);
            });

            return ctx.state !== 'suspended';
        } catch {
            return false;
        }
    };

    return { unlock, play, get ready() { return Boolean(ctx) && ctx.state === 'running'; }, get supported() { return Boolean(win?.AudioContext || win?.webkitAudioContext); } };
}

/** What an offer does on this phone, per the stored settings. */
export function alertOffer(chime, prefs = readPrefs(), nav = globalThis.navigator) {
    if (prefs.vibrate && canVibrate(nav)) nav.vibrate(VIBRATION);
    if (prefs.sound) chime.play();
}

/**
 * Wires every [data-dr-alertset] on the page: the Sound and Vibrate switches (role="switch" checkboxes named sound and
 * vibrate), the Test sound button and the status line. Returns the chime so the console can alert with it.
 */
export function bindAlertSettings(doc = globalThis.document, chime = createChime()) {
    const say = (box, text) => {
        const out = box.querySelector('[data-dr-alert-test-result]');
        if (out) out.textContent = text;
    };
    // The first tap anywhere unlocks audio for the offers that come later.
    doc.addEventListener('pointerdown', () => chime.unlock(), { once: true, capture: true });
    doc.addEventListener('keydown', () => chime.unlock(), { once: true, capture: true });

    doc.querySelectorAll('[data-dr-alertset]').forEach((box) => {
        const prefs = readPrefs();
        const sound = box.querySelector('input[name="sound"]');
        const vibrate = box.querySelector('input[name="vibrate"]');
        if (sound) sound.checked = prefs.sound;
        if (vibrate) {
            vibrate.checked = prefs.vibrate && canVibrate();
            if (!canVibrate()) {
                vibrate.disabled = true;
                vibrate.closest('.eh-switch')?.setAttribute('data-unsupported', 'true');
            }
        }
        box.querySelectorAll('[data-dr-vibrate-unsupported]').forEach((el) => (el.hidden = canVibrate()));
        if (!chime.supported) box.querySelectorAll('[data-dr-sound-unsupported]').forEach((el) => (el.hidden = false));

        box.addEventListener('change', (e) => {
            if (!e.target.matches('input[name="sound"], input[name="vibrate"]')) return;
            const ok = writePrefs({ sound: Boolean(sound?.checked), vibrate: Boolean(vibrate?.checked) });
            say(box, ok ? `Saved on this phone. Sound ${sound?.checked ? 'on' : 'off'}, vibration ${vibrate?.checked ? 'on' : 'off'}.` : 'This browser would not save the setting (private mode?).');
        });

        box.querySelector('[data-dr-test-sound]')?.addEventListener('click', () => {
            const now = readPrefs();
            chime.unlock();
            const played = now.sound ? chime.play() : false;
            if (now.vibrate && canVibrate()) navigator.vibrate(VIBRATION);
            if (!now.sound) say(box, now.vibrate && canVibrate() ? 'Sound is off, so only the vibration plays. Switch Sound on to hear offers.' : 'Sound and vibration are both off: offers will only show on screen.');
            else if (!chime.supported) say(box, 'This browser cannot play the offer sound. Offers still show on screen.');
            else say(box, played ? 'Playing the offer sound. If you heard nothing, turn the phone’s media volume up and check silent mode.' : 'The phone did not start the sound. Tap Test sound again; some phones need a second tap.');
        });
    });

    return chime;
}
