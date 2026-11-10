import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                // R-46 one entry per line so parallel branches merge cleanly
                'resources/js/geo/index.js',
                'resources/js/eh/shell.js',
                'resources/js/eh/landing.js',
                'resources/css/passenger.css',
                'resources/js/passenger/booking.js',
                'resources/js/passenger/trip.js',
                'resources/js/passenger/pages.js',
                'resources/js/passenger/fares.js',
                'resources/css/share.css',
                'resources/js/share/trip-share.js',
                'resources/css/driver.css',
                'resources/js/driver/drive.js',
                'resources/js/driver/pages.js',
                'resources/css/business/portal.css',
                'resources/js/business/book.js',
                'resources/css/fleet/fleet.css',
                'resources/js/ops/index.js',
                'resources/css/comms/comms.css',
                'resources/js/comms/comms.js',
            ],
            refresh: true,
        }),
    ],
});
