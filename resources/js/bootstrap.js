import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

// Reverb speaks the Pusher protocol, so laravel-echo still needs a Pusher
// client available globally even though this isn't Pusher's cloud service.
window.Pusher = Pusher;

const reverbKey = import.meta.env.VITE_REVERB_APP_KEY;

if (reverbKey) {
    window.Echo = new Echo({
        broadcaster: 'reverb',
        key: reverbKey,
        wsHost: import.meta.env.VITE_REVERB_HOST,
        wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
        wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
        // Private-channel auth hits /broadcasting/auth with the session
        // cookie; only the CSRF header is needed alongside it.
        auth: {
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            },
        },
    });
} else {
    console.warn('No Reverb credentials configured (VITE_REVERB_APP_KEY missing). Real-time updates disabled.');
}
