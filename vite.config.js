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
                'resources/css/fleet/fleet.css',
            ],
            refresh: true,
        }),
    ],
});
