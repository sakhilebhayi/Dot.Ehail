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
## Pricing

### Method

Web search and page fetches on 2026-11-10, done by a research sub-agent for the pricing agent and checked against the cited sources' summaries (not re-fetched). Sources were company help pages (Uber, Bolt, inDrive, DiDi, Lyft), a SARS practice note, government statements (gov.za, SAnews), one academic paper, and South African and international news. Several pages could not be fetched (403 errors: SAIT, IOL/Mercury, Oxford news page, Computer Weekly), so those points rest on search-result snippets and are marked "as reported". Help pages vary by city and change often, and most of the ones I found are for markets other than South Africa. I could not find official South African cancellation or waiting-fee rules for Uber or Bolt, any current ACSA e-hailing fee schedule, or a published Bolt rule on capping or showing surge. Numbers in square brackets point to the source list.

### 1. How the major platforms price rides

**Uber.** Where Uber offers upfront pricing, the rider sees an exact fare before requesting. It is built from a base rate, estimated time and distance, and busy-period adjustments, and it includes the booking fee, surcharges and tolls [1]. Since the move to upfront fares, surge is folded into the price and is not shown as a separate multiplier [2]. The final fare can differ from the quote if the rider changes destination, adds stops, or the trip takes much longer than expected. The receipt explains the difference [1]. In the UK, a rider who changes a stop mid-trip has to confirm a new price in the app [3]. In some US locations, wait-time fees start 2 minutes after the driver arrives, or 5 minutes for Uber Black [4]. In the US, economy rides can be charged a cancellation fee 2 or more minutes after the request, and premium rides after 5 minutes. The fee is waived if the driver runs 5 minutes past the original ETA or is not moving toward the rider [5][3]. Under a 2014 agreement with New York's attorney general, Uber caps surge during declared emergencies. I found nothing confirming the current form of that policy [6]. In South Africa, a rider at Lanseria was charged R30 in "airport" surcharges on a R179 quote in 2023, which Uber called an error and refunded [7]. The minimum fare most recently confirmed for UberX Johannesburg is R25, from 2018 [8]. I found no current figure.

**Bolt.** Bolt South Africa adds a 4% booking fee to every trip. On cash trips Bolt takes the 4% out of the driver's weekly earnings [9]. Bolt's general help page says a rider who cancels within 2 minutes of acceptance is not charged, though the window varies by city [10]. Paid waiting time starts "a few minutes" after the driver arrives [11]. Bolt describes its prices as dynamic and based on demand prediction [12]. In some markets it runs "Flex", where the rider lowers or raises the offer [13]. I did not find how, or whether, Bolt South Africa shows or caps surge.

**inDrive.** The passenger proposes the fare, starting from a recommended minimum. Drivers accept or counter, and the passenger picks an offer. The fare cannot be changed during the ride, and drivers may not ask for more after accepting. Tolls and airport fees are not included in the fare and are paid by the passenger [14].

**DiDi (Australia).** During surge, DiDi shows the multiplier on the base, time, waiting and distance components in the fare estimate and on the receipt. The waiting fee starts after 5 minutes at A$0.55/min. A cancellation fee can apply once the driver has waited 3 minutes [15]. DiDi left South Africa in April 2022 [16].

**Lyft.** Lyft added per-minute wait fees in 2023, starting after 2 minutes for standard rides [17]. Lyft has been reported as changing prices only when a route runs "significantly longer" than expected [18]. I did not verify Lyft's current cancellation window.

**Grab.** In Singapore, Grab added a S$0.30 per-ride platform fee paid by passengers in December 2020 [19]. In the Philippines, the regulator's fare matrix caps surge at 2x. A 2024 PCIJ investigation found that a surge component was present on every sampled ride [20].

### 2. Commission and take rates (as publicly reported)

