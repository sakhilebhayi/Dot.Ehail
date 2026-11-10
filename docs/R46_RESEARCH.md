# R-46 research

Each agent appends its own section. Keep every section on merge.

## Maps and location (geo agent, 2026-11-10)

Question: which providers for map tiles, road routing (distance, duration, polyline), and geocoding (search with autocomplete, reverse), South Africa first, international later, and what is honest when the owner has set no key. Sources: each provider's published usage policy or pricing page, fetched on 2026-11-10 where reachable (quotes below are short and verbatim), otherwise stated from knowledge and marked "(not re-verified)".

### Public OpenStreetMap-based services (no key)

| Service | What it offers | Policy as published | Fit for Dot.Ehail production |
|---|---|---|---|
| OSMF tile server `tile.openstreetmap.org` (raster) | Standard OSM raster tiles | Must show attribution "clearly on the map", send a valid Referer (web), honour caching, no bulk download or prefetch ("any fetching of tiles a user isn't currently viewing" is prohibited), no offline use. On commercial use: "Commercial services ... should be especially aware that access may be withdrawn at any point". No numeric limit; "We may block access, without notice, if your usage degrades the service." | **Usable at launch scale** with the rules followed (attribution bottom right, Referer sent by `strict-origin-when-cross-origin`, `keepBuffer: 1`, no service-worker tile cache). Not a contract: switch to a contracted tile provider before traffic grows. Chosen as the no-key default, with that risk written down. |
| OpenFreeMap (vector, `tiles.openfreemap.org/styles/liberty` etc.) | OpenMapTiles-schema vector tiles and styles | "There's no registration, no user database, no API keys, and no cookies"; "there are no limits on the number of map views or requests"; commercial use: "Yes"; "Attribution is required" (MapLibre adds it automatically). No raster tiles offered. | Policy is the most permissive, but it needs a WebGL vector renderer (MapLibre GL JS). Measured MapLibre 6.16.0: `maplibre-gl.mjs` 294 KB gzip + worker 147 KB gzip, about **440 KB gzip**, three times the 150 KB map-chunk budget, plus WebGL work on low-end Android. Rejected for now on bundle weight; the switch path is documented in R46_GEO.md. |
| Nominatim public `nominatim.openstreetmap.org` | Search and reverse geocoding | "an absolute maximum of 1 request per second"; "Provide a valid HTTP Referer or User-Agent identifying the application"; "Clearly display attribution"; caching asked for; **"Auto-complete search"** is listed as unacceptable use ("you must not implement such a service on the client side"); no separate commercial rule, but "Commercial applications should keep that in mind when relying on this API for serving paying customers." | **Usable as the no-key default for search-on-submit and reverse lookups** at launch scale: server-side proxy, one request per second for the whole app (enforced), 24 h cache, identifying User-Agent, attribution shown, NO autocomplete. |
| Photon public `photon.komoot.io` | Search-as-you-type, reverse | "You can use the API for your project, but please be fair - extensive usage will be throttled." "We do not guarantee for the availability". | Not a default for a commercial product with autocomplete traffic. Supported as a driver for an operator-run Photon (`EHAIL_PHOTON_URL`). |
| OSRM demo `router.project-osrm.org` | Road routing | Usage "is restricted to reasonable, non-commercial use-cases"; "Do not exceed 1 request per second"; no uptime guarantees. | **Not permitted** for fare quotes (commercial). Never a default. Supported only for an operator-run or contracted OSRM (`EHAIL_OSRM_URL`). Used once from a developer machine for the demo page check (non-commercial testing). |
| FOSSGIS Valhalla / OSRM (`*.openstreetmap.de`) | Routing | Same family of fair-use, non-commercial terms (not re-verified). | Not a default; Valhalla driver supports an operator-run server. |

### Keyed providers

| Provider | Routing | Geocoding | Tiles | Free allowance and price (not legal advice; check the live page) | SA coverage | Notes |
|---|---|---|---|---|---|---|
| Google Maps Platform | Routes API `computeRoutes` (Essentials = traffic-unaware; traffic-aware is Pro) | Places API (New) Text Search, Geocoding API | Map Tiles API / Maps JS | Since 1 March 2025 a free monthly cap per SKU: Essentials 10,000 calls, Pro 5,000, Enterprise 1,000; Map Tiles Essentials up to 100,000. Geocoding after the cap about $5 per 1,000 falling to $0.38 at volume. | Best address and POI coverage in SA, live traffic | Terms restrict storing content (place IDs may be kept; coordinates up to 30 days): the app does not cache Google answers by default. |
| Mapbox | Directions v5 (driving, driving-traffic) | Geocoding v6 (temporary), Search Box | GL JS vector, Static Tiles | Directions about 100,000 free requests/month then about $2 per 1,000; temporary geocoding 100,000 free/month; GL JS 50,000 map loads/month (third-party summaries; Mapbox's own page was not reachable) | Good roads, weaker SA addresses than Google | Temporary geocoding results may not be stored; permanent geocoding is a separate paid product. Caching off by default. |
| openrouteservice (HeiGIT) | Directions v2 | Pelias geocode/autocomplete/reverse | none | Free Standard plan: 2,000 directions/day, 40/min (geocoding quotas lower). Whether the free plan allows commercial use is ambiguous on the live page; confirm with HeiGIT before relying on it commercially. | OSM data: good major roads in metros, gaps in informal settlements and new estates | Key required (free sign-up). The cheapest real road routing to switch on. |
| HERE | Routing v8 | Geocoding and Search v7 | Raster/vector tiles | Freemium tier with monthly free transactions (not re-verified) | Good, commercial-grade | Not implemented as a driver (no stronger case than Google/Mapbox for SA; easy to add behind the same interface). |
| GraphHopper | Routing | Geocoding | none | Free plan for development/evaluation (not re-verified) | OSM | Not implemented; OSRM/Valhalla drivers cover self-hosting. |
| Self-hosted OSRM / Valhalla / GraphHopper + Photon/Nominatim | yes | yes | (tiles: self-host or contract) | Server cost only | OSM | Needs a VPS (shared cPanel host cannot run them). Drivers exist (`osrm`, `valhalla`, `photon`, `nominatim` with a custom URL). |

### Map engine weight (measured 2026-11-10, gzip -9)

| Engine | JS | CSS | Renders |
|---|---|---|---|
| Leaflet 1.9.4 (BSD-2) | `leaflet.js` 42.4 KB (in our build: map chunk 45.6 KB including our map code) | 3.5 KB | Raster XYZ, canvas vectors |
| MapLibre GL JS 6.16.0 (BSD-3) | 294.3 KB main + 147.3 KB worker | 10.2 KB | Vector (WebGL) |

### Decision summary (details in R46_DECISIONS.md D-200..D-214 and R46_GEO.md)
- Engine: Leaflet, lazy-loaded (map chunk 45.6 KB gzip JS + 2.6 KB CSS, inside the 150 KB budget).
- Tiles: OSMF raster by default under its policy; one env change moves to a contracted raster provider.
- Routing: `none` by default, because no no-key routing service permits commercial fare quotes. Booking says "Route pricing is unavailable: the operator has not configured a routing provider" and no route fare is offered. Straight-line distance is never billed.
- Geocoding: public Nominatim by default for search-on-submit and reverse (policy-compliant at launch scale); autocomplete appears automatically when the operator configures Google, Mapbox, ORS or a self-hosted Photon.
