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
                'resources/css/driver.css',
                'resources/js/driver/drive.js',
                'resources/js/driver/pages.js',
                'resources/css/business/portal.css',
                'resources/js/business/book.js',
                'resources/css/fleet/fleet.css',
                'resources/css/comms/comms.css',
                'resources/js/comms/comms.js',
            ],
            refresh: true,
        }),
    ],
});
