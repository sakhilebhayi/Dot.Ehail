{{-- Shared <head> for every Dot.Ehail page: self-hosted fonts and assets only, no third-party host. --}}
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="eh-tz" content="{{ \App\Support\Ui\LocalTime::timezone() }}">
<title>{{ isset($title) && $title ? $title.' | Dot.Ehail' : 'Dot.Ehail' }}</title>
@isset($description)<meta name="description" content="{{ $description }}">@endisset
<meta name="theme-color" content="#f1f4f0" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#1a2a24" media="(prefers-color-scheme: dark)">
<link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
<link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
<link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
<link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
<link rel="preload" href="{{ asset('fonts/barlow-latin-400-normal.woff2') }}" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="{{ asset('fonts/barlow-latin-600-normal.woff2') }}" as="font" type="font/woff2" crossorigin>
<script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">(function(d){try{var r=d.documentElement;r.classList.add('js');if(r.hasAttribute('data-theme-lock'))return;var k=r.dataset.themeKey||'eh-theme',v=localStorage.getItem(k)||r.dataset.themeDefault||'system',h=new Date().getHours(),dk=matchMedia('(prefers-color-scheme: dark)').matches;if(v==='night'){v=(h>=18||h<6||dk)?'dark':'light'}if(v==='light'||v==='dark'){r.dataset.theme=v}r.classList.toggle('dark',v==='dark'||(v==='system'&&dk))}catch(e){}})(document);</script>
@auth
{{-- Shared Dot UI (the platform switcher, the compact filter), served from this site's own public/dot-ui: CSP 'self', no inline style or script. The host token mapping lives in the design system's dot-ui stylesheet (imported by app.css). --}}
<link rel="stylesheet" href="{{ asset('dot-ui/css/dot-fonts.css') }}">
<link rel="stylesheet" href="{{ asset('dot-ui/css/dot-tokens.css') }}">
<link rel="stylesheet" href="{{ asset('dot-ui/css/dot-search.css') }}">
<link rel="stylesheet" href="{{ asset('dot-ui/css/dot-switcher-fonts.css') }}">
<link rel="stylesheet" href="{{ asset('dot-ui/css/dot-switcher.css') }}">
<script src="{{ asset('dot-ui/js/dot-switcher.js') }}" nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}" defer></script>
@endauth
@vite($viteEntries ?? ['resources/css/app.css', 'resources/js/eh/shell.js'])
