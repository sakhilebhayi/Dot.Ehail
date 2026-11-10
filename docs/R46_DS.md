# R-46 design system and shells (ds agent)

Branch `feat/r46-ds`. The identity ("Route Line"), its tokens and components, the five app shells, the landing page, auth and error pages, the Jetstream account pages, and the PWA basics. Decisions D-100 to D-115 in `docs/R46_DECISIONS.md`; skills record in `docs/R46_SKILLS.md`; research part 1 in `docs/R46_RESEARCH.md`; the visual contract in `DESIGN.md`.

## 1. Identity in one paragraph

A ride is a line between two stops. The logo's yellow disc is the stop and its green chevron is the travel; the system draws everything as a route line with stops on cool map paper, in the sign green of South African direction signs and the yellow of road markings, set in Barlow (highway and licence-plate lettering). The landing's signature is the fare line: the eight parts of a fare as stops, ending in the yellow stop that is your total. Not cream and serif, not neon on black, no gradients, glass, glows, side stripes or metric tiles.

## 2. Files

| Path | What |
|---|---|
| `resources/css/ds/tokens.css` | `--eh-*` tokens, light default, night under `prefers-color-scheme: dark` or `data-theme="dark"`, map palette `--eh-map-*`, ride-category line colours `--eh-line-1..4` |
| `resources/css/ds/fonts.css` | `@font-face` for self-hosted Barlow 400/500/600/700 and Barlow Condensed 600/700 (`public/fonts/`, OFL licence beside them) |
| `resources/css/ds/base.css` | `.eh` page base, focus ring, selection, skip link, reduced motion, text helpers |
| `resources/css/ds/components.css` | every `<x-eh.*>` component |
| `resources/css/ds/shells.css` | passenger/driver top bar and tab bar, driver state bar, side shell (ops, business, fleet), guest shell, legal prose, error pages |
| `resources/css/ds/landing.css` | the landing page |
| `resources/css/ds/jetstream.css` | Jetstream account pages and the legacy app shell mapped onto tokens |
| `resources/js/eh/shell.js` (+ `sheet.js`, `theme.js`) | theme choice, side drawer, account menu, bottom sheet, tabs, dialogs, toasts, service worker registration |
| `resources/js/eh/landing.js` | fare line, driver index, reveals, hero crossfade |
| `resources/views/components/eh/*` | 29 components |
| `resources/views/layouts/{passenger,driver,ops,business,fleet,guest,app}.blade.php` + `partials/eh-*` | shells |
| `app/View/Components/{Passenger,Driver,Ops,Business,Fleet}Layout.php` | `<x-passenger-layout title="..." :bleed="true" scope="...">` etc. |
| `app/Support/Ui/{Navigation,RoleHome,Icons,MoneyFormatter,Duration}.php` | role-aware navigation, role homes, icon set, money and time formatting |
| `app/Http/Controllers/Shell/*` | one placeholder controller per shell home, plus `RoleHomeController` (`/home`) |
| `routes/ehail/{passenger,driver,ops,business,fleet}.php` | `ride.home`, `drive.home`, `ops.home` (operator middleware), `business.home`, `fleet.home`, `home` |
| `app/Providers/EhailDesignServiceProvider.php` | scoped `RoleHome` and `Navigation` |
| `public/manifest.webmanifest`, `public/sw.js`, `public/offline.html`, `public/icons/*` | PWA |
| `public/images/landing/*.webp` | five photographs (credited on the page) |

## 3. Contract for wave 2

### Layouts

```blade
<x-passenger-layout title="Ride" :bleed="true">
    <x-slot:safety> ... replaces the default Safety control (e.g. an SOS button during a trip) ... </x-slot:safety>
    <x-slot:header> ... page heading row (omit on map-first screens) ... </x-slot:header>
    <x-slot:head> ... extra <head> content: page-specific Vite entries go here ... </x-slot:head>
    ... content ...
</x-passenger-layout>
```

- Passenger: `$safety`, `$header`, `$head`, `bleed`. Default safety control: a link to `ride.safety` once that route exists, otherwise a dialog with 10111, 10177 and 112 as calls.
- Driver: same slots plus `$state` (render `<section class="eh-statebar" data-state="online|offline|busy">` with `.eh-statebar__title` and `.eh-statebar__sub`; see `shell/driver-home.blade.php`). Default theme "night".
- Ops / business / fleet: `$header`, `$head`, `scope` (the name shown above the side navigation, e.g. the fleet or company).
- Replace a shell home by pointing its route at your controller and keeping the name; the shell controllers are deliberately trivial.
- Navigation (`App\Support\Ui\Navigation::SURFACES`) lists each destination with candidate routes; register the wave-2 route name listed there (`ride.trips`, `ride.safety`, `ride.account`, `drive.earnings`, `drive.trips`, `drive.account`, `drive.safety`, `ops.live`, `ops.rides`, `ops.drivers`, `ops.verification`, `ops.safety`, `ops.support`, `ops.pricing`, `ops.finance`, `ops.fleets`, `ops.business`, `ops.insights`, `business.trips|people|policies|invoices|settings`, `fleet.vehicles|drivers|earnings|documents|maintenance`) and it appears in the menu by itself; until then the item is hidden or falls back to today's working page.
- Each layout loads `app.css` and `shell.js` (2.4 KB gzip). Add page scripts through the `head` slot with `@vite([...])`; keep the passenger and driver first view under the 150 KB JS budget.

