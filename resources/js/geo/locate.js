// "Use my location": one explicit tap, a clear explanation when permission is blocked, and the fix's accuracy
// passed on so the map can draw it. The position goes to the caller only; nothing here sends it anywhere.

const MESSAGES = {
    unsupported: 'This browser cannot share your location. Search for the address instead.',
    denied: 'Location is blocked for this site. Allow it in your browser settings, or search for the address.',
    unavailable: 'Your phone could not find your position. Move outside or near a window, or search for the address.',
    timeout: 'Finding your position took too long. Try again, or search for the address.',
    insecure: 'Location needs a secure (https) connection.',
};

export function locationErrorMessage(code) {
    return MESSAGES[code] || MESSAGES.unavailable;
}

/** Maps a GeolocationPositionError code to our reason. */
export function reasonForError(error) {
    switch (error?.code) {
        case 1:
            return 'denied';
        case 3:
            return 'timeout';
        default:
            return 'unavailable';
    }
}

/** Current permission state without prompting: 'granted' | 'prompt' | 'denied' | 'unknown'. */
export async function locationPermission() {
    try {
        const result = await navigator.permissions?.query({ name: 'geolocation' });

        return result?.state || 'unknown';
    } catch {
        return 'unknown';
    }
}

/**
 * Resolves {lat, lng, accuracy, heading, speed, recordedAt} or rejects with {reason, message}.
 */
export function locateOnce({ timeoutMs = 15000, maximumAgeMs = 30000 } = {}) {
    return new Promise((resolve, reject) => {
        if (typeof window !== 'undefined' && window.isSecureContext === false) {
            reject({ reason: 'insecure', message: MESSAGES.insecure });

            return;
        }

        if (!('geolocation' in navigator)) {
            reject({ reason: 'unsupported', message: MESSAGES.unsupported });

            return;
        }

        navigator.geolocation.getCurrentPosition(
            (position) =>
                resolve({
                    lat: position.coords.latitude,
                    lng: position.coords.longitude,
                    accuracy: position.coords.accuracy,
                    heading: Number.isFinite(position.coords.heading) ? position.coords.heading : null,
                    speed: Number.isFinite(position.coords.speed) ? position.coords.speed : null,
                    recordedAt: position.timestamp,
                }),
            (error) => {
                const reason = reasonForError(error);
                reject({ reason, message: MESSAGES[reason] });
            },
            { enableHighAccuracy: true, timeout: timeoutMs, maximumAge: maximumAgeMs },
        );
    });
}

/**
 * Wires a button: shows "Finding you…" while busy, reports the outcome in statusEl, calls onFix(fix).
 */
export function bindLocateButton(button, statusEl, onFix) {
    const label = button.textContent;

    const run = async () => {
        button.disabled = true;
        button.setAttribute('aria-busy', 'true');
        button.textContent = 'Finding you…';
        statusEl.textContent = '';
        statusEl.dataset.kind = '';

        if ((await locationPermission()) === 'denied') {
            statusEl.textContent = MESSAGES.denied;
            statusEl.dataset.kind = 'error';
        } else {
            try {
                const fix = await locateOnce();
                statusEl.textContent = `Located to within ${Math.round(fix.accuracy)} m.`;
                onFix(fix);
            } catch (error) {
                statusEl.textContent = error.message;
                statusEl.dataset.kind = 'error';
            }
        }

        button.disabled = false;
        button.removeAttribute('aria-busy');
        button.textContent = label;
    };

    button.addEventListener('click', run);

    return () => button.removeEventListener('click', run);
}
