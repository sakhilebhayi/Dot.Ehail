// Day-and-time picker (<x-eh.datetime>): a row of days and a grid of times, drawn in the kit instead of the browser's
// date input. The chosen moment lives in a hidden input as local "YYYY-MM-DDTHH:MM" (what datetime-local gave
// before), which fires input and change events like the native field did. Pure helpers are exported for tests.

const pad = (n) => String(n).padStart(2, '0');

/** Local "YYYY-MM-DD" for a Date. */
export function dayKey(date) {
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

/** Local "YYYY-MM-DDTHH:MM" for a Date. */
export function localValue(date) {
    return `${dayKey(date)}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

/** The first slot at or after `date` on a `step`-minute grid. */
export function ceilToStep(date, step) {
    const ms = step * 60000;
    return new Date(Math.ceil(date.getTime() / ms) * ms);
}

/** Every calendar day from min to max (inclusive) that still has a slot: [{key, date, label, day, month}]. */
export function whenDays(min, max, step = 15, now = new Date()) {
    const first = ceilToStep(min, step);
    const days = [];
    const cursor = new Date(first.getFullYear(), first.getMonth(), first.getDate());
    const today = dayKey(now);
    const tomorrow = dayKey(new Date(now.getFullYear(), now.getMonth(), now.getDate() + 1));
    while (cursor <= max) {
        const key = dayKey(cursor);
        if (whenSlots(key, min, max, step).length) {
            days.push({
                key,
                date: new Date(cursor),
                label: key === today ? 'Today' : key === tomorrow ? 'Tomorrow' : cursor.toLocaleDateString('en-ZA', { weekday: 'short' }),
                day: cursor.getDate(),
                month: cursor.toLocaleDateString('en-ZA', { month: 'short' }),
            });
        }
        cursor.setDate(cursor.getDate() + 1);
    }
    return days;
}

/** The times on one day inside [min, max] on the step grid, as local "HH:MM". */
export function whenSlots(key, min, max, step = 15) {
    const [y, m, d] = key.split('-').map(Number);
    const start = new Date(y, m - 1, d, 0, 0);
    const end = new Date(y, m - 1, d, 23, 59);
    const from = ceilToStep(start < min ? min : start, step);
    const slots = [];
    for (let t = from; t <= end && t <= max; t = new Date(t.getTime() + step * 60000)) {
        if (dayKey(t) !== key) break;
        slots.push(`${pad(t.getHours())}:${pad(t.getMinutes())}`);
    }
    return slots;
}

/** "Tue 11 Nov, 07:30" for the summary line. */
export function whenLabel(value, now = new Date()) {
    if (!value) return '';
    const at = new Date(value);
    if (Number.isNaN(at.getTime())) return '';
    const key = dayKey(at);
    const day = key === dayKey(now) ? 'Today' : key === dayKey(new Date(now.getFullYear(), now.getMonth(), now.getDate() + 1)) ? 'Tomorrow' : at.toLocaleDateString('en-ZA', { weekday: 'short', day: 'numeric', month: 'short' });
    return `${day}, ${pad(at.getHours())}:${pad(at.getMinutes())}`;
}

/**
 * Binds one picker. options: {min: Date, max: Date, step: minutes, value: "YYYY-MM-DDTHH:MM" | null}.
 * Returns {setBounds(min, max), setValue(v), get value()}.
 */
export function bindWhen(root, { min = new Date(), max = new Date(Date.now() + 30 * 86400000), step = Number(root?.dataset.step || 15), value = null } = {}) {
    if (!root) return { setBounds() {}, setValue() {}, value: '' };
    const hidden = root.querySelector('input[type="hidden"]');
    const daysEl = root.querySelector('[data-eh-when-days]');
    const timesEl = root.querySelector('[data-eh-when-times]');
    const summary = root.querySelector('[data-eh-when-summary]');
    const name = hidden.id || 'eh-when';
    let bounds = { min, max };
    let chosenDay = null;

    const emit = () => {
        hidden.dispatchEvent(new Event('input', { bubbles: true }));
        hidden.dispatchEvent(new Event('change', { bubbles: true }));
        if (summary) summary.textContent = hidden.value ? `Pickup ${whenLabel(hidden.value)}` : '';
    };

    const radio = (group, val, checked, text) => {
        const label = document.createElement('label');
        const input = document.createElement('input');
        input.type = 'radio';
        input.name = `${name}-${group}`;
        input.value = val;
        input.checked = checked;
        const span = document.createElement('span');
        if (typeof text === 'string') span.textContent = text;
        else span.append(...text);
        label.append(input, span);
        return { label, input };
    };

    const drawTimes = () => {
        const slots = chosenDay ? whenSlots(chosenDay, bounds.min, bounds.max, step) : [];
        const current = hidden.value.startsWith(chosenDay || '#') ? hidden.value.slice(11, 16) : null;
        timesEl.replaceChildren(
            ...slots.map((slot) => {
                const { label, input } = radio('time', slot, slot === current, slot);
                label.className = 'eh-when__time';
                input.addEventListener('change', () => {
                    hidden.value = `${chosenDay}T${slot}`;
                    emit();
                });
                return label;
            }),
        );
        if (!slots.length) {
            const p = document.createElement('p');
            p.className = 'eh-when__empty';
            p.textContent = 'No pickup times left on this day.';
            timesEl.append(p);
        }
    };

    const draw = () => {
        const days = whenDays(bounds.min, bounds.max, step);
        if (!days.some((d) => d.key === chosenDay)) chosenDay = days[0]?.key || null;
        daysEl.replaceChildren(
            ...days.map((d) => {
                const week = document.createElement('span');
                week.textContent = d.label;
                const num = document.createElement('b');
                num.textContent = String(d.day);
                const mon = document.createElement('span');
                mon.textContent = d.month;
                const { label, input } = radio('day', d.key, d.key === chosenDay, [week, num, mon]);
                label.className = 'eh-when__day';
                label.setAttribute('aria-label', d.date.toLocaleDateString('en-ZA', { weekday: 'long', day: 'numeric', month: 'long' }));
                input.addEventListener('change', () => {
                    chosenDay = d.key;
                    // Keep the same time of day when the new day has it; otherwise the first time there is.
                    const keep = hidden.value ? hidden.value.slice(11, 16) : null;
                    const slots = whenSlots(chosenDay, bounds.min, bounds.max, step);
                    const slot = keep && slots.includes(keep) ? keep : slots[0];
                    hidden.value = slot ? `${chosenDay}T${slot}` : '';
                    drawTimes();
                    emit();
                });
                return label;
            }),
        );
        drawTimes();
    };

    const api = {
        setBounds(nextMin, nextMax) {
            bounds = { min: nextMin, max: nextMax };
            const at = hidden.value ? new Date(hidden.value) : null;
            if (!at || at < nextMin || at > nextMax) {
                const first = ceilToStep(nextMin, step);
                hidden.value = first <= nextMax ? localValue(first) : '';
            }
            chosenDay = hidden.value ? hidden.value.slice(0, 10) : null;
            draw();
            emit();
        },
        setValue(next) {
            hidden.value = next || '';
            chosenDay = hidden.value ? hidden.value.slice(0, 10) : chosenDay;
            draw();
            emit();
        },
        get value() {
            return hidden.value;
        },
    };

    if (value) hidden.value = value;
    chosenDay = hidden.value ? hidden.value.slice(0, 10) : null;
    draw();

    return api;
}