- **Uber South Africa:** a 25% standard service fee (2022) [21]. Since 29 May 2023, South African drivers have seen the destination and a net amount before accepting. Uber's own page says the per-trip service fee "will fluctuate from 3% to 35%" [22]. A driver association says promotions push the total deducted above 40%. That is an advocacy figure [23].
- **Uber, UK and global:** a 2025 study of 1.5 million trips by 258 UK drivers found Uber's take rose after dynamic pricing [24]. Reports of the study give a median of 25% rising to 29%, with some trips above 50%. Uber disputed this and said its take is "steady" at 25% [25]. According to the paper, Uber began showing drivers a weekly average take rate in December 2024, with no breakdown per trip [25]. One US analyst, working from a single driver's trips, estimated about 42% by late 2024. That sample is small [26].
- **Bolt South Africa:** reported figures disagree. One 2022 report gives 23% plus the 4% booking fee [27], a 2025 report gives 25% [28], and drivers in 2024 cited 25–43% across Uber and Bolt [29]. A KZN drivers' council accused Bolt of raising its commission in 2025 [30].
- **inDrive:** says its commission "never exceeds 10.95%" globally, including SA. It ran a 1% promotion in Johannesburg and Pretoria from 1 November to 31 December 2025 [28].
- **DiDi South Africa (2021–22):** 0% for the first four weeks, then 13% [31].
- **Lyft:** since 2024, Lyft has committed that drivers earn at least 70% of rider fares each week after external fees, and it tops up any shortfall. Lyft says it extended the commitment US-wide in May 2024 [32]. Drivers see estimated earnings before they accept [33].
- **Grab Singapore:** 20.18% including GST, from January 2023 [34].
- **Regulatory comparison:** Kenya capped commission at 18% in 2022, and Uber Kenya cut its fee from 25% to 18% [35].

### 3. South African specifics

- **Currency:** all fares are in ZAR.
- **E-tolls:** the Gauteng e-toll gantries were switched off at midnight on 11 April 2024 [36][37]. Plaza tolls remain on national routes. SANRAL raised tariffs 3.12% from 1 March 2026 [38].
- **Airports:** e-hailing pickups at OR Tambo moved to Parkade 2, Level 2 in 2022 [39]. ACSA has been reported as proposing US$1.60 per pickup and US$0.80 per drop-off for e-hailing [40]. I could not confirm whether these fees were implemented or what they cost now.
- **VAT:** the rate is 15%. A planned rise to 15.5% was withdrawn in April 2025 [41]. Section 12(g) of the VAT Act exempts transport of fare-paying passengers by road, provided by the operator of the vehicle "in the course of a transport business". SARS Practice Note 7 (1992) says the operator must be "commercially responsible" for the transport and need not own the vehicle [42]. Uber's South African tax page treats the driver's transport supply as exempt and charges 15% VAT on its own service and booking fees [43]. SAIT has been reported as arguing that Uber itself supplies the transport as an exempt supply [44]. SARS's June 2026 ruling on ambulances reads section 12(g) narrowly [45]. Which of these treatments would apply to Dot.Ehail is a question for the owner's accountant, and this section does not answer it. It depends on who supplies the transport, whether a separate booking or service fee is charged, and VAT registration.
- **Regulation:** the National Land Transport Amendment Act was signed in June 2024 [46]. It was gazetted with its regulations on 12 September 2025. Under it, drivers need operating licences, and an app that allows unlicensed operators faces fines of up to R100,000 or two years' imprisonment [47]. I found no fare-setting or fare-display rule in the government statement.

### 4. What makes pricing feel fair

In experiments, consumers rate surge pricing as less fair, but explaining the increase in proportion to its size improves that perception [48]. Other research found that more process transparency can also raise new questions [49]. India's competition regulator found that riders accept that surge exists, but the surge amount is not shown separately [50]. On the driver side, complaints in both the UK and South Africa focus on not being able to check the take on each trip [23][25]. Lyft's answer was a weekly floor plus receipts showing the split [32]. Practices that appear in the sources: refunds for wrong surcharges [7], waivers when the driver is late [5], a new price that the rider confirms after a mid-trip change [3], a multiplier shown on the receipt [15], and no renegotiation after acceptance [14].

### How the build applied this (pricing agent)

Upfront quotes with every line itemised, re-pricing only for passenger changes or large overruns (capped), demand pricing off and always shown as its own line when on, no airport fee configured, VAT left to the accountant (tax mode none in the example), the driver's per-trip split shown before accepting and after completion, a late-driver cancellation waiver, and a 20 % example commission. See docs/R46_PRICING.md. Not built: a mid-trip "confirm the new price" step (passenger and driver apps, wave 2; the finaliser already labels a passenger destination change) and a weekly earnings floor (an owner decision).

### Implications for Dot.Ehail

