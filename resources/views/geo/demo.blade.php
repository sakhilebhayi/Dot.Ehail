<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex">
    <title>Geo module demo · Dot.Ehail</title>
    <script type="application/json" data-geo-config>@json($geoConfig)</script>
    @vite(['resources/js/geo/index.js'])
    <style>
        body { margin: 0; background: var(--eh-ground, #f1f4f0); color: var(--eh-ink, #0f1f19); font-family: var(--eh-font-ui, system-ui, sans-serif); }
        .demo { display: grid; grid-template-rows: 55vh auto; min-height: 100vh; }
        .demo__map { border-radius: 0; min-height: 55vh; }
        .demo__sheet { display: grid; gap: 1rem; align-content: start; padding: 1rem; background: var(--eh-surface, #fff); }
        .demo__sheet h1 { margin: 0; font-family: var(--eh-font-display, system-ui, sans-serif); font-size: 1.375rem; }
        .demo__note { margin: 0; font-size: 0.875rem; color: var(--eh-ink-2, #3a4a43); }
        .demo__row { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; }
        .demo__toggle { display: inline-flex; gap: 0.5rem; align-items: center; min-height: 44px; font-weight: 600; }
        .demo__toggle input { width: 20px; height: 20px; accent-color: var(--eh-accent, #0b6a3c); }
        @media (min-width: 960px) {
            .demo { grid-template-columns: 400px 1fr; grid-template-rows: 100vh; }
            .demo__map { order: 2; min-height: 100vh; }
            .demo__sheet { order: 1; overflow-y: auto; box-shadow: 1px 0 0 var(--eh-rule, #d3dbd5); }
        }
    </style>
</head>
<body>
<main class="demo eh-geo" data-geo-demo>
    <div class="demo__map" data-map aria-label="Map of pickup, destination and route"></div>

    <section class="demo__sheet" aria-labelledby="demo-title">
        <h1 id="demo-title">Geo module demo</h1>
        <p class="demo__note">Development page (local only). The driver below is simulated; search, routes and location are real calls through the server proxy.</p>

        <div class="eh-search" data-search="pickup">
            <label class="eh-search__label" for="demo-pickup">Pickup</label>
            <div class="eh-search__field">
                <span class="eh-search__marker" aria-hidden="true"></span>
                <input class="eh-search__input" id="demo-pickup" type="search" placeholder="Street, place or landmark" enterkeyhint="search">
                <button class="eh-geo-button" type="button" data-geo-search-submit>Search</button>
            </div>
            <p class="eh-search__hint" data-geo-search-hint></p>
            <ul class="eh-search__list" data-geo-search-list></ul>
            <p class="eh-search__status" data-geo-search-status></p>
        </div>

        <div class="demo__row">
            <button class="eh-geo-button eh-geo-button--quiet" type="button" data-locate>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="7"/><circle cx="12" cy="12" r="2.5" fill="currentColor"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/></svg>
                Use my location
            </button>
            <p class="eh-geo-status" data-locate-status role="status" aria-live="polite"></p>
        </div>

        <div class="eh-search" data-search="destination">
            <label class="eh-search__label" for="demo-destination">Destination</label>
            <div class="eh-search__field">
                <span class="eh-search__marker eh-search__marker--destination" aria-hidden="true"></span>
                <input class="eh-search__input" id="demo-destination" type="search" placeholder="Where to?" enterkeyhint="search">
                <button class="eh-geo-button" type="button" data-geo-search-submit>Search</button>
            </div>
            <p class="eh-search__hint" data-geo-search-hint></p>
            <ul class="eh-search__list" data-geo-search-list></ul>
            <p class="eh-search__status" data-geo-search-status></p>
        </div>

        <p class="eh-geo-status" data-route-summary role="status" aria-live="polite"></p>

        <div class="demo__row">
            <button class="eh-geo-button" type="button" data-simulate disabled>Start simulated driver</button>
            <label class="demo__toggle"><input type="checkbox" data-follow> Follow driver</label>
        </div>
        <p class="demo__note" data-simulation-note></p>
    </section>
</main>
</body>
</html>