### Components (`<x-eh.*>`)

| Component | Props (defaults) | Notes |
|---|---|---|
| `button` | `variant` primary/secondary/ghost/signal/danger, `size` sm/md/lg/driver, `href`, `type`, `icon`, `icon-right`, `loading`, `disabled`, `block` | 36/44/48/56 px; link when `href`; `aria-busy` when loading; disabled link loses its href |
| `icon-button` | `icon`, `label` (required), `variant`, `size` md/driver, `href` | label becomes `aria-label` and title |
| `icon` | `name`, `size`, `label` | 114 icons (`App\Support\Ui\Icons::names()`); decorative unless `label` |
| `field` | `label`, `name`, `type` (incl. textarea), `id`, `value`, `hint`, `error`, `optional`, `size` md/driver | error read from the validation bag; `aria-describedby` and `aria-invalid` wired |
| `select` | `label`, `name`, `options`, `value`, `placeholder`, `hint` | native select |
| `switch` | `label`, `name`, `checked`, `description` | `role="switch"`, visible track in both states |
| `segmented` | `legend`, `name`, `options`, `value` | radio group |
| `tabs`, `tab-panel` | `items`, `active`, `label`, `id` | links with `href` become a nav row; otherwise an ARIA tablist (arrow keys in shell.js) |
| `chip` | `pressed`, `href`, `current`, `icon` | toggle or filter link |
| `badge`, `status` | `tone`; `status` takes a ride status (old and new names) | always a word, never colour alone |
| `money` | `minor` (int), `currency` ZAR, `size` md/lg/xl, `signed`, `muted`, `whole` | `<data value>`; "R1 250.50"; minus read as "minus" |
| `eta` | `seconds`, `at`, `size`, `prefix` | "4 min, arrives 14:32" |
| `stepper` | `steps` [{label, meta}], `current`, `orientation`, `stateful` | the route line; `aria-current="step"` |
| `list-row` | `title`, `meta`, `icon`, `href`, slots `lead`, `trail` | inside `<ul class="eh-list">` |
| `empty` | `title`, `pending`, `heading-level`, slot `actions` | dashed line while the destination is not built |
| `skeleton` | `lines`, `block` | `role="status"` |
| `toasts`, `toast` | | flashes `status`, `success`, `error`, `flash.banner`; `window.ehToast(message, {tone})` |
| `dialog` | `id`, `title`, slot `footer` | native `<dialog>`; open with `data-eh-dialog-open="id"` |
| `banner` | `tone` info/go/caution/stop/safety, `title`, `icon`, `dismissible`, `emergency` | safety tone adds 10111, 10177, 112 calls |
| `avatar` | `name`, `size`, `src` (same host only) | initials on a stable pale tint |
| `rating-stars` | `value`, `count`, `show-value` | display only |
| `map-frame` | `id`, `label`, `height`, `bleed`, `message` | geo contract below |
| `stat-line` | `items` [{label, value}] | null prints a dash with "not available" |
| `kbd`, `logo`, `sheet` | | sheet: `id`, `title`, `snap` peek/half/full, `snaps`, `dock` |

### Map frame contract (geo)

`<x-eh.map-frame>` renders `[data-eh-map][data-map-state="pending"]` with `.eh-mapframe__canvas[data-eh-map-canvas]` and a status message `[data-eh-map-message]`. The geo script mounts the map into the canvas, then sets `data-map-state="ready"` (hides the status) or `"unavailable"` (keeps the status; write the reason into `[data-eh-map-message] span`). Map colours: `--eh-map-land, -road, -road-casing, -road-major, -road-major-casing, -water, -park, -building, -label, -label-halo, -route, -route-casing, -route-alt, -pickup, -dropoff, -driver` (night values swap with the theme; read them with `getComputedStyle(document.documentElement)` and re-read on the theme change).

### Bottom sheet

`<x-eh.sheet id="book" title="Where to?" snap="peek">`: non-modal, over a bleed map. Handle is a button: click or Enter cycles up, ArrowUp/ArrowDown move a snap, Home/End jump, Escape collapses; pointer drag snaps to the nearest point. Emits `eh:sheet` with `{snap}`. Docks as a 26rem side panel at 1024px and wider (`:dock="false"` to keep it a sheet). On phones it sits above the tab bar. Reduced motion: no transition.

