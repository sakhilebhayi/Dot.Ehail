import { test, beforeEach, afterEach } from 'node:test';
import assert from 'node:assert/strict';
import { getJson, GeoRequestError } from '../../resources/js/geo/network.js';
import { locationErrorMessage, reasonForError } from '../../resources/js/geo/locate.js';

const realFetch = globalThis.fetch;
let calls;

const respond = (status, body, headers = {}) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json', ...headers } });

beforeEach(() => {
    calls = [];
});

afterEach(() => {
    globalThis.fetch = realFetch;
});

test('builds the query string and skips empty params', async () => {
    globalThis.fetch = async (url) => {
        calls.push(url);

        return respond(200, { results: [] });
    };

    await getJson('/geo/search', { q: 'Sandton City', lat: -26.1, lng: null });
    assert.equal(calls[0], '/geo/search?q=Sandton+City&lat=-26.1');
});

test('a busy answer is retried once after Retry-After', async () => {
    globalThis.fetch = async (url) => {
        calls.push(url);

        return calls.length === 1 ? respond(429, { reason: 'busy', message: 'busy' }, { 'Retry-After': '0' }) : respond(200, { ok: true });
    };

    assert.deepEqual(await getJson('/geo/search', { q: 'abc' }), { ok: true });
    assert.equal(calls.length, 2);
});

test('server reasons and messages come through unchanged', async () => {
    globalThis.fetch = async () => respond(503, { reason: 'not_configured', message: 'Route pricing is unavailable: the operator has not configured a routing provider.' });

    await assert.rejects(getJson('/geo/route'), (error) => {
        assert.ok(error instanceof GeoRequestError);
        assert.equal(error.reason, 'not_configured');
        assert.equal(error.status, 503);
        assert.match(error.message, /not configured a routing provider/);

        return true;
    });
});

test('a hung request times out with a slow-connection message', async () => {
    globalThis.fetch = (url, { signal }) => new Promise((resolve, reject) => signal.addEventListener('abort', () => reject(signal.reason)));

    await assert.rejects(getJson('/geo/route', {}, { timeoutMs: 20 }), (error) => error.reason === 'timeout' && /slow/.test(error.message));
});

test('a network failure says so', async () => {
    globalThis.fetch = async () => {
        throw new TypeError('Failed to fetch');
    };

    await assert.rejects(getJson('/geo/route'), (error) => error.reason === 'network');
});

test('geolocation errors map to plain-language reasons', () => {
    assert.equal(reasonForError({ code: 1 }), 'denied');
    assert.equal(reasonForError({ code: 2 }), 'unavailable');
    assert.equal(reasonForError({ code: 3 }), 'timeout');
    assert.match(locationErrorMessage('denied'), /browser settings/);
});