- Quote upfront in ZAR, with the booking fee and known tolls included and itemised. Re-price only when the trip changes (destination, stops, or a much longer trip), and have the rider confirm the new price in the app.
- Show any demand adjustment as a visible multiplier or amount, on the quote and the receipt, as DiDi does, and decide on a published cap.
- Before the driver accepts, show the rider fare, the platform fee and the net earnings for that trip. A variable fee kept out of view, such as Uber's 3–35%, is the main source of driver complaints.
- Publish a fixed commission or a weekly floor, Lyft-style, and keep it below the reported 25% SA norm. inDrive's ceiling of about 11% is the competitive reference.
- Publish the free-cancellation window (about 2 minutes is common), the waiting grace period, and automatic waivers when the driver is late or not moving.
- Do not add airport surcharges until there is a documented ACSA or airport charge to pass through. Then show it as a separate line.
- Have the owner's accountant settle VAT treatment of the fare and the platform fee under section 12(g) before launch.
- Block onboarding of unlicensed operators, given the penalties in the amended Act.

### Sources

1. Uber Help, How are fares calculated: https://help.uber.com/riders/article/how-are-fares-calculated?nodeId=d2d43bbc-f4bb-4882-b8bb-4bd8acf03a9d
2. Uber India newsroom, Upfront fares: https://www.uber.com/en-IN/newsroom/upfrontfare
3. Uber UK blog, 21 Oct 2025: https://www.uber.com/gb/en/blog/understanding-your-upfront-fare-when-it-can-change-and-what-extra-fees-may-apply/
4. Uber Help, Wait time fees: https://help.uber.com/h/22b6db22-0f86-4765-ac72-da549a2302d7
5. Uber Help, Cancellation fees explained: https://help.uber.com/en/riders/article/cancellation-fees-explained?nodeId=9dcdbc61-d270-417d-8d43-677ae9c9a30c
6. TechCrunch, 8 Jul 2014: https://techcrunch.com/2014/07/08/uber-caps-surge-pricing-during-emergencies
7. TopAuto, 19 Sep 2023: https://topauto.co.za/features/87135/uber-breaks-silence-on-secret-airport-fees-in-south-africa/
8. Stuff, 30 Jul 2018: https://stuff.co.za/2018/07/30/uber-is-increasing-its-minimum-fare-for-uberx-in-johannesburg-to-r25/?amp=1
9. Bolt SA, Booking fees: https://bolt.eu/en-za/support/articles/360004204273/
10. Bolt, Issue with a cancellation fee: https://bolt.eu/en/support/articles/360009457274/
11. Bolt, Paid wait time fees: https://bolt.eu/en/support/articles/360009458314/
12. Bolt Labs, Fair Pricing for Reliable Rides: https://medium.com/bolt-labs/fair-pricing-for-reliable-rides-6a6442a267b0
13. TaxiPoint, Bolt Flex: https://www.taxi-point.co.uk/post/what-is-bolt-flex-operator-lets-passengers-haggle-over-fares-as-flex-pricing-rolled-out-across-29-a
14. inDrive Help, How fares are calculated: https://indrive.com/help/passengers/how-fares-are-calculated
15. DiDi Australia Help Center: https://web.didiglobal.com/au/rider/help ; https://web.didiglobal.com/au/help-center/waiting-fee ; https://web.didiglobal.com/au/help-center/how-does-the-cancellation-fee-work
16. WeeTracker, 14 Apr 2022: https://weetracker.com/2022/04/14/didi-south-africa-shutdown/
17. Engadget, 2023: https://www.engadget.com/lyft-adds-wait-times-fees-204523564.html
18. Quartz: https://qz.com/1488150/what-happens-if-your-uber-driver-takes-a-different-route-than-promised
19. Malay Mail, 11 Dec 2020: https://www.malaymail.com/news/singapore/2020/12/11/singapore-grab-to-charge-30-cent-platform-fee-for-most-rides-from-dec-18/1931050
20. PCIJ, 12 Jul 2024: https://pcij.org/2024/07/12/methodology-pcij-investigation-grab-surge-pricing-model/
21. News24, 25 May 2022: https://www.news24.com/business/companies/uber-unlikely-to-ditch-25-driver-commission-20220525
22. Uber ZA blog, 29 May 2023: https://www.uber.com/en-ZA/blog/see-your-destination-before-accepting-rejecting-a-ride
23. Daily Maverick, 3 Dec 2024: https://www.dailymaverick.co.za/article/2024-12-03-balancing-act-ubers-claims-of-economic-growth-clash-with-drivers-struggles-for-stability
24. Binns et al., arXiv 2506.15278, Jun 2025: https://arxiv.org/abs/2506.15278
25. University of Oxford news, 23 Jun 2025 (as reported; page not fetched): https://www.ox.ac.uk/news/2025-06-23-new-oxford-research-reveals-uber-s-algorithmic-pricing-leaves-drivers-and-passengers ; TaxiPoint: https://www.taxi-point.co.uk/post/uber-rejects-university-of-oxford-study-that-finds-drivers-see-drop-in-pay-under-dynamic-pricing
26. Len Sherman: https://len-sherman.medium.com/ubers-long-and-winding-road-to-50-take-rates-1aaf4a574ae0
27. IOL, 4 Nov 2022: https://iol.co.za/news/south-africa/gauteng/2022-11-04-uber-and-bolt-sa-respond-as-kenya-cuts-commission-to-18-for-drivers/
28. ITWeb, 12 Nov 2025: https://www.itweb.co.za/article/indrive-allows-drivers-to-keep-99-of-fare-income/DZQ58MV8Dd4vzXy2
29. WageIndicator, Aug 2024: https://wageindicator.org/what-we-do/news-stories/gig-news/2024/august-2024-south-africa-uber-and-bolt-drivers-boycott-rides-in-western-cape/
30. The Mercury, 23 Jul 2025 (as reported; page not fetched): https://themercury.co.za/2025-07-23-bolt-south-africa-responds-to-kzn-e-hailing-councils-complaints-about-driver-fares/
31. Business Day, 26 Sep 2021: https://www.businesslive.co.za/bt/opinion/2021-09-26-arthur-goldstuck-didi-ride-hailing-making-inroads-in-sa-market/
32. Lyft blog, 14 May 2024: https://www.lyft.com/blog/posts/lyft-expands-driver-earnings-commitment-nationwide
33. Lyft blog, Upfront pay: https://www.lyft.com/blog/posts/upfront-pay-and-the-next-chapter-of-the-lyft-driving-experience
34. Vulcan Post, 28 Dec 2022: https://vulcanpost.com/812440/grab-raise-driver-commission-fees-help-or-harm-them/
35. TechCrunch, 4 Nov 2022: https://techcrunch.com/2022/11/04/uber-withdraws-petition-to-annul-new-ride-hailing-regulations-in-kenya ; Citizen Digital: https://citizen.digital/tech/uber-adjusts-service-fee-from-25-to-18-in-kenya-after-operators-strike-n308427
36. SAnews, E-tolls officially scrapped: https://www.sanews.gov.za/south-africa/etolls-officially-scraped
37. EWN, 28 Mar 2024: https://ewn.co.za/2024/03/28/transport-dept-officially-withdraws-e-toll-declaration-on-some-gauteng-roads
38. Engineering News, 19 Feb 2026: https://www.engineeringnews.co.za/article/sanral-advises-of-312-toll-tariff-increase-2026-02-19
39. IOL, OR Tambo relocation of public transport: https://www.iol.co.za/news/south-africa/gauteng/or-tambo-international-announces-relocation-of-public-transport-4343128d-af3c-49cf-9cab-1fb320298bea
40. Parking Today: https://parkingtoday.com/article/e-hailing-and-covid-19-depress-parking-revenues-for-south-africa-airports/
41. SARS media release, Apr 2025: https://www.sars.gov.za/latest-news/media-release-sars-welcomes-court-order-relating-to-the-vat-rate-originally-announced-to-come-into-effect-on-1-may-2025/ ; Treasury: https://www.gov.za/news/media-statements/treasury-reversal-vat-increase-24-apr-2025
42. SARS VAT Practice Note 7, 10 Feb 1992: https://www.sars.gov.za/lapd-intr-prn-2012-30-vat-practice-note-7-of-1991
43. Uber ZA, Tax information: https://www.uber.com/za/en/drive/tax-information/
44. SAIT FAQ (as reported; page returned 403): https://thesait.org.za/?p=58041
45. SARS BGR 75: https://www.sars.gov.za/legal-intr-r-bgr-75-value-added-tax-treatment-of-ambulance-services/
46. News24, 20 Jun 2024: https://www.news24.com/fin24/companies/bolt-uber-praise-new-e-hailing-law-but-drivers-remain-sceptical-about-taxi-relations-20240620
47. gov.za, 12 Sep 2025: https://www.gov.za/news/media-statements/transport-gazettes-national-land-transport-amendment-act-and-regulations-e
48. J. Global Scholars of Marketing Science 33(4), 2023: https://ideas.repec.org/a/taf/jgsmks/v33y2023i4p626-645.html
49. eScholarship dissertation: https://escholarship.org/uc/item/8qz7g89x
50. MediaNama, CCI market study, Nov 2022: https://www.medianama.com/2022/11/223-summary-cci-market-study-cab-aggregators-ola-uber/
Part 1 (UX and product study) is by the ds agent. Other agents append their sections below it: pricing, dispatch, safety, payments.

