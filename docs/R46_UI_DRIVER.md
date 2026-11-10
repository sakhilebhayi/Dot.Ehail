# R-46 driver app (`/drive`)

Owner: driver agent (wave 2), branch `feat/r46-driver`, decisions D-900 to D-916. Code: `App\Http\Controllers\Driver\*` (with `Support\DriverState`, `Support\TripSummary`, `Support\Local`), `resources/views/driver/**`, the legacy views `resources/views/drivers/{apply,vehicles,vehicle-edit,_vehicle-fields}.blade.php` (now in the driver shell), `resources/js/driver/**`, `resources/css/driver.css`, `routes/ehail/driver.php`, `config/ehail_driver.php`, `App\Providers\EhailDriverServiceProvider` (limiters). Screens: `docs/R46_screens/driver/`.

## 1. Pages

| Route | Name | What it is |
|---|---|---|
| `/drive` | `drive.home` | Not registered: what it takes and Apply. Registered, not approved: the onboarding checklist. Approved: the console (below). |
| `/drive/apply` (legacy URL) | `drive.apply` | Application: licence and ID numbers (stored encrypted), join an approved fleet or drive independently (a one-driver fleet in your name, reviewed by operations), vehicle (make, model, year, colour, plate, class). Then the onboarding checklist. |
| `/drive/onboarding` | `drive.onboarding` | Application, vehicle, documents (counts), review; "You can go online when:" from the safety gate, grouped in the driver's words. |
| `/drive/documents` | `drive.documents` | Every required document for the driver and each vehicle with a chip: Missing, Submitted, In review, Approved, Expiring soon, Rejected or Send again (reviewer's reason and note), Expired, Photo too old. Each needing action has the upload open: Take a photo (`capture="environment"`) or Choose a file; expiry date when the type needs one; type and size checked before sending; progress, Try again after a failed upload; held while driving. Upload goes through `VerificationService::submit` (JSON for script, plain form otherwise). |
| `/drive/vehicles` (legacy URL) | `drivers.vehicles.index` | Vehicles, active vehicle, make active, deactivate, edit, add; link to the vehicle's documents (licence disc, roadworthy, insurance, operating licence, photos). |
| `/drive/earnings` | `drive.earnings` | Day, week, month with previous/next: net in large type, how it adds up (gross fares, commission, platform fees, tax and other deductions as the difference of shown figures, incentives, cancellation fees, adjustments, net), cash collected, trips, net per trip, pending/available/paid; time online only if measured (it is not, and the page says so); every trip and payment row with its status; statement CSV (money's endpoint); incentives with real progress or "No active programmes"; next payout draft date. |
| `/drive/earnings/payouts` | `drive.earnings.payouts` | Balance (available, pending, cash held, next payout estimate, cash commission owed), bank account (masked, verification state; the Paystack check form only when Paystack and account verification are configured, otherwise "operations checks the account"), add or change the account, payout history. |
| `/drive/earnings/payouts/{payout}` | `drive.earnings.payout` | One payout: earnings, cash netted, amount, account, and each earning in it. |
| `/drive/trips`, `/drive/trips/{ride}` | `drive.trips`, `drive.trips.show` | History; one trip: fare agreed vs final with pricing's adjustment reasons, commission, deductions, net, cash; rate the passenger; report the passenger (private); ask support about the trip. |
| `/drive/safety`, `/drive/safety/cases/{case}` | `drive.safety`, `drive.safety.case` | Emergency numbers, SOS on the current trip, documents that need attention (expiry alerts), support cases and replies, safety reports raised, ratings received with appeal. |
| `/drive/account` | `drive.account` | Vehicles, documents, payouts, help, onboarding, notifications inbox and settings (comms), this phone's settings (navigation app, offer sound, vibration), cash trips opt-in (money), profile. |

JSON owned here (`/drive/api`, limiter `ehail-driver-api` 60/min): `GET rides/{ride}/rider` (business rides booked for someone else: the rider's name, guest flag, company and the phone masked as in the business portal, only for the assigned driver while the ride holds the driver, from `App\Domain\Business\RiderDetails`; shown on the trip screen as "Pick up Naledi (guest, Acme), booked by ..., phone •••• 4567"), `GET offers/{offer}/earnings` (pricing's split of the ride's quoted fare for this driver: fare, tolls, deductions with the commission detail, net; `available=false` or `net=null` with the reason when there is no snapshot or plan), `POST rides/{ride}/arrived` (D-902), `GET rides/{ride}/summary` (`TripSummary`). Each answers 404 for anything that is not this driver's.

## 2. The console

State comes only from `GET /api/ehail/driver/feed` (ETag, `Retry-After`: 2 s online, 30 s offline). Views: offline, online, trip, summary, plus the offer card over everything.

- **Offline**: active vehicle with plate; one 96 px "Go online" control, or the grouped reasons with the one fix button (documents, vehicle or checklist) and a disabled control. Going online asks for location first; blocked location is a stop message and the driver stays offline (no offers would reach them anyway).
- **Online**: location state, last check, vehicle; Go offline (96 px), Pause/Resume, Open board (dispatch's fallback list). Stale alert when the app's own heartbeats have not got through for 45 s; background alert after the page was hidden (D-907); wake lock while online.
- **Offer** (full screen, focus on Accept): countdown on the server clock as a burning line plus seconds; "You earn (estimate)" in large type on the yellow stop, breakdown under it (fare, commission with its percentage, tolls paid back, you earn; example-plan note); pickup distance and ETA (labelled straight-line when it is); trip distance and duration; destination area (D-903); category, payment, passenger first name and rating band. Accept 88 px, Decline 56 px with optional reason (too far, destination, safety, busy, other). Chime and vibration per phone setting. Never auto-accepts; expiry closes it with a note.
- **Trip**: route line (pickup, passenger in, drop-off), the next stop in large type, distance (road distance and time from geo when routing is configured, otherwise straight line, labelled), Navigate in the chosen app (Google Maps, Waze, Apple Maps universal links to the next stop only); I've arrived (enabled within tolerance, "I'm here, GPS is wrong" otherwise, D-902); waiting clock with the no-show time and button when policy allows (confirmation dialog); 4-digit PIN keypad (64 px keys, keyboard digits too, wrong PIN clears with dispatch's message, lock disables the pad); Start trip; Complete (confirmation dialog); map on request (lazy Leaflet, pickup, drop-off, driver, road route); trip options: cancel with a reason. SOS in the top bar: 3 seconds to cancel, then safety's incident and its "what we did" list, Dot.Ehail's limits and the emergency numbers.
- **Summary**: final fare vs agreed, commission, deductions, you earn, settlement note; cash: the amount to collect (from money's cash payment), confirm what was received.
- **Minimal-distraction mode** (D-905), **location reporting** (D-906).

## 3. Accessibility and performance

- Targets: primary actions 72 to 96 px, secondary 56 px, keypad 64 px; one h1 per page (checked on every page at 390 and 1440, light and night); state never by colour alone (chips carry words, waiting vs action marks differ in shape); live regions for state, alerts and PIN status; reduced motion stops the waiting line and the countdown transition; night theme by default 18:00 to 06:00 (ds); light theme for sun (ds's measured contrast; driver pages use only ds tokens, no new colour pairs except tokens already measured).
- No horizontal overflow at 390 or 1440 on any driver page (measured in the walkthrough script).
- JS: console entry 12.2 KB gzip with its imports, other pages 4.5 KB, shell 2.4 KB; map 51 KB gzip only when opened (budget 120 KB). CSS: `driver.css` 21 KB (3.9 KB gzip).

## 4. Tests

- PHP: `tests/Feature/Driver/DriverAppPagesTest` (15) and `DriverApiTest` (8): guests, not registered, pending, verified vs unverified console, document chips and reasons, upload as JSON and plain form, earnings and payouts only the driver's own (another driver's payout 404), bank account masked and honest verification without Paystack, trips only own (another's 404), cash opt-in, safety page limits and SOS only on a trip, independent application, legacy vehicles in the driver shell, geolocation policy header; offer earnings from the pricing split (no snapshot, no plan, plan), another driver cannot read or accept the offer, arrival within tolerance, far arrival needs confirmation and is audited, tolerance includes accuracy up to the cap, another driver cannot act on or read the ride, summary with adjustments and split, feed carries only the driver's own offer. Minimal-distraction mode is a UI rule and is not tested as security (the server ignores it by design).
- JS: `tests/js/driver-{countdown,throttle,motion,pin}.test.mjs` (19): server-clock countdown and expiry, location throttling (interval, accuracy, batching, Retry-After, saver), speed-based mode with hysteresis and derived speed, PIN keypad and lock, destination area, money formatting, navigation links, preferences storage failure.
- Suite after merging the trunk with integration, comms, business, fleet and ops: PHP 1294 tests, 1283 passed, 8 skipped, 3 failed; the 3 (FleetPortalTest legacy fleet URL, two ops RolePermissionMatrixTest rows for `ops.business`) fail identically on the trunk itself and touch no driver code (driver tests: 26, all pass). JS 54 passed (driver: 19). Before this branch the merged trunk had 1247 PHP tests (business doc) and 31 JS tests. `view:cache` and `route:cache` pass.

## 5. Browser walkthrough (what was clicked)

Headless Chrome (playwright-core from the installed Playwright CLI, scripts in the session scratchpad), real geolocation emulation moved along the route, a local OSRM-compatible stub (city-grid route), the example tariff, the approximate Cape Town service area, cash trips on, the dev queue run every 3 s instead of the host cron. Driver (127.0.0.1) and passenger (localhost) in separate browser contexts; operator via the HTTP API with its own session (no ops UI on this branch).

1. New user: `/drive` (not registered), Apply, drive independently, vehicle; onboarding checklist.
2. Documents: all 11 uploaded through the page's own picker (progress, reload); operator rejected the insurance with a reason and approved the rest; the console showed "Rejected, upload a new one"; the documents page showed the reason; a `.txt` file was refused before sending; the insurance re-sent and approved; operator approved the fleet and the driver.
3. Account: accepted cash trips. Console: Go online (location granted), online screen.
4. Passenger quoted (economy, R90.00) and requested a cash ride. Driver: the offer showed R69.60 net, R90.00 fare, 20 % commission (example plan noted), pickup 570 m, trip 8.7 km / 17 min, destination area. Accepted; heading to pickup; drove north (minimal-distraction mode switched on); stopped at the pickup; I've arrived went straight through (no audit row); waiting clock and no-show time; wrong PIN refused with dispatch's message; passenger's PIN started the trip; map opened; drove the grid route to Rondebosch; Complete with confirmation; summary waited for settlement, showed R90.00 to collect; confirmed R90.00 received; final fare R90.00 = agreed, commission R17.40, platform fees R3.00, earned R69.60; back to offers.
5. Trip detail, earnings (day, week), payouts. Payout batch visibility: the earning was moved back one day in the dev database (the batch only takes earnings up to the end of yesterday; a time shortcut, nothing else changed), the operator drafted the batch through the API; the driver saw the payout: R69.60 earnings fully netted against the R90.00 cash held, R0.00 sent, R20.40 owed to the platform.
6. Second and third rides: decline with a reason (390 light); accept, SOS cancelled within 3 s, SOS sent ("what we did": incident recorded, context saved, operations alerted, no trusted contacts so nothing sent), driver cancelled with a reason (390 night); accept then passenger cancelled (1440 light).
7. Every driver page at 390 and 1440 in light and night: no overflow, one h1, theme applied.

Not exercised in a browser: real push (comms), Paystack card and bank verification (not configured; the honest unconfigured states were shown), an actual phone in a car (background behaviour is stated, not solved).

Fleet: the go-online gate now includes the fleet's reasons (`vehicle_out_of_service` with the fleet's reason, `vehicle_retired`, `fleet_vehicle_unassigned`); the console and checklist show them as written, with "Only your fleet owner can put this vehicle back on the road or give you another one.", and a refused go-online (403 `vehicle_out_of_service`) shows dispatch's message and then the list (tested).

After the last trunk merge (business, fleet) every driver page was opened again at 390 px: all 200, no overflow, one h1, comms bell present; go online and offline worked against the integrated backend.

## 6. Known limits and notes for other owners

- A web app cannot receive offers in the background or with the screen off; stated on screen (D-907). A native wrapper or Web Push for offers would be an owner decision (comms sends none for offers by design).
- Money: statement days run in UTC (D-913).
- Business: the rider's full phone number is not shown to the driver (masked, per the business portal policy); if drivers need to call guests, a masked-call or relay service is an owner decision.
- Ops: no ops UI was on this branch, so approvals in the walkthrough used the safety and money ops APIs.
- `DriverActivitySource` measures no online time, so earnings never show it.
- The vehicle "Change" link goes to the vehicles page (activation there); there is no in-console vehicle picker because dispatch uses the one active vehicle.
