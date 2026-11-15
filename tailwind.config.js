import dotPreset from './dot-ui/tailwind/tailwind-preset.cjs';
import forms from '@tailwindcss/forms';
import typography from '@tailwindcss/typography';

/** @type {import('tailwindcss').Config} */
export default {
    // R-52: the shared dot-ui preset makes font-sans Roboto and adds text-dot-* (TYPOGRAPHY.md); no host font override.
    presets: [dotPreset],
    darkMode: 'class',

    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './vendor/laravel/jetstream/**/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    plugins: [forms, typography],
};
