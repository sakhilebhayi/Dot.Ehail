// Dot.Ehail shell behaviour (ds, R-46): theme choice, side drawer, account menu, bottom sheet, tabs, dialogs,
// toasts and service-worker registration. No dependencies; every feature is progressive (markup works without it).
import { initSheets } from './sheet.js';
import { resolveTheme } from './theme.js';

const doc = document;
const root = doc.documentElement;
const reducedMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

function applyTheme(choice) {
    if (root.hasAttribute('data-theme-lock')) return;
    const theme = resolveTheme(choice);
    if (theme) root.dataset.theme = theme;
    else delete root.dataset.theme;
    root.classList.toggle('dark', (theme || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')) === 'dark');
}

function initTheme() {
    const key = root.dataset.themeKey || 'eh-theme';
    let current;
    try { current = localStorage.getItem(key); } catch { current = null; }
    current = current || root.dataset.themeDefault || 'system';
    doc.querySelectorAll('input[data-eh-theme-choice]').forEach((input) => {
        input.checked = input.value === current;
        input.addEventListener('change', () => {
            if (!input.checked) return;
            try { localStorage.setItem(key, input.value); } catch { /* private mode: applies for this page only */ }
            applyTheme(input.value);
        });
    });
}

// Side drawer (ops, business, fleet below 1024px)
function initDrawer() {
    const nav = doc.getElementById('eh-side-nav');
    if (!nav) return;
    const openers = doc.querySelectorAll('[data-eh-drawer-open]');
    const behind = doc.querySelectorAll('[data-eh-drawer-behind]');
    let lastFocus = null;
    const set = (open) => {
        root.classList.toggle('eh-drawer-open', open);
        openers.forEach((b) => b.setAttribute('aria-expanded', String(open)));
        behind.forEach((el) => { el.inert = open; });
        if (open) {
            lastFocus = doc.activeElement;
            nav.querySelector('[data-eh-drawer-close]')?.focus();
        } else if (lastFocus) {
            lastFocus.focus();
        }
    };
    openers.forEach((b) => b.addEventListener('click', () => set(true)));
    doc.querySelectorAll('[data-eh-drawer-close]').forEach((b) => b.addEventListener('click', () => set(false)));
    doc.addEventListener('keydown', (e) => { if (e.key === 'Escape' && root.classList.contains('eh-drawer-open')) set(false); });
    window.matchMedia('(min-width: 1024px)').addEventListener('change', (e) => { if (e.matches) set(false); });
}

// Account menu: a <details> that closes on outside click and Escape
function initMenus() {
    const menus = doc.querySelectorAll('details[data-eh-menu]');
    if (!menus.length) return;
    doc.addEventListener('click', (e) => menus.forEach((m) => { if (m.open && !m.contains(e.target)) m.open = false; }));
    doc.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;
        menus.forEach((m) => { if (m.open) { m.open = false; m.querySelector('summary')?.focus(); } });
    });
}

// Tabs (ARIA tablist): arrow keys, Home, End
function initTabs() {
    doc.querySelectorAll('[role="tablist"][data-eh-tabs]').forEach((list) => {
        const tabs = [...list.querySelectorAll('[role="tab"]')];
        const select = (tab, focus = true) => {
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
                const to = { ArrowRight: i === last ? 0 : i + 1, ArrowLeft: i === 0 ? last : i - 1, Home: 0, End: last }[e.key];
                if (to === undefined) return;
                e.preventDefault();
                select(tabs[to]);
            });
        });
    });
}

// Dialogs: native <dialog> opened by [data-eh-dialog-open="id"]
function initDialogs() {
    doc.addEventListener('click', (e) => {
        const opener = e.target.closest('[data-eh-dialog-open]');
        if (opener) {
            const dialog = doc.getElementById(opener.dataset.ehDialogOpen);
            if (dialog && typeof dialog.showModal === 'function') { e.preventDefault(); dialog.showModal(); }
            return;
        }
        const closer = e.target.closest('[data-eh-dialog-close]');
        if (closer) closer.closest('dialog')?.close(closer.value || 'cancel');
    });
    doc.querySelectorAll('dialog.eh-dialog').forEach((dialog) => {
        dialog.addEventListener('click', (e) => { if (e.target === dialog) dialog.close('cancel'); });
    });
}

