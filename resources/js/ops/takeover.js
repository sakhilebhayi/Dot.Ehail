// The live board's incident rail (R-46 polish, D-2202): redraws the server-rendered cards from the feed and runs the
// optional alarm. The alarm is off until the operator turns it on (browsers allow sound only after a click on the
// page); once on, it sounds when an emergency nobody owns appears, and every 30 s while one still has no owner.

const REPEAT_MS = 30000;

/** "2 emergencies open, 1 with nobody owning it" */
export function takeoverTitle(items) {
    const n = items.length;
    const unowned = items.filter((i) => !i.owner).length;
    const head = `${n} ${n === 1 ? 'emergency' : 'emergencies'} open`;
    return unowned ? `${head}, ${unowned} with nobody owning ${unowned === 1 ? 'it' : 'them'}` : head;
}

/** Ids of unowned emergencies not in `seen` (the ones that should sound now). */
export function newUnowned(seen, items) {
    return items.filter((i) => !i.owner && !seen.has(i.id)).map((i) => i.id);
}

function fill(card, item) {
    card.toggleAttribute('data-unowned', !item.owner);
    const link = card.querySelector('[data-sos-link]');
    link.href = item.href;
    link.textContent = `${item.kind} ${item.reference}`;
    card.querySelector('[data-sos-who]').textContent = `${item.who}${item.ride_id ? ` · Ride ${item.ride_id}` : ''} `;
    const plate = card.querySelector('[data-sos-plate]');
    plate.hidden = !item.vehicle;
    plate.textContent = item.vehicle || '';
    card.querySelector('[data-sos-place]').textContent = item.place;
    card.querySelector('[data-sos-owner]').textContent = item.owner_text;
    card.querySelector('[data-sos-level]').textContent = item.next_text ? `${item.level_text} · ${item.next_text}` : item.level_text;
    card.querySelector('[data-sos-time]').textContent = item.opened_text;
    const button = card.querySelector('[data-sos-open]');
    button.href = item.href;
    button.classList.toggle('eh-btn--danger', !item.owner);
    button.classList.toggle('eh-btn--secondary', !!item.owner);
    button.querySelector('.eh-btn__label').textContent = item.owner ? 'Open' : 'Open and take it';
}

export function renderTakeover(root, items) {
    const section = root.querySelector('[data-live-takeover]');
    const template = section?.querySelector('template[data-sos-card]');
    if (!section || !template) return;
    section.hidden = items.length === 0;
    root.querySelectorAll('[data-sos-legend]').forEach((el) => { el.hidden = items.length === 0; });
    section.querySelector('[data-sos-title]').textContent = takeoverTitle(items);
    const list = section.querySelector('[data-sos-list]');
    list.replaceChildren(...items.map((item) => {
        const card = template.content.firstElementChild.cloneNode(true);
        fill(card, item);
        return card;
    }));
}

function tone(ctx) {
    const now = ctx.currentTime;
    const gain = ctx.createGain();
    gain.connect(ctx.destination);
    gain.gain.setValueAtTime(0.0001, now);
    [880, 660, 880, 660].forEach((hz, n) => {
        const osc = ctx.createOscillator();
        osc.type = 'square';
        osc.frequency.value = hz;
        osc.connect(gain);
        const at = now + n * 0.22;
        gain.gain.setValueAtTime(0.12, at);
        gain.gain.setValueAtTime(0.0001, at + 0.18);
        osc.start(at);
        osc.stop(at + 0.2);
    });
}

export function createAlarm(section) {
    const button = section?.querySelector('[data-sos-sound]');
    const Ctx = typeof window !== 'undefined' ? (window.AudioContext || window.webkitAudioContext) : null;
    const seen = new Set();
    let ctx = null;
    let on = false;
    let latest = [];
    let timer = null;

    const label = () => {
        if (!button) return;
        button.setAttribute('aria-pressed', String(on));
        button.querySelector('[data-sos-sound-label]').textContent = on ? 'Alarm sound on' : 'Alarm sound off';
        button.querySelector('[data-sos-icon-on]').hidden = !on;
        button.querySelector('[data-sos-icon-off]').hidden = on;
    };
    const ring = () => { if (on && ctx) tone(ctx); };
    const schedule = () => {
        clearInterval(timer);
        timer = on ? setInterval(() => { if (latest.some((i) => !i.owner)) ring(); }, REPEAT_MS) : null;
    };

    if (button && !Ctx) {
        button.disabled = true;
        const hint = section.querySelector('[data-sos-sound-hint]');
        if (hint) hint.textContent = 'This browser cannot play the alarm; watch this rail.';
    }
    button?.addEventListener('click', async () => {
        on = !on;
        if (on) {
            try {
                ctx ??= new Ctx();
                await ctx.resume();
                tone(ctx);
            } catch {
                on = false;
            }
        }
        label();
        schedule();
    });

    return {
        update(items) {
            latest = items;
            const fresh = newUnowned(seen, items);
            items.forEach((i) => seen.add(i.id));
            if (fresh.length) ring();
        },
    };
}
