// Local stand-ins for every external provider Dot.Ehail talks to. Nothing here contacts the internet and no real key
// exists: the app is pointed at these through its own env settings (run.mjs). Shapes follow the providers' documented
// APIs, the same ones the PHP fakes use (tests/Concerns/AcceptanceWorld.php, MoneyFixtures.php).
//
//  - edge (http):   OSRM /osrm/route/v1/driving/..., Nominatim /nominatim/search|reverse,
//                   and /__control (read captures, switch behaviour) for the specs.
//  - paystack (https, self-signed; PHP trusts it through curl.cainfo): transaction initialize/verify,
//                   charge_authorization, refund (+ fetch), bank/validate, transferrecipient, transfer (+ verify),
//                   BulkSMS /v1/messages and the map tiles /tiles/z/x/y.png on the same server. Webhooks are delivered to the app signed with the
//                   configured test secret (HMAC-SHA512 of the raw body, x-paystack-signature).
//  - smtp:          a minimal SMTP server that captures every message (Laravel's smtp mailer, no TLS).
import http from 'node:http';
import https from 'node:https';
import net from 'node:net';
import crypto from 'node:crypto';
import { roadPath, pathMetres, encodePolyline, PLACES, CITY_SPEED_MS } from './geo.mjs';

// A 1x1 light-grey PNG for every tile request (the map renders; nothing leaves the machine).
const TILE = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR42mP4//8/AAX+Av4N70a4AAAAAElFTkSuQmCC', 'base64');

export function createState() {
    return {
        routingDown: false, routeRequests: 0, searches: 0,
        paystack: { txs: {}, refunds: {}, transfers: {}, chargeOutcome: 'success', checkoutOutcome: 'success', reusable: true, calls: [], refundSeq: 9000 },
        webhooks: [], mails: [], sms: [], outbound: [], appUrl: null, secret: null,
    };
}

function readBody(req) {
    return new Promise((res) => { const c = []; req.on('data', (d) => c.push(d)); req.on('end', () => res(Buffer.concat(c).toString('utf8'))); });
}
const send = (res, code, body, type = 'application/json') => { res.writeHead(code, { 'content-type': type }); res.end(typeof body === 'string' || Buffer.isBuffer(body) ? body : JSON.stringify(body)); };

// ------------------------------------------------------------------ edge: routing, geocoding, tiles, control

