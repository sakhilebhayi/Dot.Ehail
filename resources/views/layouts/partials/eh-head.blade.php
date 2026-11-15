{{-- Shared <head> for every Dot.Ehail page: self-hosted fonts and assets only, no third-party host. --}}
<meta charset="utf-8">
{{-- interactive-widget: on Android Chrome the on-screen keyboard resizes the page (the booking sheet and every form stay above it); iOS Safari ignores it, the sheet script sets --eh-kb. --}}
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content">
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
@auth
{{-- Signed in: the one minified Dot UI stylesheet carries Roboto, the type scale, the header, the search pill and the switcher (dot-ui 0.6.1). --}}
<link rel="preload" href="{{ asset('dot-ui/fonts/roboto/roboto-latin-wght-normal.woff2') }}" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="{{ asset('dot-ui/css/dot-ui.min.css') }}">
@else
@include('layouts.partials.eh-roboto')
@endauth
<script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">(function(d){try{var r=d.documentElement;r.classList.add('js');if(r.hasAttribute('data-theme-lock'))return;var k=r.dataset.themeKey||'eh-theme',v=localStorage.getItem(k)||r.dataset.themeDefault||'system',h=new Date().getHours(),dk=matchMedia('(prefers-color-scheme: dark)').matches;if(v==='night'){v=(h>=18||h<6||dk)?'dark':'light'}if(v==='light'||v==='dark'){r.dataset.theme=v}r.classList.toggle('dark',v==='dark'||(v==='system'&&dk))}catch(e){}})(document);</script>
@vite($viteEntries ?? ['resources/css/app.css', 'resources/js/eh/shell.js'])
@auth
{{-- Shared Dot UI (the header, the search pill, the platform switcher), served from this site's own public/dot-ui: CSP 'self', no inline style or script.
     Deferred, after the app's own entry so it never holds its script back. dot-ui.min.js, not dot-ui-core.min.js: the header search script calls
     DotSearchResults.attach() on DOMContentLoaded, and the core build only queues the results controller (it loads on first focus). --}}
<script src="{{ asset('dot-ui/js/dot-ui.min.js') }}" nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}" defer></script>
@endauth
