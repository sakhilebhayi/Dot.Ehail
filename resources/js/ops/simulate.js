// Fare simulator: posts the trip to the pricing API's simulate endpoint and draws one column per tariff (live and
// drafts), every component on its own line, so a draft can be compared with what passengers pay today.
import { errorMessage, randToMinor } from './helpers.js';

function cell(tag, text, className) {
    const el = document.createElement(tag);
    if (className) el.className = className;
    el.textContent = text;
    return el;
}

function render(target, tripLabel, body) {
    target.replaceChildren();
    if (!body.available) {
        target.appendChild(cell('p', body.message || 'This trip cannot be priced.', 'ops-act__error'));
        return;
    }
    tripLabel.textContent = `${(body.trip.distance_m / 1000).toFixed(1)} km, ${Math.round(body.trip.duration_s / 60)} min, ${body.trip.category} (${body.trip.source})`;
    const results = body.results || [];
    if (!results.length) {
        target.appendChild(cell('p', 'No tariff applies to this category: there is no live tariff and no draft was chosen.', 'ops-none'));
        return;
    }
    const codes = [];
    results.forEach((r) => (r.fare?.components || []).forEach((c) => { if (!codes.find((x) => x.code === c.code)) codes.push({ code: c.code, label: c.label }); }));
    const table = document.createElement('table');
    table.className = 'ops-table ops-table--compact';
    const head = document.createElement('tr');
    head.appendChild(cell('th', 'Line'));
    results.forEach((r) => head.appendChild(cell('th', `${r.name} v${r.version} (${r.status})`, 'num')));
    const thead = document.createElement('thead');
    thead.appendChild(head);
    const tbody = document.createElement('tbody');
    codes.forEach(({ code, label }) => {
        const tr = document.createElement('tr');
        tr.appendChild(cell('td', label));
        results.forEach((r) => {
            const c = (r.fare?.components || []).find((x) => x.code === code);
            tr.appendChild(cell('td', c ? c.formatted : '-', 'num'));
        });
        tbody.appendChild(tr);
    });
    const total = document.createElement('tr');
    total.appendChild(cell('td', 'Total'));
    results.forEach((r) => { const td = cell('td', r.fare?.total?.formatted ?? (r.reason || '-'), 'num'); td.style.fontWeight = '700'; total.appendChild(td); });
    tbody.appendChild(total);
    table.append(thead, tbody);
    target.appendChild(table);
    if (results.some((r) => r.is_example)) target.appendChild(cell('p', 'An example tariff is included: its numbers are placeholders, not approved prices.', 'ops-note ops-note--small'));
}

export function initSimulator(form) {
    const target = document.querySelector('[data-sim-results]');
    const tripLabel = document.querySelector('[data-sim-trip]');
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const data = new FormData(form);
        const payload = new FormData();
        payload.set('category', data.get('category'));
        data.getAll('rule_set_ids[]').forEach((id) => payload.append('rule_set_ids[]', id));
        payload.set('include_live', data.getAll('include_live').includes('1') ? '1' : '0');
        const coords = ['pickup_lat', 'pickup_lng', 'dropoff_lat', 'dropoff_lng'].map((k) => String(data.get(k) || '').trim());
        if (coords.every((v) => v !== '')) {
            ['pickup_lat', 'pickup_lng', 'dropoff_lat', 'dropoff_lng'].forEach((k, i) => payload.set(k, coords[i]));
        } else {
            const metres = randToMinor(String(data.get('distance_km') || '').trim()); // km with 2 decimals -> tens of metres
            if (metres === null) { target.replaceChildren(cell('p', 'Enter the distance in kilometres, like 12.5.', 'ops-act__error')); return; }
            payload.set('distance_m', String(Number(metres) * 10));
            payload.set('duration_s', String(Math.max(0, Math.round(Number(data.get('duration_min') || 0) * 60))));
        }
        payload.set('waiting_s', String(Math.max(0, Math.round(Number(data.get('waiting_min') || 0) * 60))));
        const tolls = String(data.get('tolls_rand') || '').trim();
        if (tolls) {
            const minor = randToMinor(tolls);
            if (minor === null) { target.replaceChildren(cell('p', 'Enter the tolls in rand, like 45.50.', 'ops-act__error')); return; }
            payload.set('tolls_minor', minor);
        }
        target.setAttribute('aria-busy', 'true');
        try {
            const response = await fetch(form.action, {
                method: 'POST', body: payload, credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
            });
            const body = await response.json().catch(() => null);
            if (!response.ok) target.replaceChildren(cell('p', errorMessage(response.status, body), 'ops-act__error'));
            else render(target, tripLabel, body);
        } catch {
            target.replaceChildren(cell('p', 'No connection. Try again.', 'ops-act__error'));
        } finally {
            target.removeAttribute('aria-busy');
        }
    });
}