export function startEdgeStub(state) {
    const server = http.createServer(async (req, res) => {
        if (/^https?:\/\//.test(req.url)) { state.outbound.push(new URL(req.url).host); return send(res, 403, 'e2e: the internet is off'); }
        const url = new URL(req.url, 'http://x');
        const raw = await readBody(req);
        if (url.pathname === '/__control') {
            if (req.method === 'POST') {
                const b = JSON.parse(raw || '{}');
                for (const k of ['routingDown']) if (k in b) state[k] = b[k];
                for (const k of ['chargeOutcome', 'checkoutOutcome', 'reusable']) if (k in b) state.paystack[k] = b[k];
                return send(res, 200, { ok: true });
            }
            if (req.method === 'DELETE') { state.mails.length = 0; state.sms.length = 0; state.webhooks.length = 0; state.paystack.calls.length = 0; return send(res, 200, {}); }
            return send(res, 200, { ...state, appUrl: undefined, secret: undefined });
        }
        if (url.pathname === '/__control/checkout') {
            const b = JSON.parse(raw || '{}');
            const out = await checkoutAction(state, b.code, b.action);
            return out ? send(res, 200, out) : send(res, 404, { error: 'unknown checkout' });
        }
        let m;
        if ((m = url.pathname.match(/^\/osrm\/route\/v1\/driving\/([-0-9.]+),([-0-9.]+);([-0-9.]+),([-0-9.]+)$/))) {
            state.routeRequests++;
            if (state.routingDown) return send(res, 503, { code: 'InvalidUrl', message: 'Service unavailable' });
            const points = roadPath([Number(m[2]), Number(m[1])], [Number(m[4]), Number(m[3])]);
            const d = pathMetres(points);
            return send(res, 200, { code: 'Ok', waypoints: [], routes: [{ distance: Math.round(d * 10) / 10, duration: Math.round((d / CITY_SPEED_MS) * 10) / 10, weight: d / CITY_SPEED_MS, weight_name: 'routability', geometry: encodePolyline(points), legs: [] }] });
        }
        if (url.pathname === '/nominatim/search') {
            state.searches++;
            const q = (url.searchParams.get('q') || '').toLowerCase().trim();
            const rows = q ? Object.entries(PLACES).filter(([name]) => q.includes(name.toLowerCase().split(' ')[0]) || name.toLowerCase().startsWith(q)).map(([name, p]) => place(name, p)) : [];
            return send(res, 200, rows);
        }
        if (url.pathname === '/nominatim/reverse') {
            const lat = Number(url.searchParams.get('lat')), lng = Number(url.searchParams.get('lon'));
            let best = null;
            for (const [name, p] of Object.entries(PLACES)) { const d = Math.hypot(p.at[0] - lat, p.at[1] - lng); if (!best || d < best.d) best = { d, name, p }; }
            return send(res, 200, { ...place(best.name, best.p), lat: String(lat), lon: String(lng) });
        }
        return send(res, 404, { error: 'not_found' });
    });
    // The app's HTTPS_PROXY points here: any attempt to reach a real host is recorded and refused.
    server.on('connect', (req, sock) => { state.outbound.push(req.url); sock.end('HTTP/1.1 403 Forbidden\r\n\r\n'); });
    return listen(server);
}

function place(name, p) {
    return {
        place_id: crypto.createHash('md5').update(name).digest().readUInt32BE(0), licence: 'Data © OpenStreetMap contributors, ODbL 1.0.', osm_type: 'way', osm_id: 1,
        lat: String(p.at[0]), lon: String(p.at[1]), category: 'highway', type: 'residential', place_rank: 26, importance: 0.1, addresstype: 'road', name, display_name: p.label,
        boundingbox: [String(p.at[0] - 0.001), String(p.at[0] + 0.001), String(p.at[1] - 0.001), String(p.at[1] + 0.001)],
    };
}

// ------------------------------------------------------------------ Paystack (+ BulkSMS), https

function authorization(state, email) {
    return {
        authorization_code: 'AUTH_e2e_' + crypto.createHash('md5').update(email || 'x').digest('hex').slice(0, 10), bin: '408408', last4: '4081', exp_month: '12', exp_year: '2030',
        channel: 'card', card_type: 'visa ', bank: 'TEST BANK', country_code: 'ZA', brand: 'visa', reusable: state.paystack.reusable, signature: 'SIG_e2e_' + crypto.createHash('md5').update(email || 'x').digest('hex').slice(0, 8), account_name: null,
    };
}

function txData(state, tx) {
    return {
        id: 5000000 + (parseInt(crypto.createHash('md5').update(tx.reference).digest('hex').slice(0, 6), 16) % 1000000), domain: 'test', status: tx.status, reference: tx.reference,
        amount: tx.amount, currency: tx.currency, channel: 'card', paid_at: tx.status === 'success' ? new Date().toISOString() : null, gateway_response: tx.status === 'success' ? 'Approved' : 'Declined',
        metadata: tx.metadata ?? null, customer: { email: tx.email, customer_code: 'CUS_e2e' }, authorization: authorization(state, tx.email), fees: 100,
    };
}

export async function deliverWebhook(state, event, data) {
    if (!state.appUrl) return;
    const body = JSON.stringify({ event, data });
    const sig = crypto.createHmac('sha512', state.secret).update(body).digest('hex');
    try {
        const r = await fetch(`${state.appUrl}/webhooks/paystack`, { method: 'POST', headers: { 'content-type': 'application/json', 'x-paystack-signature': sig }, body });
        state.webhooks.push({ event, reference: data.reference ?? data.transaction?.reference ?? null, status: r.status });
    } catch (e) { state.webhooks.push({ event, error: String(e.message) }); }
}

export function startPaystackStub(state, { key, cert }) {
    const ps = state.paystack;
    const server = https.createServer({ key, cert }, async (req, res) => {
        const url = new URL(req.url, 'https://x');
        const raw = await readBody(req);
        let body = {};
        try { body = raw ? JSON.parse(raw) : {}; } catch { /* ignore */ }
        // Map tiles are served here too: production tile hosts are https, and only https hosts enter the CSP.
        if (url.pathname.startsWith('/tiles/')) { res.writeHead(200, { 'content-type': 'image/png', 'cache-control': 'max-age=3600', 'access-control-allow-origin': '*' }); return res.end(TILE); }
        if (url.pathname === '/v1/messages') {
            const auth = req.headers.authorization || '';
            if (!auth.startsWith('Basic ')) return send(res, 401, { title: 'Unauthorized' });
            state.sms.push({ to: body.to, body: body.body, at: Date.now() });
            return send(res, 201, [{ id: 'sms-' + state.sms.length, type: 'SENT', to: body.to, status: { type: 'ACCEPTED' } }]);
        }
        if ((req.headers.authorization || '') !== `Bearer ${state.secret}`) return send(res, 401, { status: false, message: 'Invalid key' });
        ps.calls.push({ method: req.method, path: url.pathname, body });
        let m;
        if (url.pathname === '/transaction/initialize' && req.method === 'POST') {
            const code = crypto.createHash('md5').update(body.reference).digest('hex').slice(0, 12);
            ps.txs[body.reference] = { reference: body.reference, amount: Number(body.amount), currency: body.currency || 'ZAR', email: body.email, metadata: body.metadata, callback_url: body.callback_url, access_code: code, status: 'pending', kind: 'checkout' };
            return send(res, 200, { status: true, message: 'Authorization URL created', data: { authorization_url: `https://checkout.paystack.com/${code}`, access_code: code, reference: body.reference } });
        }
        if ((m = url.pathname.match(/^\/transaction\/verify\/(.+)$/))) {
            const tx = ps.txs[decodeURIComponent(m[1])];
            if (!tx) return send(res, 400, { status: false, message: 'Transaction reference not found' });
            if (tx.status === 'pending' && tx.kind === 'checkout') return send(res, 200, { status: true, message: 'Verification successful', data: { ...txData(state, tx), status: 'abandoned' } });
            return send(res, 200, { status: true, message: 'Verification successful', data: txData(state, tx) });
        }
        if (url.pathname === '/transaction/charge_authorization' && req.method === 'POST') {
            const status = ps.chargeOutcome;
            const tx = { reference: body.reference, amount: Number(body.amount), currency: body.currency || 'ZAR', email: body.email, metadata: body.metadata, status, kind: 'charge' };
            ps.txs[body.reference] = tx;
            if (status === 'success') setTimeout(() => deliverWebhook(state, 'charge.success', txData(state, tx)), 300);
            return send(res, 200, { status: true, message: 'Charge attempted', data: txData(state, tx) });
        }
        if (url.pathname === '/refund' && req.method === 'POST') {
            const id = ++ps.refundSeq;
            const tx = ps.txs[body.transaction];
            const refund = { id, status: 'processed', amount: Number(body.amount ?? tx?.amount ?? 0), currency: body.currency || 'ZAR', transaction: { id: 1, reference: body.transaction, amount: tx?.amount ?? 0, currency: 'ZAR' } };
            ps.refunds[id] = refund;
            return send(res, 200, { status: true, message: 'Refund has been queued for processing', data: { ...refund, status: 'pending', integration: 1, deducted_amount: 0, merchant_note: body.merchant_note ?? '', customer_note: '', refunded_by: 'ops@example.test', expected_at: new Date().toISOString(), domain: 'test', fully_deducted: false } });
        }
        if ((m = url.pathname.match(/^\/refund\/(\d+)$/))) {
            const r = ps.refunds[m[1]];
            if (!r) return send(res, 404, { status: false, message: 'Refund not found' });
            return send(res, 200, { status: true, message: 'Refund retrieved', data: r });
        }
        if (url.pathname === '/bank/validate') {
            return send(res, 200, { status: true, message: 'Personal Account Verification attempted', data: { verified: true, verificationMessage: 'Account is verified successfully', accountAcceptsDebits: true, accountAcceptsCredits: true, accountOpenForMoreThanThreeMonths: true, accountHolderMatch: true, accountOpen: true } });
        }
        if (url.pathname === '/transferrecipient') {
            return send(res, 200, { status: true, message: 'Transfer recipient created successfully', data: { active: true, currency: body.currency, name: body.name, type: body.type, recipient_code: 'RCP_e2e' + crypto.createHash('md5').update(String(body.account_number)).digest('hex').slice(0, 6), details: { account_number: body.account_number, bank_code: body.bank_code } } });
        }
        if (url.pathname === '/transfer' && req.method === 'POST') {
            ps.transfers[body.reference] = { reference: body.reference, amount: Number(body.amount), currency: body.currency, status: 'success' };
            return send(res, 200, { status: true, message: 'Transfer has been queued', data: { domain: 'test', amount: body.amount, currency: body.currency, reference: body.reference, source: 'balance', reason: body.reason, status: 'pending', transfer_code: 'TRF_' + body.reference.slice(-8), id: 1, integration: 1, recipient: 1, createdAt: new Date().toISOString(), updatedAt: new Date().toISOString() } });
        }
        if ((m = url.pathname.match(/^\/transfer\/verify\/(.+)$/))) {
            const t = ps.transfers[decodeURIComponent(m[1])];
            if (!t) return send(res, 404, { status: false, message: 'Transfer not found' });
            return send(res, 200, { status: true, message: 'Transfer retrieved', data: { ...t, transfer_code: 'TRF_' + t.reference.slice(-8) } });
        }
        return send(res, 404, { status: false, message: 'Not a stubbed endpoint' });
    });
    return listen(server);
}

/**
 * Paystack's hosted checkout, driven from a spec (harness.interceptCheckout routes checkout.paystack.com in the
 * browser, so that host is never contacted): "view" returns the transaction; "pay"/"decline" marks it, delivers the
 * signed charge.success webhook on success, and returns the callback URL the app gave at initialize, as Paystack does.
 */
export async function checkoutAction(state, code, action) {
    const tx = Object.values(state.paystack.txs).find((t) => t.access_code === code);
    if (!tx) return null;
    if (action === 'view') return { tx };
    tx.status = action === 'pay' ? state.paystack.checkoutOutcome : 'failed';
    if (tx.status === 'success') await deliverWebhook(state, 'charge.success', txData(state, tx));
    const cb = new URL(tx.callback_url);
    cb.searchParams.set('trxref', tx.reference); cb.searchParams.set('reference', tx.reference);
    return { tx, location: cb.toString() };
}

// ------------------------------------------------------------------ SMTP capture

function decodeQp(s) {
    return s.replace(/=\r?\n/g, '').replace(/=([0-9A-F]{2})/g, (_, h) => String.fromCharCode(parseInt(h, 16)));
}

export function startSmtpStub(state) {
    const server = net.createServer((sock) => {
        let buf = '', inData = false, rcpt = [], from = null;
        const say = (l) => sock.write(l + '\r\n');
        say('220 e2e-smtp ready');
        sock.on('data', (d) => {
            buf += d.toString('utf8');
            for (;;) {
                if (inData) {
                    const end = buf.indexOf('\r\n.\r\n');
                    if (end < 0) return;
                    const raw = buf.slice(0, end); buf = buf.slice(end + 5); inData = false;
                    const head = raw.split(/\r\n\r\n/)[0];
                    const header = (n) => (head.match(new RegExp(`^${n}: (.*(?:\\r\\n[ \\t].*)*)`, 'mi'))?.[1] || '').replace(/\r\n[ \t]/g, ' ');
                    let subject = header('Subject');
                    subject = subject.replace(/=\?utf-8\?Q\?(.*?)\?=/gi, (_, t) => decodeQp(t.replace(/_/g, ' '))).replace(/=\?utf-8\?B\?(.*?)\?=/gi, (_, t) => Buffer.from(t, 'base64').toString('utf8'));
                    state.mails.push({ from, to: rcpt, subject, body: decodeQp(raw), at: Date.now() });
                    rcpt = []; say('250 OK queued');
                    continue;
                }
                const nl = buf.indexOf('\r\n');
                if (nl < 0) return;
                const line = buf.slice(0, nl); buf = buf.slice(nl + 2);
                const cmd = line.slice(0, 4).toUpperCase();
                if (cmd === 'EHLO' || cmd === 'HELO') { sock.write('250-e2e-smtp\r\n250 8BITMIME\r\n'); }
                else if (cmd === 'MAIL') { from = line.match(/<(.*)>/)?.[1]; say('250 OK'); }
                else if (cmd === 'RCPT') { rcpt.push(line.match(/<(.*)>/)?.[1]); say('250 OK'); }
                else if (cmd === 'DATA') { inData = true; say('354 go ahead'); }
                else if (cmd === 'QUIT') { say('221 bye'); sock.end(); }
                else if (cmd === 'RSET' || cmd === 'NOOP') say('250 OK');
                else say('250 OK');
            }
        });
        sock.on('error', () => {});
    });
    return listen(server);
}

function listen(server) {
    return new Promise((resolve) => server.listen(0, '127.0.0.1', () => resolve({ port: server.address().port, close: () => { server.closeAllConnections?.(); server.close(); } })));
}
