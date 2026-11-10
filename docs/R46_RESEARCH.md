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
Each wave-1 agent appends its own section. Sections are independent; keep both sides on merge conflicts.

## Safety and trust (safety agent, 2026-11-10)

### Method (read this first)
Written from the agent's own knowledge of the ride-hailing industry and South African law and practice, current to about mid-2026. **No live web sources were fetched in this session**, nothing was verified against a primary source today, and no lawyer was consulted. Product descriptions are of publicly announced features as generally reported; availability differs by country and changes often. Every legal point below is marked **to be confirmed by the owner's legal adviser**; none of it is legal advice and nothing in the build depends on a legal claim being true (document types are configuration, not code).

### What the large platforms do

| Feature | Who has it (as publicly described) | What we took |
|---|---|---|
| Trip PIN / verify your ride | Uber (optional PIN, some markets mandatory), Lyft, Bolt, DiDi (in some markets) | Dispatch owns a 4-digit PIN (contract section 2); safety exposes a `RidePinSource` hook in the safety card |
| Share trip status / live link | Uber, Bolt, Lyft, Grab, DiDi, inDrive | `/t/{token}`: expiring, revocable, first names + vehicle only, no cookies, no addresses |
| Trusted contacts | Uber (up to 5), Lyft, Bolt, DiDi | Up to 5, double opt-in by email link, per-contact opt-out in every email |
| Emergency button | All; Uber's in-app button can pass location to some dispatch centres (US/other markets); Bolt and DiDi connect to local numbers or security partners in some countries; in South Africa platforms have publicised links with private armed-response providers | SOS creates an incident, alerts ops and confirmed contacts, shows 10111 / 10177 / 112; no private responder integration (owner decision) |
| Anomaly detection (RideCheck-style) | Uber RideCheck (long unexpected stops, route deviation, possible crash); DiDi and Grab have similar route-deviation alerts | Geo emits `RouteDeviationDetected` / `UnusualStopDetected`; safety asks both parties "are you OK?"; no answer in N minutes or "I need help" escalates |
| Audio recording | Uber (some markets, encrypted on device), DiDi (in-trip recording in Latin America), Bolt (trials) | Not built: needs a legal basis under POPIA and a retention/consent design; listed as an owner decision |
| Driver selfie / real-time ID check | Uber Real-Time ID Check, Bolt, DiDi face checks | Not built: needs a face-matching provider (biometric data is special personal information under POPIA). Profile photo is reviewed by a human and shown to the passenger |
| Women-preference matching | DiDi (women drivers for women riders in some markets), Uber "Women Rider Preference" (pilots in the US and elsewhere), Grab, inDrive in some regions, several SA operators | Not built in wave 1; it is a dispatch matching filter plus a policy question (who may opt in, verification of gender). Owner decision |
| Background checks | US: third-party checks (Checkr etc.); SA: platforms report SAPS police clearance requirements | Contract section 7: manual document review only; a police clearance certificate can be added as a document type in config |
| Ratings with minimum thresholds | All; Uber/Bolt deactivate below thresholds | Bands on sensitive surfaces, moderation, appeals; **never** auto-ban on ratings |

### Known failures and complaints (patterns, not citations)
- **Slow or scripted support after an emergency**: riders and drivers report reaching a chatbot or getting a reply hours later. Our answer: SOS alerts are in-app to every operator at once, escalate on timers measured in minutes, and the "what we did" screen says exactly what happened, including failures.
- **Panic buttons that only call the platform**: users press SOS expecting police. Our copy says plainly that Dot.Ehail does not dispatch emergency services and shows the numbers with tel: links on every safety surface.
- **Vehicle or driver mismatch** (a different person or car arrives): the passenger card shows the approved photo, first name, make, model, colour and registration with a "check before you get in" line; a mismatch has its own report category.
- **Account sharing and rented accounts by drivers** (widely reported in SA): document review and profile photo help but do not solve it without live selfie checks (not built).
- **Unfair deactivation on ratings**: drivers complain of deactivation without a hearing. We hide abusive comments, allow one appeal per rating, exclude upheld ones, and never act automatically on ratings.
- **Over-sharing location**: share links that never expire or reveal home addresses. Ours expire 30 minutes after the trip, carry no addresses and round the car's position to about 100 m.