## Part 1: UX and product study (ds, 2026-11-10)

### Method, stated honestly

This is a desk study from public product knowledge: how the apps work as publicly documented and widely reported up to mid-2026, their help centres and published policies, recurring themes in app-store reviews and driver communities (Reddit, Facebook driver groups, South African news coverage of e-hailing strikes and regulation), and industry reporting. I had no device access, did not install or run any of the apps, did not scrape stores, and did not interview users. Nothing here is a measured statistic; where a claim is a recurring complaint pattern rather than a documented fact, it says so. Before any of this informs marketing copy, a human should check it against current app versions.

Products considered: Uber, Bolt, inDrive, DiDi (DiDi Rider operated in South Africa until its 2023 exit), Lyft, Grab, and regional players: Yango (active in several African markets), Little (Kenya), SafeBoda (Uganda, Nigeria history), and South African metered-taxi apps and dispatchers (the "metered taxi" segment that e-hailing displaced in Johannesburg, Pretoria, Cape Town and Durban).

### What the leaders do well (and Dot.Ehail must match)

| Pattern | Who does it best | What it means for Dot.Ehail |
|---|---|---|
| Map-first booking: pickup pin, destination search, category comparison in a bottom sheet | Uber, Bolt, Grab | The passenger shell is built around a map frame and a bottom sheet with snap points (`<x-eh.map-frame>`, `<x-eh.sheet>`). |
| Upfront price before request | Uber (upfront pricing), Bolt, DiDi | Table stakes. Dot.Ehail goes further: the full breakdown, not just the total. |
| Driver details before pickup: name, photo, car, plate, rating | everyone | Shown in the trip card; the plate gets the largest type because that is what a passenger checks at the kerb. |
| Ride PIN at pickup | Uber (optional PIN), Bolt in some markets, Grab | Architecture makes the 4-digit PIN the default; the stepper and safety copy carry it. |
| Share trip status | Uber, Bolt, Lyft | Trip share link (safety agent). |
| In-app emergency button | Uber (safety toolkit), Bolt (SOS), Grab | SOS with an honest statement of what it does (architecture section 7). |
| Lightweight apps for low-end phones | Uber Lite (retired 2022), Bolt Lite, Grab's low-data modes | The PWA is the "lite" app: small JS (shell 2.4 KB gzip), self-hosted fonts, no third-party runtime hosts, shell-only caching. |
| Cash payments | Bolt, inDrive, Uber in SA | Cash is a first-class payment method (money agent). |
| Driver destination filter, heat maps, earnings view | Uber, Bolt | Driver shell has Earnings as a first-level destination. |

