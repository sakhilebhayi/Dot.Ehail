// The things people do, the way they do them in the browser (selectors are the apps' own data attributes).
import assert from 'node:assert/strict';
import { sleep, one, until, driveAlong, sql } from './harness.mjs';

export const PASS = 'a-long-pass-2026';

/** Sign-up through the real form (ALLOW_REGISTRATION=true in the suite). Leaves the page signed in. */
export async function register(page, { name, email }) {
    await page.goto('/register');
    await page.fill('#name', name);
    await page.fill('#email', email);
    await page.fill('#password', PASS);
    await page.fill('#password_confirmation', PASS);
    await page.check('#terms');
    await Promise.all([page.waitForURL((u) => !u.pathname.includes('register'), { timeout: 15000 }), page.click('button[type=submit]')]);
    const user = one('select id, email from users where email = ?', [email]);
    assert.ok(user, 'the account exists');
    return { ...user, name, password: PASS };
}

/** Account > Add a card: Paystack's card-only checkout (routed to the stub), pay R1.00, back to the account. */
export async function addCard(page) {
    await page.goto('/ride/account');
    await Promise.all([page.waitForURL(/checkout\.paystack\.com/), page.getByRole('button', { name: /add a card/i }).click()]);
    await Promise.all([page.waitForURL((u) => !u.host.includes('paystack'), { timeout: 15000 }), page.click('#pay')]);
    await page.getByText(/Visa •••• 4081/).first().waitFor({ timeout: 15000 });
}

/** Geo's place search: type, Enter to search, the first result, Enter to choose it. */
export async function searchPlace(page, selector, text) {
    const input = page.locator(selector);
    await input.click();
    await input.fill(text);
    await input.press('Enter');
    await page.locator('[role=option]').first().waitFor({ timeout: 10000 });
    await input.press('Enter');
    await sleep(300);
}

/** Booking sheet up to the priced options. Returns the options' visible names and totals. */
export async function priceTrip(page, { from = 'Kloof', to = 'Bree' } = {}) {
    await page.goto('/ride?new=1');
    await searchPlace(page, '#pax-pickup', from);
    await searchPlace(page, '#pax-dropoff', to);
    await page.click('[data-pax-see-prices]');
    await page.locator('.pax-option').first().waitFor({ timeout: 20000 });
}

/** Choose a category by its visible name and request it. Returns the ride id from the live page's URL. */
export async function requestCategory(page, name, { payment = null } = {}) {
    const option = page.locator('.pax-option', { has: page.locator('.pax-option__name, h2, h3', { hasText: name }) }).first();
    await option.locator('.pax-option__pick').check();
    if (payment) await page.locator(`input[name="pax-payment"][value="${payment}"]`).check();
    await Promise.all([page.waitForURL(/\/ride\/trips\/\d+/, { timeout: 20000 }), page.click('[data-pax-request]')]);
    return Number(page.url().match(/trips\/(\d+)/)[1]);
}

export async function paxTitle(page, re, timeout = 30000) {
    await page.waitForFunction((src) => new RegExp(src).test(document.querySelector('[data-pax-title]')?.textContent || ''), re.source, { timeout });
}

export async function paxPin(page) {
    const pin = page.locator('[data-pax-pin]');
    await pin.waitFor({ timeout: 15000 });
    return (await pin.getAttribute('data-pin')) || (await pin.innerText()).replace(/\D/g, '');
}

/** Driver console: Go online (location granted in the context) and wait for the online view and a first fix. */
export async function goOnline(page, driverId) {
    await page.goto('/drive');
    await page.click('[data-dr-go-online]');
    await page.waitForSelector('[data-dr-view="online"]:not([hidden])', { timeout: 20000 });
    await until(() => one("select last_lat from driver_availability where user_id = ? and state = 'online' and last_lat is not null", [driverId]), { what: 'the first position post', timeout: 20000 });
}

export async function waitOffer(page, timeout = 40000) {
    await page.waitForSelector('[data-dr-offer]:not([hidden])', { timeout });
    // The earnings breakdown under "You earn" loads just after the card opens.
    await page.locator('[data-dr-offer-ledger]').filter({ hasText: /Fare/ }).waitFor({ timeout: 10000 }).catch(() => {});
    return (await page.locator('[data-dr-offer]').innerText()).replace(/\s+/g, ' ');
}

export async function accept(page) {
    await page.click('[data-dr-accept]');
    await page.waitForSelector('[data-dr-view="trip"]:not([hidden])', { timeout: 15000 });
}

/** Drive to a point, then wait until the console has noticed the car stopped (minimal-distraction mode off). */
export async function driveTo(ctx, page, from, to, speed = 25) {
    await driveAlong(ctx, from, to, { speed });
    await page.waitForFunction(() => document.documentElement.dataset.moving !== 'true', null, { timeout: 30000 }).catch(() => {});
    await sleep(4500); // the last fixes reach the server (posts every 4 s)
}

export async function arrive(page) {
    await page.click('[data-dr-arrived]');
    await page.waitForSelector('[data-dr-stage="arrived"]:not([hidden])', { timeout: 15000 });
}

export async function typePin(page, pin) {
    for (const c of pin) await page.click(`[data-dr-key="${c}"]`);
    await page.click('[data-dr-start]');
}

export async function startTrip(page, pin) {
    await typePin(page, pin);
    await page.waitForSelector('[data-dr-stage="in_progress"]:not([hidden])', { timeout: 15000 });
}

export async function complete(page) {
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.click('[data-dr-complete]');
    await page.click('[data-dr-complete-confirm]');
    await page.waitForSelector('[data-dr-view="summary"]:not([hidden])', { timeout: 20000 });
}

export async function goOffline(page) {
    await page.goto('/drive');
    const off = page.locator('[data-dr-go-offline]:visible');
    if (await off.count()) await off.click();
}

/** Ledger checks straight from the journal tables. */
export function ledgerBalanced() {
    const all = one('select coalesce(sum(debit_minor),0) d, coalesce(sum(credit_minor),0) c from ledger_entries');
    const bad = sql('select journal_id, sum(debit_minor) - sum(credit_minor) diff from ledger_entries group by journal_id having diff != 0');
    return all.d === all.c && bad.length === 0;
}
export function accountBalance(accountPrefix, { credit = true } = {}) {
    const r = one('select coalesce(sum(credit_minor),0) c, coalesce(sum(debit_minor),0) d from ledger_entries where account = ?', [accountPrefix]);
    return credit ? r.c - r.d : r.d - r.c;
}