### South African context
- **Crime around ride-hailing**: public reporting since 2017 describes attacks on drivers (hijackings, robberies of drivers lured by fake ride requests, especially at night and in some townships), conflict between metered-taxi/minibus-taxi operators and e-hailing drivers at ranks and malls, and attacks on passengers (including impersonation of drivers). Driver safety (passenger verification facts, private reports, cancellation-pattern flags, driver SOS) matters as much as passenger safety.
- **Armed-response and medical providers**: private security companies offer app-triggered armed response to the public and to platforms. Integrating one is a commercial contract plus an API and is the owner's decision (see R46_SAFETY.md). Nothing in the build claims such a response exists.
- **Emergency numbers** (from the agent's knowledge; widely published): **10111** SAPS flying squad / police emergency; **10177** ambulance and fire (national emergency number; metros also run their own lines); **112** emergency from any mobile phone (works without airtime on SA networks). Shown with tel: links. To be re-checked by the owner before launch, together with any metro-specific numbers worth adding.
- **Regulation of e-hailing (to be confirmed by the owner's legal adviser)**: the National Land Transport Act 5 of 2009 requires an **operating licence** for public transport services; the National Land Transport Amendment Act (passed by Parliament 2023 and assented to in 2024, as the agent understands it) explicitly recognises **e-hailing** as a mode and places duties on e-hailing platforms (for example not to allow drivers or vehicles without valid operating licences, and to share information with regulators). The commencement date and the regulations (vehicle age, area of operation, number caps) need confirmation. Operating licences are issued per vehicle by provincial regulatory entities and are often delayed; the platform must decide how to treat applications in progress (our config only knows "approved document with an expiry date").
- **Driver documents typically required** (industry practice; legal list to be confirmed): South African ID (or passport with a valid work permit for foreign nationals), driver's licence, **professional driving permit (PrDP)** in category P (passengers for reward; required by the Road Traffic Act regulations, renewed every two years as the agent understands it; requires a medical certificate and fingerprint-based criminal check at application), police clearance certificate (many platforms ask for it), profile photo. **Vehicle**: licence disc (registration), roadworthy certificate (a public transport vehicle needs a current certificate; frequency to be confirmed), passenger liability insurance, operating licence / e-hailing permit, vehicle photos; platforms also set vehicle age limits per category.
- **POPIA (Protection of Personal Information Act 4 of 2013)**: identity numbers and photos of identity documents are personal information; criminal behaviour and biometric data are **special personal information** with stricter conditions. Location data during a trip is personal information. What the build does: purpose-limited collection (only the configured documents), encryption at rest for identifiers and contact details, private disks, policy-checked streaming, an audit row for every non-owner view, minimisation on public surfaces (first names, no addresses), EXIF/GPS stripped from photos, no cookies on the public share page. **Open for the owner and legal adviser**: retention periods for verification documents after a driver leaves, incident records and trip trails; the lawful basis for sharing location with trusted contacts (consent by the person pressing SOS is the working assumption); an Information Officer registration with the Information Regulator; cross-border transfer if storage moves offshore.
Each wave-1 agent appends its own section. Keep both sides on merge conflicts.

## Payments and driver economics (money agent, 2026-11-10)

### Method, stated plainly

- **Paystack API facts** come from Paystack's own machine-readable API description, the OpenAPI file in the public repository `PaystackOSS/openapi` (`dist/paystack.yaml`, fetched 2026-11-10). The request and response shapes used in the code and in the contract tests (charge authorization, verify, refund, bank validate, transfer recipient, transfer, transfer verify) were read from that file, schema by schema.
- **paystack.com documentation pages refused automated fetches (HTTP 403)**, so the narrative guides (recurring charges, webhooks, transfers) could not be read page by page in this session. Statements below that come from those guides are marked *(from prior knowledge, not re-read today)*: the owner should confirm them in the dashboard before going live.
- **Market facts** (commission levels, cash risk, fees) come from web searches that returned summaries of South African news and support pages (ITWeb, TopAuto, The Mercury/IOL, BusinessTech, Paystack support and pricing pages, PayFast fees page). These are reported figures, sometimes contradictory, and they are labelled as such. No survey or interview was done.
- Nothing here was tested against a live Paystack account: there are no keys in this environment. Every Paystack interaction in the code is exercised against a contract fake built from the OpenAPI shapes.

### How South Africans pay for rides and where Paystack fits

| Option | What it is | Fit for ride-hailing |
|---|---|---|
| Card (Visa/Mastercard, local debit and credit) | The default for app-based ride-hailing; saved card charged after the trip | Primary method. Paystack, Peach Payments, PayFast, Stitch and others process cards in ZA |
| Instant EFT / pay-by-bank (Ozow, Capitec Pay, Stitch) | Bank-authorised push payment per transaction | Works for a one-off checkout, not for a silent charge after the trip (needs the payer each time). Paystack ZA offers "Pay with Bank" (Instant EFT via Ozow and Capitec Pay) for approved merchants |
| QR wallets (SnapScan, Zapper) | Scan to pay | Payer-initiated; same limitation as EFT. Paystack lists SnapScan/QR for ZA; third-party fee figures disagree |
| Cash | Paid to the driver | Still widely used and expected in parts of the market, and the main robbery risk for drivers (below) |
| Business accounts / invoicing | Employer pays monthly | Wave 2 business domain; seam exists |

Reported Paystack ZA pricing: local cards 2.9% + R1 (R1 waived under R10), Instant EFT 2%, international cards 3.1% + R1, all excluding VAT (Paystack ZA pricing page and support article, via search summary). PayFast lists Instant EFT 2% (minimum R2) and Capitec Pay 3.5% + R2. Fees are set per account; the owner should read them off the Paystack dashboard.

### What Paystack supports that this build uses

- **Saved cards / charge_authorization.** After a successful card transaction Paystack returns an `authorization` object (`authorization_code`, `bin`, `last4`, `exp_month`, `exp_year`, `channel`, `card_type`, `bank`, `country_code`, `brand`, `reusable`, `signature`, `account_name`). When `reusable` is true, `POST /transaction/charge_authorization` (`email`, `amount` in the lower denomination, `authorization_code`, optional `reference` (only `-`, `.`, `=` and alphanumerics), `currency`, `metadata` as stringified JSON) charges it again server side. Source: OpenAPI `TransactionChargeAuthorization`, `ChargeAuthorizationResponse`.
- **No tokenise-without-charge for cards.** The OpenAPI file has no card tokenisation or pre-authorisation/capture endpoint. The documented way to get a reusable card authorisation is a first successful charge *(the "charge a small amount, then refund" advice is from prior knowledge of Paystack's recurring-charges guide, not re-read today)*. Decision D-603 follows this: a R1.00 card-only checkout (`channels: ["card"]`), save the authorisation if reusable, refund at once.
- **Pre-authorisation reality.** There is no hold-then-capture flow in Paystack's public API for ZA cards, so the platform cannot reserve the fare at request time. The contract's `authorise` therefore checks that a chargeable method exists; `capture` is the post-trip charge. Consequence: a declined card after the trip is a real risk, handled by retries, a "payment due" block on new requests, and the ops exceptions list.
- **Verify.** `GET /transaction/verify/{reference}` returns the transaction with `status`, `amount`, `currency`, `reference`, `id`, `gateway_response`, `authorization`. The code records a payment as paid only from this answer, never from a webhook body or the charge response alone.
- **Refunds.** `POST /refund` (`transaction` = our reference or id, optional `amount` (not more than the original), `currency`, `customer_note`, `merchant_note`); response `data` has `id`, `status`, `amount`, `currency`, `transaction{reference,...}`, `expected_at`. `GET /refund/{id}` fetches status. Status values seen in the API and webhooks: pending, processing, processed, failed *(the `refund.pending/processing/processed/failed` webhook names are from prior knowledge)*. Card refunds take days to reach the cardholder.
- **Webhooks.** Paystack signs each webhook with `x-paystack-signature`, a hex HMAC-SHA512 of the raw body using the secret key *(prior knowledge; this was already implemented and tested in the existing controller)*. Paystack sends no event id, so the build derives an idempotency key from the event name and the object's id, reference, status and codes.
- **Transfers (payouts) in ZA.** Recipient type `basa` for South African bank accounts (`POST /transferrecipient`: `type`, `name`, `account_number`, `bank_code`, `currency`), then `POST /transfer` (`source: "balance"`, `amount`, `recipient` code, `reference` (lowercase, 16+ characters, idempotency), `reason`, `currency: ZAR`), `GET /transfer/verify/{reference}`. Prerequisites reported by Paystack support pages (via search): transfers are for **Registered Businesses** only (not Starter businesses); ZA businesses fund the Paystack balance by EFT top-up (reported 1% top-up fee); transfers go to SA bank accounts only; reported fee R3 per transfer (charged on success and failure); OTP on transfers must be disabled for API-driven payouts *(prior knowledge)*. Hence Transfers is **off by default** here (D-610) and the manual bank-file batch is always available.
- **Bank account checks in ZA.** `GET /bank/resolve` is the Nigeria/Ghana-style name lookup. For ZA the API offers `POST /bank/validate` (`account_name`, `account_number`, `account_type` personal|business, `bank_code`, `country_code: ZA`, `document_type` identityNumber|passportNumber|businessRegistrationNumber, `document_number`) returning `verified`, `verificationMessage`, `accountAcceptsCredits`, `accountHolderMatch`, `accountOpen`. The build uses validate, sends the ID number for that one call and never stores it (POPIA minimisation).
- **Settlements.** `GET /settlement` and `GET /settlement/{id}/transactions` exist, but the OpenAPI file gives no response schema for them, so the build does not code against an undocumented shape: reconciliation accepts the CSV export the operator downloads from the dashboard (columns `reference`, `amount`).

### Cash, driver safety and payouts

- **Cash risk.** Drivers and driver associations in SA have long linked cash trips to robbery and hijacking; a Western Cape e-hailing association attributed most crime affecting drivers to cash payments (search summary of ITWeb/IOL/Bisnow coverage, mostly 2016-2017). Uber SA introduced a cash indicator in 2017 so drivers could decline cash trips without penalty. In October 2025 Bolt SA warned riders against cash "offline" trips arranged outside the app (The Mercury). Current incident statistics were not found. Design consequences here: cash is **off by default**, can be limited to categories, and each driver must opt in; the driver confirms the amount, and a shortfall goes to support rather than blocking the passenger.
- **Commission norms (as publicly reported, conflicting).** Uber SA standard commission reported at 25% plus a booking fee (ITWeb, Nov 2025). Bolt SA reported at 20% with a 5% booking fee in one ITWeb article and 25% in another. Driver groups claimed effective deductions up to 50% in July 2025; a TopAuto worked example found 34% on one Uber trip. inDrive was reported letting drivers keep 99% of fare income in a promotion (ITWeb). The build ships **no market commission figure as fact**: the rate is an owner setting (`EHAIL_COMMISSION_PERCENT`, default 20 until pricing's commission plans are bound).
- **Driver complaints that shape the design.** Opaque deductions (drivers cannot see how the fare became their earnings) and effective take rates above the advertised commission are the recurring public complaint. Every earning row here carries fare, commission, deductions, net, cash collected, the pricing plan label and estimate-vs-actual with reasons; every payout lists the exact earnings and cash netted.
- **Payout cadence.** Weekly payouts are the norm drivers expect from the large platforms, with instant/daily cash-out offered at a fee by some *(prior knowledge; not re-verified today)*. Paystack's own merchant settlement in ZA is reported as T+1 or T+2 business days (sources disagree). The build drafts a weekly batch (configurable day), pays only settled ("available") earnings, carries forward amounts below the minimum, and nets cash commission owed.

### Dot.Billing and Dot.Finance

Read-only check of both repositories (`~/Dot/Dot.Billing`, `~/Dot/Dot.Finance`, and the current R-44 Dot.Finance clone): Dot.Billing exposes no API for another platform to post revenue or invoices. Dot.Finance's only signed server-to-server inbound endpoints are `/api/ecosystem/membership` (InfoDot) and `/api/integrations/emall/sales` (an Emall-specific contract, `docs/integrations/emall-sales-contract.md`). **There is no Ehail contract, so no integration was built** (D-614). Ehail offers the ledger CSV export (`/money/ops/ledger/export.csv`) for the operator's accountant instead; an Ehail-to-Finance contract would need a Finance-side change first.

### Sources

- Paystack OpenAPI: https://github.com/PaystackOSS/openapi (dist/paystack.yaml)
- Paystack ZA pricing: https://paystack.com/za/pricing ; transaction charges: https://support.paystack.com/hc/en-us/articles/360009881920
- Paystack Pay with Bank (EFT, Capitec Pay): https://support.paystack.com/hc/en-us/articles/360019166720-Pay-with-EFT
- Paystack transfers in SA: https://paystack.com/blog/product/transfers-in-sa ; business types: https://support.paystack.com/hc/en-us/articles/360009972759
- PayFast fees: https://payfast.io/fees
- Commission reporting: https://www.itweb.co.za/article/sa-ride-hailing-becomes-income-lifeline/kYbe97XbwZZqAWpG ; https://topauto.co.za/news/135676/how-much-money-uber-and-bolt-drivers-earn-in-south-africa/ ; https://www.itweb.co.za/article/indrive-allows-drivers-to-keep-99-of-fare-income/DZQ58MV8Dd4vzXy2 ; https://iol.co.za/mercury/2025-07-23-bolt-south-africa-responds-to-kzn-e-hailing-councils-complaints-about-driver-fares/
- Cash risk: https://itweb.africa/article/uber-reviews-cash-payment-option-to-ensure-driver-safety/kYbe97XD9297AWpG ; https://www.iol.co.za/motoring/industry-news/cash-puts-us-at-risk-say-uber-drivers-2044785 ; https://themercury.co.za/2025-10-16-stay-safe-bolt-advises-against-cash-based-trips-outside-the-app/
Each wave-1 agent appends its own section. Keep both sides on merge conflicts.

## Dispatch and matching (dispatch agent, 2026-11-10)

### Method, stated honestly
Desk research only. I had no access to any operator's code, data or internal documents. Three web searches (2026-11-10) checked the claims that matter most for the design: Uber's own "marketplace/matching" page, Uber's acceptance-rate and deactivation pages, press on Trip Radar (TaxiPoint, News24), and the DiDi research papers (KDD 2019/2021/2022, INFORMS Interfaces). Everything else below comes from general public knowledge (operator help pages, driver forums and trade press read before this session) and is marked **(unverified here)** where I did not re-check it. Where only secondary sources exist (system-design blogs), I say so and do not treat them as fact. I ran no experiments on other platforms.

### What the big platforms do
| Platform | Matching, as publicly described | Source quality |
|---|---|---|
| Uber | Started with "closest driver", then moved to ETA ("closest doesn't always mean quickest": rivers, overpasses, traffic), then to **batched matching**: requests and drivers in an area are collected for a few seconds and paired to cut the *average* wait across everyone, so one rider may get a driver two minutes further away so another waits five minutes instead of ten. Uber notes it may not apply in every market and that it avoids pairing people who rated each other one star. | Uber's own matching page; TaxiPoint 2019 |
| Uber | **Trip Radar**: a request not quickly accepted is shown to several nearby drivers at once; first to tap wins. Declines on Trip Radar do not count against acceptance rate; cancellations do. Drivers' unions (ADCU, London) and South African drivers objected: it pushes drivers to look at the phone while driving and to accept without checking the destination. | Uber pages; TaxiPoint; News24 2023-10-20 |
| Uber | Acceptance rate is a 100-request look-back; Uber says low acceptance does not cause permanent deactivation, but it gates Uber Pro rewards and, in some markets, seeing destinations before accepting. Cancellation rate is treated more seriously. Destination and fare shown before accepting were rolled out in the US. | Uber regional pages; secondary press |
| DiDi | Published a sequence of dispatch methods: combinatorial assignment, then value-function / reinforcement learning (semi-Markov decision process) that weighs the long-term value of putting a driver in a given place and time, deployed with A/B tests and later as the primary dispatch mode in one international market. | KDD 2019 (CVNet), KDD 2021 (V1D3), KDD 2022, INFORMS Interfaces |
| Lyft | Similar ETA-based and batched assignment; historically penalised low acceptance through bonus eligibility; offers a limited number of destination-filter uses per day. **(unverified here)** | general public knowledge |
| Bolt | Sequential offers with a short timer; a "destination" filter with limited daily uses; drivers in South Africa have long complained about low fares and about being "punished" with fewer requests after declines. **(unverified here)** | driver forums, press |
| Grab | Batched assignment with "auto-accept" options and acceptance-rate-linked incentives. **(unverified here)** | general public knowledge |
| inDrive | A different model: the passenger proposes a price, nearby drivers counter-offer or accept, the passenger chooses. No central dispatcher decides who gets the ride. **(unverified here; my search did not return inDrive sources)** | general public knowledge |

A system-design blog describes batched matching as an assignment problem (Hungarian algorithm or linear programming over the pending batch). That is the standard technique, but no platform has confirmed it as its exact implementation, so I treat it as illustration only.

### Recurring driver complaints
1. Acceptance-rate pressure: drivers feel forced to take unprofitable or unsafe trips (long pickups, unfamiliar areas at night) to protect bonuses or status.
2. Opaque punishment: drivers believe declines quietly reduce their future requests, with no limit and no explanation.
3. Blind acceptance: being asked to accept without seeing the destination or the earnings. In South Africa this is a safety issue, not just a money one.
4. Fastest-finger broadcast (Trip Radar style) rewards whoever is staring at the phone, including while driving.
5. Unfair distribution: the same drivers seem to get the good trips while others idle.

### What is fair and measurable here (and what this build does)
Dot.Ehail launches small (one market, few drivers), on a shared host with no daemon (D-1, D-2). A batched global optimiser needs volume to beat sequential offers and a process that runs every few seconds; neither exists here yet. So:

- **Sequential offers, one driver at a time, 15 s to answer**, ranked by pickup ETA (a road router when the geo domain provides one within a time budget; otherwise a bounded straight-line estimate that is labelled as such and used for ranking only, never for fares).
- **Fairness terms are explicit, capped and logged**: idle time since the last trip (credit capped at 30 minutes), a trip-balance term that evens out the day around the candidates' median, and a decline cooldown that is a small fixed penalty for five minutes after three misses in ten minutes. A decline or a timeout never removes a driver from dispatch and never lowers anything beyond that cooldown. Every offer stores every term and weight (`ride_offers.score_terms`), so the effect of any weight change can be measured from the data instead of argued about.
- **Before accepting, the driver sees** pickup distance and ETA (with the method), trip distance and duration, the destination address, the fare, the payment method, the passenger's first name and rating band, and the earnings estimate when pricing can produce one (null, never a guess, until then).
- **The open board** (the old claim queue) is kept as a fallback only after a ride has searched for 45 s, configurable and switchable off. It is listed without push or sound, so nobody is invited to tap while driving. The fairness cost is documented in docs/R46_DISPATCH.md.
- **Measurable**: offers sent, seen, accepted, declined (with reason) and expired per driver and per ride; search time to assignment; reassignments; the score terms of every offer. The ops timeline API exposes all of it.

Deliberately not built: acceptance-rate penalties beyond the capped cooldown, hidden throttling, global batching (revisit when demand per zone justifies it; the `CandidateScorer` is the place it would plug in), and reinforcement-learning repositioning.

### Sources
- [Uber: how matching works](https://www.uber.com/us/en/marketplace/matching/)
- [TaxiPoint: batch matching is why riders do not get the nearest vehicle (2019)](https://www.taxi-point.co.uk/post/2019/06/26/batchmatching-revealed-as-the-reason-uber-users-dont-get-paired-with-the-nearest-vehicle)
- [Uber: understanding acceptance and cancellation rates](https://www.uber.com/en-MX/blog/understanding-acceptance-and-cancellation-rates/)
- [Uber: driver deactivation policy (South Africa)](https://www.uber.com/za/en/blog/driver-deactivation-policy/)
- [TaxiPoint: ADCU pickets Uber over Trip Radar](https://www.taxi-point.co.uk/post/adcu-pickets-uber-s-london-hq-in-protest-against-trip-radar-feature)
- [News24: Uber drivers bemoan distracting Trip Radar (2023-10-20)](https://m.news24.com/news24/tech-and-trends/news/taking-trips-blindly-uber-drivers-bemoan-distracting-new-trip-radar-feature-20231020)
- [DiDi: deep value-network dispatch (KDD 2019)](https://mktal.github.io/pdfs/VNetDispatchKDD.pdf)
- [DiDi: V1D3 value-function dispatch and repositioning (KDD 2021)](https://arxiv.org/abs/2105.08791v3)
- [Reinforcement learning in the wild: RL dispatch deployed (KDD 2022)](https://www.aminer.org/pub/6205d3ef5aee126c0ff1d168/reinforcement-learning-in-the-wild-scalable-rl-dispatching-algorithm-deployed-in-ridehailing)
- [INFORMS Interfaces: ride-hailing order dispatching at DiDi via RL](https://pubsonline.informs.org/doi/fpi/10.1287/inte.2020.1047)