### Recurring complaints (patterns, not measured rates)

**Passengers**
1. **Surprise fares.** Price on arrival differs from the quote (route changes, waiting, tolls, surge applied after a delay). Complaints concentrate on not knowing *why* the fare changed. Dot.Ehail answer: a fare never changes without a reason code shown on the receipt (architecture section 4); the landing page makes this its first promise.
2. **Surge opacity.** Multipliers shown as a number without a cap or explanation, or folded into the total. Answer: the busy-time multiplier is a named, capped line in the breakdown.
3. **Cancellations and long waits.** Drivers accept then cancel (often after seeing the destination or because the pickup is far for the pay), or the "searching" state runs on. Answer: drivers see trip and earnings before accepting (less reason to cancel after), searching has a visible window and an honest "no driver found" end state (`expired`).
4. **Safety at night and in unfamiliar areas.** Fake or swapped drivers, wrong car, harassment, and in South Africa specifically, crime risk at pickup points and fear of hijacking or robbery. Answer: plate and photo first, PIN to start, live share, SOS that alerts operations and trusted contacts, emergency numbers one tap away, and copy that never claims an armed response the product does not have.
5. **Support dead ends.** Chatbots and canned replies, no human for serious incidents, refunds hard to get. Answer: support cases with a human queue (safety agent), and the ops shell has Support as a first-level destination.
6. **Data and battery cost.** On prepaid data, large apps and map tiles hurt; low-storage phones uninstall big apps. Answer: PWA, no app-store install needed, tiny shell, map tiles only when the map is on screen (geo agent).
7. **Pickup confusion.** Pins land on the wrong side of a road or inside an estate; gated complexes and malls have specific pickup points. Answer: map-frame and sheet patterns support a "confirm pickup" step; zones (geo) can carry named pickup points.

