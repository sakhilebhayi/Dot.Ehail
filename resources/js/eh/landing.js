// Landing page behaviour (ds, R-46): the fare line (one stop open at a time), the driver index, section reveals and
// the slow crossfade of the hero photograph. Without this script every explanation is visible and nothing moves.
const doc = document;
const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
doc.documentElement.classList.add('lp-js');

// Fare line: each stop is a button controlling its own explanation
function initFareLine() {
    const line = doc.querySelector('[data-lp-fareline]');
    if (!line) return;
    const buttons = [...line.querySelectorAll('[data-lp-stop]')];
    const panels = line.querySelector('[data-lp-panels]');
    const open = (button, focus = false) => {
        buttons.forEach((b) => {
            const on = b === button;
            b.setAttribute('aria-expanded', String(on));
            const panel = doc.getElementById(b.getAttribute('aria-controls'));
            if (panel) panel.hidden = !on;
        });
        if (focus) button.focus();
    };
    // Wide screens show the explanation under the whole line; narrow screens under its stop.
    const place = () => {
        const wide = window.matchMedia('(min-width: 1100px)').matches;
        buttons.forEach((b) => {
            const panel = doc.getElementById(b.getAttribute('aria-controls'));
            if (!panel) return;
            if (wide) panels.append(panel); else b.closest('li').append(panel);
        });
    };
    buttons.forEach((b, i) => {
        b.addEventListener('click', () => open(b));
        b.addEventListener('keydown', (e) => {
            const to = { ArrowRight: i + 1, ArrowDown: i + 1, ArrowLeft: i - 1, ArrowUp: i - 1 }[e.key];
            if (to === undefined || !buttons[to]) return;
            e.preventDefault();
            open(buttons[to], true);
        });
    });
    place();
    window.matchMedia('(min-width: 1100px)').addEventListener('change', place);
    open(buttons[buttons.length - 1]);
}

// Driver index: a tablist of names; the fact beside it changes
function initIndex() {
    const list = doc.querySelector('[data-lp-index]');
    if (!list) return;
    const tabs = [...list.querySelectorAll('[role="tab"]')];
    const select = (tab, focus) => {
        tabs.forEach((t) => {
            const on = t === tab;
            t.setAttribute('aria-selected', String(on));
            t.tabIndex = on ? 0 : -1;
            const panel = doc.getElementById(t.getAttribute('aria-controls'));
            if (panel) panel.hidden = !on;
        });
        if (focus) tab.focus();
    };
    tabs.forEach((tab, i) => {
        tab.addEventListener('click', () => select(tab, false));
        tab.addEventListener('keydown', (e) => {
            const last = tabs.length - 1;
            const to = { ArrowRight: i === last ? 0 : i + 1, ArrowDown: i === last ? 0 : i + 1, ArrowLeft: i === 0 ? last : i - 1, ArrowUp: i === 0 ? last : i - 1, Home: 0, End: last }[e.key];
            if (to === undefined) return;
            e.preventDefault();
            select(tabs[to], true);
        });
    });
    select(tabs[0], false);
}

// Reveal: the heading, then the text, then the picture of each section, once
function initReveal() {
    const blocks = doc.querySelectorAll('[data-lp-reveal]');
    if (reduced || !('IntersectionObserver' in window)) { blocks.forEach((b) => b.classList.add('is-in')); return; }
    const io = new IntersectionObserver((entries) => entries.forEach((entry) => {
        if (entry.isIntersecting) { entry.target.classList.add('is-in'); io.unobserve(entry.target); }
    }), { rootMargin: '0px 0px -10% 0px', threshold: 0.05 });
    blocks.forEach((b) => io.observe(b));
}

// Hero crossfade: more photographs of the same roads, loaded only after the page, never on Save-Data or reduced motion
function initCrossfade() {
    const frame = doc.querySelector('[data-lp-crossfade]');
    if (!frame || reduced || navigator.connection?.saveData) return;
    window.addEventListener('load', () => {
        const sources = JSON.parse(frame.dataset.lpCrossfade || '[]');
        const first = frame.querySelector('img');
        const images = [first];
        sources.forEach((src) => {
            const img = doc.createElement('img');
            img.src = src; img.alt = ''; img.decoding = 'async';
            frame.insertBefore(img, frame.querySelector('figcaption'));
            images.push(img);
        });
        const captions = [first.dataset.caption, ...JSON.parse(frame.dataset.lpCaptions || '[]')];
        const caption = frame.querySelector('figcaption');
        let index = 0;
        first.classList.add('is-on');
        let timer = setInterval(step, 6000);
        function step() {
            if (doc.hidden) return;
            images[index].classList.remove('is-on');
            index = (index + 1) % images.length;
            images[index].classList.add('is-on');
            if (index > 0) first.style.opacity = '0'; else first.style.opacity = '';
            if (caption && captions[index]) caption.textContent = captions[index];
        }
        doc.addEventListener('visibilitychange', () => { if (doc.hidden) { clearInterval(timer); } else { timer = setInterval(step, 6000); } });
    });
}

initFareLine();
initIndex();
initReveal();
initCrossfade();
