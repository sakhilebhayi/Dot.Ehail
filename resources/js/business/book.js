/*
 * Business booking page: the same public APIs the passenger app uses.
 * geo search -> pricing quote compare -> /business/trips/prepare (policy check) -> /api/ehail/rides.
 * No fare is ever computed here; the server's quote and decision are shown as they come.
 */
const form = document.getElementById('bz-book');

if (form) {
    const urls = JSON.parse(form.dataset.urls);
    const profileId = form.dataset.profile;
    const token = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    const statusEl = form.querySelector('[data-status]');
    const optionsEl = form.querySelector('[data-options]');
    const checkEl = form.querySelector('[data-check]');
    const requestBtn = form.querySelector('[data-request]');
    const askBtn = form.querySelector('[data-ask]');
    const places = { pickup: null, dropoff: null };
    let quoteId = null;
    let checkTimer = null;

    const say = (text) => { statusEl.textContent = text; };
    const money = (m) => (m && typeof m === 'object' ? m.formatted : '');
    const json = async (url, options = {}) => {
        const response = await fetch(url, {
            credentials: 'same-origin',
            ...options,
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest', ...(options.headers || {}) },
        });
        let body = {};
        try { body = await response.json(); } catch { body = {}; }
        return { ok: response.ok, status: response.status, body };
    };

    const rider = () => form.querySelector('input[name="rider"]:checked')?.value ?? 'self';
    const businessFields = () => {
        const value = (name) => form.querySelector(`[name="${name}"]`)?.value?.trim() || null;
        const fields = { purpose: value('purpose') };
        if (rider() === 'member') { fields.traveller_member_id = value('traveller_member_id'); fields.rider_phone = value('rider_phone'); }
        if (rider() === 'guest') { fields.guest_name = value('guest_name'); fields.guest_phone = value('guest_phone'); }
        Object.keys(fields).forEach((k) => fields[k] === null && delete fields[k]);
        return fields;
    };
    const scheduledFor = () => {
        const v = form.querySelector('[name="scheduled_for"]')?.value;
        return v ? new Date(v).toISOString() : null;
    };

    // Rider choice shows the fields it needs.
    form.querySelectorAll('input[name="rider"]').forEach((input) => input.addEventListener('change', () => {
        form.querySelectorAll('[data-when]').forEach((el) => { el.hidden = el.dataset.when !== rider(); });
        scheduleCheck();
    }));

    // Place search with a short debounce; a chosen place keeps its coordinates.
    form.querySelectorAll('[data-place]').forEach((wrap) => {
        const kind = wrap.dataset.place;
        const input = wrap.querySelector('input');
        const list = wrap.querySelector('.bz-suggest');
        let timer = null;
        input.addEventListener('input', () => {
            places[kind] = null;
            invalidateQuote();
            clearTimeout(timer);
            const q = input.value.trim();
            if (q.length < 3) { list.hidden = true; return; }
            timer = setTimeout(async () => {
                const { ok, body } = await json(`${urls.search}?q=${encodeURIComponent(q)}`);
                list.replaceChildren();
                if (!ok) { say(body.message || 'Place search is not available right now.'); list.hidden = true; return; }
                (body.results || []).slice(0, 6).forEach((place) => {
                    const li = document.createElement('li');
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.textContent = place.label;
                    button.addEventListener('click', () => {
                        places[kind] = place;
                        input.value = place.label;
                        list.hidden = true;
                        if (places.pickup && places.dropoff) { fetchQuotes(); }
                    });
                    li.append(button);
                    list.append(li);
                });
                list.hidden = list.children.length === 0;
                if (list.hidden) { say('No places found. Try a street and suburb.'); }
            }, 300);
        });
    });

    function invalidateQuote() {
        quoteId = null;
        optionsEl.replaceChildren();
        checkEl.hidden = true;
        requestBtn.disabled = true;
        askBtn.hidden = true;
    }

    async function fetchQuotes() {
        invalidateQuote();
        say('Getting fares...');
        const { ok, body } = await json(urls.quote, { method: 'POST', body: JSON.stringify({
            pickup_lat: places.pickup.lat, pickup_lng: places.pickup.lng, dropoff_lat: places.dropoff.lat, dropoff_lng: places.dropoff.lng,
        }) });
        if (!ok || body.available === false) { say(body.message || 'Fares are not available for this trip right now.'); return; }
        const options = (body.options || []).filter((o) => o.quote_id);
        if (options.length === 0) { say('No ride category is available here right now.'); return; }
        say(`${options.length} ${options.length === 1 ? 'category' : 'categories'} for this trip. Choose one.`);
        options.forEach((option, i) => {
            const label = document.createElement('label');
            label.className = 'bz-option';
            const radio = document.createElement('input');
            radio.type = 'radio';
            radio.name = 'quote';
            radio.value = option.quote_id;
            const text = document.createElement('span');
            const strong = document.createElement('strong');
            strong.textContent = option.category?.name ?? option.category?.key ?? 'Ride';
            const small = document.createElement('small');
            small.textContent = option.category?.description ?? '';
            text.append(strong, small);
            const price = document.createElement('strong');
            price.className = 'eh-num';
            price.textContent = money(option.fare?.total);
            label.append(radio, text, price);
            radio.addEventListener('change', () => { quoteId = option.quote_id; scheduleCheck(true); });
            optionsEl.append(label);
            if (i === 0) { radio.checked = true; quoteId = option.quote_id; }
        });
        scheduleCheck(true);
    }

    function scheduleCheck(now = false) {
        clearTimeout(checkTimer);
        if (!quoteId) { return; }
        checkTimer = setTimeout(check, now ? 0 : 500);
    }
    form.querySelectorAll('[name="purpose"],[name="guest_name"],[name="guest_phone"],[name="traveller_member_id"],[name="scheduled_for"]').forEach((el) => el.addEventListener('input', () => scheduleCheck()));

    function showReasons(title, reasons, tone) {
        checkEl.hidden = false;
        checkEl.replaceChildren();
        const box = document.createElement('div');
        box.className = `eh-banner eh-banner--${tone}`;
        box.setAttribute('role', tone === 'stop' ? 'alert' : 'status');
        const body = document.createElement('div');
        body.className = 'eh-banner__body';
        const p = document.createElement('p');
        p.className = 'eh-banner__title';
        p.textContent = title;
        body.append(p);
        if (reasons.length) {
            const ul = document.createElement('ul');
            ul.className = 'bz-reasons';
            reasons.forEach((r) => { const li = document.createElement('li'); li.textContent = r.message; ul.append(li); });
            body.append(ul);
        }
        box.append(body);
        checkEl.append(box);
    }

    async function check() {
        if (!quoteId) { return; }
        const { ok, body } = await json(urls.prepare, { method: 'POST', body: JSON.stringify({ profile_id: profileId, quote_id: quoteId, scheduled_for: scheduledFor(), ...businessFields() }) });
        if (!ok) { requestBtn.disabled = true; askBtn.hidden = true; showReasons(body.message || 'This ride cannot be checked.', body.reasons || [], 'stop'); return; }
        requestBtn.disabled = !body.allowed;
        askBtn.hidden = !body.needs_approval;
        if (body.allowed) { showReasons(body.approval ? 'Covered by an approval. Ready to request.' : 'Within the policy. Ready to request.', [], 'go'); }
        else if (body.needs_approval) { showReasons('This ride needs an approver first.', body.reasons, 'caution'); }
        else { showReasons('This ride is outside the policy.', body.reasons, 'stop'); }
    }

    askBtn.addEventListener('click', async () => {
        askBtn.disabled = true;
        const { ok, body } = await json(urls.approval, { method: 'POST', body: JSON.stringify({ profile_id: profileId, quote_id: quoteId, scheduled_for: scheduledFor(), pickup_address: places.pickup?.label, dropoff_address: places.dropoff?.label, ...businessFields() }) });
        askBtn.disabled = false;
        if (ok) {
            askBtn.hidden = true;
            showReasons(`Asked. An approver has until ${new Date(body.approval.decide_by).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })} to decide; you get an email. Then get a fresh fare here and request.`, [], 'go');
        } else {
            showReasons(body.message || 'The request could not be sent.', body.reasons || [], 'stop');
        }
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!quoteId || requestBtn.disabled) { return; }
        requestBtn.disabled = true;
        requestBtn.setAttribute('aria-busy', 'true');
        const key = (crypto.randomUUID ? crypto.randomUUID() : String(Date.now()) + Math.random().toString(16).slice(2));
        const { ok, body } = await json(urls.ride, {
            method: 'POST',
            headers: { 'Idempotency-Key': key },
            body: JSON.stringify({
                quote_id: quoteId,
                payment_method: 'business_account',
                payment_ref: String(profileId),
                scheduled_for: scheduledFor(),
                pickup_address: places.pickup?.label,
                dropoff_address: places.dropoff?.label,
                business: businessFields(),
            }),
        });
        requestBtn.removeAttribute('aria-busy');
        if (ok) {
            showReasons(scheduledFor() ? 'Booked. The ride is scheduled and billed to the account after the trip.' : 'Requested. Finding a driver now; follow the ride in the passenger app.', [], 'go');
            optionsEl.replaceChildren();
            quoteId = null;
            const link = document.createElement('a');
            link.className = 'eh-link';
            link.href = urls.after;
            link.textContent = 'Open the ride';
            checkEl.append(link);
            say('');
        } else {
            requestBtn.disabled = false;
            showReasons(body.message || 'The ride could not be requested.', body.reasons || [], 'stop');
        }
    });
}