## 4. Role routing

`/home` sends people to their role's surface: operator to `/ops`, driver to `/drive`, everyone else to `/ride` (`App\Support\Ui\RoleHome`). It is the PWA start URL and the logo link in the legacy shell. `/dashboard` keeps rendering its privacy-scoped overview (16 existing tests assert its figures) and now starts with the person's apps, primary first (decision D-104).

## 5. Accessibility

- Contrast, measured (WCAG relative luminance), all 42 checked pairs pass. Light: ink on ground 15.39, ink-2 on ground 8.45, ink-3 on surface-2 4.97, white on sign green 6.69, dark ink on yellow 10.19, go 5.32, caution 6.31, stop 5.38, info 6.58, focus ring 5.38 on ground, field border 3.22 on ground and 3.57 on surface. Night: ink 15.85, ink-2 9.85, ink-3 on surface-2 5.63, dark ink on mint 8.75, tint ink 8.83, stop 6.94, white on SOS red 5.47, focus 8.68, field border 3.88.
- Focus: 3px ring in `--eh-focus`, offset 2px, on every interactive element; drawers trap nothing but make the content behind `inert`.
- Targets: 44px minimum; driver primary actions 56px; tab bar items full height.
- Landmarks: one `main#main`, a skip link first on every page, labelled navs, one `h1`.
- Reduced motion: transitions and animations collapse to 0.01ms; the landing's reveals and crossfade are off; the sheet does not animate.
- Without script: navigation is visible (side nav stays in the page), the fare line shows every explanation, flashes render in `<noscript>`.

## 6. Performance

- JS: `shell.js` 6.2 KB (2.45 KB gzip), `landing.js` 2.6 KB (1.07 KB gzip). Shells do not load Echo, Pusher or Livewire unless a page renders a Livewire component.
- CSS: `app.css` 119 KB (20.5 KB gzip), including Tailwind for the Jetstream and legacy views. Once wave 2 retires the legacy views, Tailwind can go and the ds CSS alone is about 45 KB (8 KB gzip).
- Fonts: 6 files of about 22 KB, two preloaded. Landing photographs: WebP with `srcset`, 19 to 128 KB each, lazy below the fold; the crossfade photographs load only after the page, never on Save-Data.
- No third-party host anywhere (tested in `PublicPagesTest`).

## 7. PWA

`manifest.webmanifest` (start `/home`, standalone, maskable icons cut from the real logo), `sw.js` (caches only `/build/assets/*`, `/fonts/*`, `/icons/*`, `/offline.html`, the manifest; navigations always go to the network and fall back to the offline page; nothing personal is cached), `offline.html` (self-contained, emergency numbers as calls). Registered by `shell.js` on pages that carry `data-sw="on"` (every shell and the guest pages); the landing page does not load `shell.js`, so the worker is installed from the first signed-in or sign-in page.

## 8. Verification

- PHP: full suite 603 before (596 pass, 7 skipped); after: see the report line in section 10. New tests: `tests/Feature/Ehail/Ds/{ShellPagesTest,NavigationTest,ComponentsTest,PublicPagesTest}.php`, `tests/Unit/MoneyFormatterTest.php`.
- JS: `node --test "tests/js/*.test.mjs"`: 3 tests (sheet snaps, nearest snap, theme rules); there were none before.
- `npm run build`, `php artisan view:cache`, `php artisan route:cache` pass.
- Screenshots: `docs/R46_screens/ds/<page>-<width>-<light|dark>.jpg` at 390, 768 and 1440 for landing, login, forgot password, 404, terms, ride, drive (not registered and online), business, fleet (none and with a fleet), dashboard, profile, driver desk, ops. Captured headless with images forced to load and reveals settled.
- Detectors: impeccable `detect` clean on every new page; findings left only on pre-R-46 pages (Livewire's own progress-bar glow). Hallmark sloplint landing clean. Taste inspector landing 0 FAIL.

## 9. Known limits and follow-ups

- The legacy pages (dashboard, rides, driver desk, operator pages) keep their fixed-dark inline styles; the legacy shell pins the night theme for them until wave 2 replaces them. Their Material Symbols icons were swapped for the kit's icons.
- `config/livewire` progress bar colour and its built-in glow style are Livewire's; not changed (not ds-owned).
- `App\Models\User` still appends `profile_photo_url` (Jetstream's ui-avatars default) to serialised users; no view renders it any more. Owner of the model may override `defaultProfilePhotoUrl()`.
- `App\Http\Middleware\SecurityHeaders` (not ds-owned) still allows Google Fonts, the Tailwind CDN and Unsplash in its report-only CSP and denies geolocation in Permissions-Policy. With this branch nothing needs those hosts; the passenger app will need `geolocation=(self)`.
- No i18n extraction yet (English only).
- `.impeccable/design.json` sidecar not produced (documenter not run).