**Drivers**
1. **Pay opacity and commission.** Drivers report not knowing the commission per trip, or that the commission percentage effectively rose. South African driver protests (2021 to 2024) repeatedly cited commission rates and per-km rates that do not cover fuel and vehicle costs. Answer: earnings statement lines (fare, commission, deductions, net) and the offer card shows earnings before accepting.
2. **Destination hidden until pickup.** Leads to cancellations and unsafe areas. Answer: the trip is shown on the offer.
3. **Deactivation without explanation.** Accounts blocked by automated systems with no appeal. Answer: verification reviews with reasons and resubmission (safety agent); ops shell has Verification and Drivers destinations.
4. **Safety of drivers.** Robberies of drivers by passengers posing as riders, cash trips in particular. Answer: the safety banner and SOS exist in the driver shell too; passenger verification and ratings are two-sided.
5. **Fleet owner and renter disputes.** In South Africa many drivers rent cars from fleet owners ("partners"); disputes over targets and payouts are common. Answer: a fleet shell with drivers, vehicles, documents and earnings per car.
6. **App distraction while driving.** Small targets, lots of text, notifications. Answer: the driver shell uses 56px primary actions, 18px body, a single state bar, and hands navigation to the phone's maps app.
7. **Regulation.** Operating licences under the National Land Transport Act amendments (2023/2024) and municipal enforcement (impounds). Answer: documents and expiry tracking; the design system has a licence-disc icon and a document status vocabulary ready for it.

### Regional notes

- **inDrive** lets passengers propose a fare and drivers counter. It wins on price-sensitivity and loses on trust and speed. Dot.Ehail keeps server-authoritative fares (architecture section 4) but shares inDrive's lesson that people want agency over price: show the parts and let them choose the category.
- **Yango** emphasises in-app safety features and fixed pricing in several African cities.
- **Little (Kenya)** built corporate accounts and M-Pesa integration early: business accounts and local payment rails matter in African markets.
- **SafeBoda** built community trust through branded, trained riders and a visible code of conduct: trust is designed, not assumed.
- **Metered taxis in SA** lost to e-hailing on price visibility and convenience, not on safety; the meter was opaque. Dot.Ehail's breakdown is the opposite of the meter.

### Where Dot.Ehail can be better (and what the shells already carry)

1. **The fare as a readable formula, before and after the trip.** Landing signature (the fare line), money component (minor units, tabular figures), status vocabulary with reason codes.
2. **Honest states everywhere.** Empty states that say what is not built yet; SOS copy that says what it does not do; navigation that hides destinations whose routes do not exist (`App\Support\Ui\Navigation`).
3. **Safety in the frame.** A safety control in every passenger and driver top bar; emergency numbers as one-tap calls that work without data.
4. **Light on the phone.** No third-party hosts, self-hosted fonts, 2.4 KB shell script, shell-only service worker, dark night mode for drivers.
5. **Driver respect.** Earnings before accept, statement lines, large targets, night theme by default after 18:00.
6. **Local identity.** Sign green and road-marking yellow from South African roads, ZAR formatting ("R1 250.50"), South African emergency numbers, photographs of Johannesburg and Cape Town streets.

### Open questions for the owner (logged, not blocking)

- Languages: English only for now; isiZulu, Afrikaans, isiXhosa and Sesotho copy would widen reach (i18n keys are not yet extracted).
- Whether to offer a passenger-proposed fare mode (inDrive style); the architecture currently says no.
- Armed-response or medical partner integration for SOS (architecture section 7: an owner decision).
