// Screen-reader announcements that inform without nagging (R-46 a11y, D-1702). A ride page changes every few
// seconds; reading every change aloud would drown the person. Rules, all pure and tested
// (tests/js/a11y-announce.test.mjs):
//   - the same sentence is never repeated back to back;
//   - a "topic" (the ETA, a countdown) speaks at most once per its minimum gap, except when it becomes urgent;
//   - countdowns speak at a few marks only (e.g. 10 and 5 seconds left), never every second.

/** Should this sentence be spoken now? `last` maps topic -> {text, at}. Returns the new memory or null (stay quiet). */
export function shouldAnnounce(last, { topic = 'general', text, now, minGapMs = 0, urgent = false }) {
    const said = last[topic];
    if (!text) return null;
    if (said && said.text === text) return null;
    if (said && !urgent && now - said.at < minGapMs) return null;

    return { ...last, [topic]: { text, at: now } };
}

/** The countdown mark crossed between two readings of seconds left (descending), or null. */
export function countdownCue(previous, seconds, marks = [10, 5]) {
    if (!Number.isFinite(seconds)) return null;
    for (const mark of marks) {
        if ((previous === null || previous === undefined || previous > mark) && seconds <= mark && seconds > 0) return mark;
    }

    return null;
}

/**
 * The ETA sentence worth saying, or null: speak when the whole-minute value changes and at least a minute has passed
 * since the last ETA sentence, or at once when the driver is two minutes away or less (urgent).
 */
export function etaCue(lastSaid, etaSeconds, now, { prefix = 'Arrives in', minGapMs = 60000 } = {}) {
    if (!Number.isFinite(etaSeconds)) return null;
    const minutes = Math.max(1, Math.round(etaSeconds / 60));
    if (lastSaid && lastSaid.minutes === minutes) return null;
    const urgent = minutes <= 2 && (!lastSaid || lastSaid.minutes > 2 || minutes < lastSaid.minutes);
    if (lastSaid && !urgent && now - lastSaid.at < minGapMs) return null;

    return { text: `${prefix} ${minutes} min.`, minutes, at: now, urgent };
}

/**
 * The queues that went up since the last reading, as one sentence (an operator's screen reader hears new work
 * arrive without hearing every poll). `labels` maps a count key to its words; counts that fall or stay are quiet.
 */
export function risesSentence(previous, next, labels) {
    if (!previous || !next) return null;
    const parts = Object.entries(labels)
        .filter(([key]) => Number.isFinite(next[key]) && Number.isFinite(previous[key]) && next[key] > previous[key])
        .map(([key, words]) => `${words}: ${next[key]}`);

    return parts.length ? `New work. ${parts.join('. ')}.` : null;
}

/** Straight-line distance in a few plain words ("850 m", "1.2 km"). */
export function distanceWords(metres) {
    if (!Number.isFinite(metres)) return null;
    if (metres < 1000) return `${Math.max(10, Math.round(metres / 10) * 10)} m`;

    return `${(metres / 1000).toFixed(metres < 10000 ? 1 : 0)} km`;
}

/**
 * A live-region announcer bound to the page. Creates its two visually hidden regions when the page has none
 * (polite and assertive). `say(text, {topic, minGapMs, assertive, urgent})`.
 */
export function createAnnouncer(doc = document, { polite = null, assertive = null, now = () => Date.now() } = {}) {
    const region = (existing, mode) => {
        if (existing) return existing;
        const el = doc.createElement('div');
        el.className = 'eh-sr-only';
        el.setAttribute('aria-live', mode);
        el.setAttribute('aria-atomic', 'true');
        el.dataset.ehAnnouncer = mode;
        doc.body.append(el);

        return el;
    };
    const regions = { polite: region(polite, 'polite'), assertive: region(assertive, 'assertive') };
    let memory = {};

    return {
        say(text, { topic, minGapMs = 0, assertive: loud = false, urgent = false } = {}) {
            const next = shouldAnnounce(memory, { topic: topic || text, text, now: now(), minGapMs, urgent });
            if (!next) return false;
            memory = next;
            const target = loud ? regions.assertive : regions.polite;
            target.textContent = '';
            // A fresh text node after a tick makes a repeated message heard again.
            setTimeout(() => { target.textContent = text; }, 30);

            return true;
        },
        forget(topic) { delete memory[topic]; },
    };
}
