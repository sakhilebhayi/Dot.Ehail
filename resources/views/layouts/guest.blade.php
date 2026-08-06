<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
        <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
        <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Manrope:wght@400;500;600;700&family=Overpass+Mono:wght@400;500;600&display=swap" rel="stylesheet">

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])

        <!-- Styles -->
        @livewireStyles

        <style>
            :root {
                --paper: #f6f5ef;
                --panel: #fdfcf8;
                --ink: #1b201a;
                --ink-soft: #565c53;
                --gold: #c99626;
                --gold-bright: #e2b13d;
                --sage: #5c8447;
                --sage-bright: #7fa863;
                --line: rgba(27, 32, 26, 0.12);
                --font-display: 'Space Grotesk', ui-sans-serif, sans-serif;
                --font-body: 'Manrope', system-ui, sans-serif;
                --font-mono: 'Overpass Mono', ui-monospace, monospace;
                --ease-out: cubic-bezier(0.23, 1, 0.32, 1);
            }
            html { background: var(--paper); }
            body { font-family: var(--font-body); background: var(--paper); color: var(--ink); }
            .font-display { font-family: var(--font-display); }
            .font-mono { font-family: var(--font-mono); }
            .press { transition: transform 160ms var(--ease-out); }
            .press:active { transform: scale(0.97); }
        </style>
    </head>
    <body>
        <div class="font-body text-[var(--ink)] antialiased">
            {{ $slot }}
        </div>

        @livewireScripts
    </body>
</html>