// Toasts: window.ehToast(message, { tone: 'stop' }) and server flashes rendered as [data-eh-flash]
function toastRegion() {
    let region = doc.getElementById('eh-toasts');
    if (!region) {
        region = doc.createElement('div');
        region.id = 'eh-toasts';
        region.className = 'eh-toasts';
        region.setAttribute('role', 'status');
        region.setAttribute('aria-live', 'polite');
        doc.body.append(region);
    }
    return region;
}

export function toast(message, { tone = 'neutral', timeout = 6000 } = {}) {
    const region = toastRegion();
    const item = doc.createElement('div');
    item.className = 'eh-toast';
    item.dataset.tone = tone;
    const text = doc.createElement('p');
    text.className = 'eh-toast__text';
    text.style.margin = '0';
    text.textContent = message;
    const close = doc.createElement('button');
    close.type = 'button';
    close.className = 'eh-iconbtn';
    close.setAttribute('aria-label', 'Dismiss');
    close.innerHTML = '<svg class="eh-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>';
    const remove = () => item.remove();
    close.addEventListener('click', remove);
    item.append(text, close);
    region.append(item);
    if (timeout > 0 && tone !== 'stop') {
        let timer = setTimeout(remove, timeout);
        item.addEventListener('mouseenter', () => clearTimeout(timer));
        item.addEventListener('focusin', () => clearTimeout(timer));
        item.addEventListener('mouseleave', () => { timer = setTimeout(remove, timeout); });
    }
    return item;
}

function initFlashes() {
    doc.querySelectorAll('template[data-eh-flash]').forEach((tpl) => {
        toast(tpl.content.textContent.trim(), { tone: tpl.dataset.tone || 'neutral' });
        tpl.remove();
    });
}

function initServiceWorker() {
    if (root.dataset.sw !== 'on' || !('serviceWorker' in navigator)) return;
    window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(() => {}));
}

// Tables that scroll sideways on a phone (R-46 a11y, D-1706): a keyboard user must be able to reach and scroll them,
// and a screen reader hears what the region is. Only wrappers that really overflow become Tab stops.
const SCROLLERS = '.ops-tablewrap, .bz-tablewrap, .fl-table-wrap, .eh-legacy .table-scroll, [data-eh-scroll]';
function scrollRegionName(wrap) {
    const table = wrap.querySelector('table');
    const named = table?.getAttribute('aria-label') || table?.querySelector('caption')?.textContent;
    if (named) return named.trim();
    const section = wrap.closest('section[aria-labelledby]');
    const heading = section && doc.getElementById(section.getAttribute('aria-labelledby'));

    return (heading?.textContent || 'Table').trim().replace(/\s+/g, ' ');
}
function initScrollRegions() {
    const apply = () => doc.querySelectorAll(SCROLLERS).forEach((wrap) => {
        const scrolls = wrap.scrollWidth > wrap.clientWidth + 1;
        if (scrolls && !wrap.hasAttribute('tabindex')) {
            wrap.tabIndex = 0;
            wrap.setAttribute('role', 'region');
            wrap.setAttribute('aria-label', `${scrollRegionName(wrap)} (scrolls sideways)`);
            wrap.dataset.ehScrollRegion = '';
        } else if (!scrolls && wrap.hasAttribute('data-eh-scroll-region')) {
            wrap.removeAttribute('tabindex');
            wrap.removeAttribute('role');
            wrap.removeAttribute('aria-label');
            delete wrap.dataset.ehScrollRegion;
        }
    });
    apply();
    let pending = 0;
    window.addEventListener('resize', () => { cancelAnimationFrame(pending); pending = requestAnimationFrame(apply); });
}

window.ehToast = toast;
initTheme();
initDrawer();
initMenus();
initTabs();
initDialogs();
initSheets({ reducedMotion });
initFlashes();
initScrollRegions();
initServiceWorker();
