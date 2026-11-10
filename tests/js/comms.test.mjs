import { test } from 'node:test';
import assert from 'node:assert/strict';
import { pushSupport, urlBase64ToUint8Array } from '../../resources/js/comms/comms.js';

const full = { hasServiceWorker: true, hasPushManager: true, hasNotification: true };

test('push is offered only where the browser and the server can do it', () => {
    assert.equal(pushSupport({ ...full, configured: false }).state, 'not_configured');
    assert.equal(pushSupport({ ...full }).state, 'supported');
    assert.equal(pushSupport({ ...full, permission: 'denied' }).state, 'blocked');
    assert.equal(pushSupport({ hasServiceWorker: true, hasPushManager: false, hasNotification: false, userAgent: 'Firefox' }).state, 'unsupported');
});

test('iPhone Safari outside the home screen is told how to get push, not that it is unsupported', () => {
    const ua = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 Version/17.4 Mobile/15E148 Safari/604.1';
    const result = pushSupport({ hasServiceWorker: true, hasPushManager: false, hasNotification: false, userAgent: ua, standalone: false });
    assert.equal(result.state, 'needs_home_screen');
    assert.match(result.text, /Add to Home Screen/);
});

test('the VAPID key decodes to the 65-byte uncompressed point', () => {
    const key = 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4';
    const bytes = urlBase64ToUint8Array(key);
    assert.equal(bytes.length, 65);
    assert.equal(bytes[0], 4);
});
