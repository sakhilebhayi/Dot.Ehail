# R-46 passenger app (`/ride`)

Owner: passenger agent (wave 2), branch `feat/r46-passenger`, decisions D-800 to D-816 in `R46_DECISIONS.md`, skills record in `R46_SKILLS.md`. Built inside the ds "Route Line" system (`DESIGN.md`, `<x-eh.*>`, the passenger shell) on the integrated backend (dispatch, pricing, geo, safety, money, comms).

## 1. What a passenger can do

| Step (brief 5A) | Where | Backed by |
|---|---|---|
| Open | `GET /ride` (`ride.home`); a ride in progress opens that ride instead | `BookingController` |
| Confirm pickup | "Use my location" (asked only on a tap; located silently only when the browser already allowed it), address search, tap on the map, saved places; "Move the pin": the map moves under a fixed pin, the address follows (reverse lookup after the map settles) | geo `/geo/search`, `/geo/reverse`, `locateOnce` |
| Enter destination | the same search, saved places, tap on the map | geo |
| See available options | one card per category pricing returns for this route now; categories with no supply listed with pricing's own sentence | `POST /pricing/quotes` (compare) |
| Distance and duration | estimated road distance and driving time above the cards; the route drawn on the map | quote `route`, `/geo/route` |
| Transparent fare | "Fare breakdown" on each card: every component pricing returned (base, distance x rate, time x rate, booking fee, zone or airport fee, tolls note, promotion, rounding, tax note, demand line if any) drawn as a route of stops ending in the yellow total; shown only if the lines add up to the total; "Example tariff" note while the tariff is the example | quote `fare` |
| Quote expiry | "Prices held for 4:32" countdown; at zero the request is disabled and "Refresh prices" re-quotes | quote `expires_at` |
| ETA to pickup | per card: "4 min to you" (road), "About 4 min (straight-line estimate)", "No car of this kind nearby right now", or "Nearby cars unknown until you request" | `POST /ride/api/pickup-etas` (D-803) |
| Promo code | "Add a promo code", re-quotes with the code; pricing's sentence when it does not apply | quote `promo_code` |
| Payment method | saved chargeable cards (default preselected), "Card, paid after the trip" when no card is saved, cash where enabled for the category, business account when available (hidden otherwise); "Add a card" (Paystack card-only checkout, R1 check refunded, returns to `/ride/account`) | `GET /money/wallet`, `POST /money/cards` (D-810) |
| Company rides | business profiles appear in "Pay with" only for people on a business account; choosing one shows "Purpose of the trip" and the company's check for this quote: "Within the company policy", "This ride needs approval" with "Ask for approval", or the refusal with its reasons; the request then bills the account (D-817) | `GET /business/profiles`, `POST /business/trips/prepare`, `POST /business/approvals` |
| Now or later | "Later": date and time within dispatch's lead-time rules, the price-guarantee rule in words | dispatch `scheduled_for` |
| Request | "Request Comfort, R74.00"; one request per tap (Idempotency-Key reused on retry, button disabled in flight); `payment_due` (402) shows the unpaid amount with "Pay R74.00 with Visa ••••" | `POST /api/ehail/rides`, `POST /money/rides/{id}/pay-now` |
| Track the driver | live page `GET /ride/trips/{ride}`: state title and sentence, five-stop progress line, driver first name, photo when verified (initial otherwise), rating band, "Documents verified", car colour, make and model, registration as a white SA number plate, ETA (road or labelled estimate), "Last position 2 min ago" when the position is stale, map with the driver gliding between fixes | dispatch feed, safety `GET /safety/rides/{id}` |
| PIN | the 4-digit PIN in large yellow digits from assignment until the trip starts | feed `ride.pin` |
| Arrived | "Driver waiting for 1:12", free waiting left and the per-minute charge after it, from the ride's rule-set version | `RideOverview` policy |
| Follow the journey | "On the way to Bree Street, arriving in 11 min", share trip, change the destination (re-priced with the reason), safety centre | feed, safety shares, dispatch trip-change |
| Complete | quoted vs final fare with each change and its reason, the final breakdown, payment status from money, receipt link (money's print-friendly receipt) | `GET /pricing/rides/{id}/fare`, `RideOverview` |
| Pay | automatic per money; "Pay now" when a charge failed or the fare is unpaid | money |
| Rate | 1 to 5 stars (real radio buttons), tags, comment (the driver reads comments after moderation; private concerns go to "Report a problem") | `POST /safety/rides/{id}/rating`, `/report` (D-809) |

Cancel at any stage before the trip: the dialog says the cost first ("Free while we look for a driver", "Free for another 1:24, then R25.00", or pricing's sentence for a fee), with dispatch's passenger reasons. Ended rides say what happened ("No driver found. You are not charged.") and offer "Book this route again" (`/ride?again={ride}` prefills both stops, own rides only).

## 2. Other pages

- **Trips** (`ride.trips`): the ride going now, scheduled rides ("Awaiting driver" / "Driver confirmed", booked fare), past trips with status, payment problems, fare and receipt number.
- **Scheduled ride** (same live page): booked time, phase, the guarantee rule, "The price for this ride went up" with "Accept the new price", "Change the pickup time", cancel.
- **Safety** (`ride.safety`): emergency numbers as calls first, the ride going now (SOS, share), trusted contacts (add, waiting for confirmation, resend, remove; the form hides at the limit of 5), pending check-ins, report a safety issue (harassment, accident, other; optionally tied to a recent trip), past reports with their "what we did".
- **What we did** (`ride.safety.incident`): emergency numbers, every action with its real outcome from safety's notification rows ("Sent", "Not sent: you have no confirmed trusted contacts", "Failed"), what was not done, "I am safe now", "Send my location again".
- **SOS** (top bar during an active ride, and on the safety page): numbers first, "Send SOS", a 3-second countdown with "Stop, do not send", then "what we did" (D-813).
- **Are you OK?** (live page, while a driver holds the ride): safety check-ins polled every 20 s; "Yes, I'm OK" or "I need help" (raises an incident, then "what we did").
- **Help** (`ride.support`, `ride.support.create`, `ride.support.show`): cases and their status in words, a new case (category, trip, subject, details, fare the passenger expected for a dispute, optional photo or PDF), the thread with replies and attachments; masking stays the support domain's.
- **Account** (`ride.account`): payment methods (default, remove, add), cash note, saved places (home, work, others; add by search, remove), receipts, links to profile, notifications and notification settings (comms), safety and help. A data-export link appears when an owner registers one (D-808).
- **Fare calculator** (`/fares`, public): tap two points (or use your location; signed-in people can search), every category's estimate with its full breakdown, pricing's sentences when a category has no drivers nearby or the pickup is outside the service area (D-814).

## 3. Code

| Path | What |
|---|---|
| `routes/ehail/passenger.php`, `routes/ehail/passenger-public.php` (required from `public.php`) | routes; limiters in `App\Providers\EhailPassengerServiceProvider` |
| `app/Http/Controllers/Passenger/*` | Booking, Trip (index, show), SafetyCentre, Support, Account, SavedPlace (JSON), PassengerApi (overview, pickup ETAs), FareCalculator |
| `app/Domain/Passenger/*` | `RideOverview`, `PickupEtas`, `RideText` (first-paint words, same as `status.js`), `ScheduleRules`, `Models\SavedPlace` |
| `app/Http/Middleware/Passenger/PassengerContentPolicy.php` | CSP with the tile host (D-806) |
| `resources/views/passenger/**` | pages and partials (`sos`, `check-in`) |
| `resources/js/passenger/*` | entries `booking.js`, `trip.js`, `pages.js`, `fares.js`; pure modules `feed.js`, `money.js`, `breakdown.js`, `countdown.js`, `labels.js`, `status.js`; helpers `api.js`, `dom.js`, `safety.js` |
| `resources/css/passenger.css` | `pax-` styles on `--eh-*` tokens |
| `database/migrations/2026_11_10_810000_create_passenger_saved_places_table.php` | D-801 |

JSON endpoints of this app: `GET /ride/api/places`, `POST /ride/api/places`, `DELETE /ride/api/places/{place}`, `GET /ride/api/rides/{ride}/overview`, `POST /ride/api/pickup-etas` (coordinates in the body). Errors follow the integration's `{error, message}` policy.

Shared files touched (one line each unless stated): `bootstrap/providers.php` (provider), `routes/ehail/public.php` (require), `vite.config.js` (five entries, one per line), `layouts/passenger.blade.php` (comms bell and its CSS, D-815). Tests changed by legitimate behaviour change: `Ehail/Ds/ShellPagesTest`, `Ehail/Ds/NavigationTest`, `Money/CardSetupAndRefundTest` (D-800, D-807).

## 4. Real-time, offline, slow networks

- Feed client (`feed.js`): `If-None-Match: "v<version>"`, waits as the server says (3 s active, 30 s idle), exponential backoff on errors (2 s to 60 s), "Connection lost. Showing the last update; we keep trying. Last update 0:42 ago." after two failures, paused while the tab is hidden and polled at once on return, stops on 401/403/404/419, restarts from the server's truth after the browser's back-forward cache. The page itself is rendered from the same feed on the server, so a reload always shows the current state.
- Offline notices on booking and the live ride follow `navigator.onLine`; the booking sheet keeps choices while offline.
- Size: booking first view about 18 KB gzip of JS before the map, the map chunk (45.6 KB gzip) after first paint; CSS `passenger.css` 3.9 KB gzip. No third-party host except the configured tile server (tested).

## 5. Accessibility

One `h1` per page (the booking page's is visually hidden, "Book a ride"); polite and assertive live regions announce state changes once (assertive for "driver is here", "driver cancelled", "no driver found", "failed"); the state title takes focus on those; sheet steps move focus to their heading; the place search is geo's combobox; the PIN is read as digits; money negatives are read as "minus"; ride categories, stars, payment and tags are native radios, checkboxes or pressed buttons; targets 44 px or more (SOS and check-in answers 64 px); progress bar has `role="progressbar"` with a value; reduced motion turns off the progress and card transitions (the kit already stops the sheet and the map's glide).

## 6. Verification

- PHP: `tests/Feature/Passenger/PassengerPagesJourneyTest` (2 tests: a full ride through the pages on the integrated backend with only provider edges faked; SOS to "what we did") and `PassengerPagesTest` (12 tests: guests, CSP and geolocation headers, one heading, no third-party host, a passenger cannot open another's ride, overview, saved place, incident or help case, scheduled and ended panels, booking redirect, saved place rules, pickup ETA validation and "none", help case preselection, account links and card return, safety centre). Full suite after merging the trunk (with driver, business, fleet, ops, comms): 1308 tests, 1300 passed, 8 skipped, 0 failed; the trunk alone is 1294 (14 new here).
- JS: `tests/js/passenger.test.mjs` 24 tests (feed state machine, ETag, backoff, hidden tab, delta merge, money and typed amounts, breakdown and its sum check, countdowns, waiting and free cancellation, SOS countdown, ETA wording, payment choices including business profiles, business refusal wording, request wording, lead-time rules, status words and announcements). `node --test "tests/js/*.test.mjs"`: 78 pass (54 from the trunk).
- `npm run build`, `php artisan view:cache`, `php artisan route:cache` pass. Impeccable detector clean; taste lint 0 FAIL.
- Browser walkthrough (headless Chrome via Playwright, dev server on 8610 with a local OSRM/Nominatim stand-in and cash on; driver = a verified seeded driver in a second browser context using the real dispatch and geo API): at 390 px light and night and 1440 px light and night: booking (search pickup and destination), compare, open a breakdown, choose Comfort and cash, request; searching; driver accepts; arriving with positions every 3 s; arrived with PIN and waiting clock; PIN start; in trip; share link created and shown; SOS dialog and countdown, stopped; completed with final vs quoted and the change's reason; rating with stars and tags; trips, safety, account, help, new help case; a second ride with SOS sent (what we did) and then cancelled (cost shown first); the public calculator as a guest; "Move the pin" (dragged the map, address followed, confirmed); "Later" with the lead-time bounds; a business traveller (`DemoBusinessSeeder`) choosing the company profile, refused for a missing purpose, then "Within the company policy" with the request enabled. Screenshots: `docs/R46_screens/passenger/<step>-<width>-<light|dark>.jpg`.

## 7. Known limits

- Card payments could not be exercised in the browser (Paystack needs an https endpoint; the dev host has no test keys); the add-card and card-charge paths are covered by PHP tests with Paystack faked (`PassengerPagesJourneyTest`, money's tests). The walkthrough used cash.
- The public calculator shows no price for categories without drivers near the pickup, because pricing's estimate applies supply like a quote (D-814).
- Multiple stops are not offered (not required; dispatch supports one extra stop, pricing bills it).
- The dev SQLite database answered "database is locked" under four concurrent PHP workers with the database cache store (D-412, D-818); the pages fell back honestly (no ETA line, no route drawn, "No prices for this trip" with Try again). With `CACHE_STORE=file` the walkthrough ran clean.
- Booking for another traveller or a guest is in the business portal, not the passenger sheet.
- Simulated drives in the walkthrough are short, so pricing sometimes recalculated the fare down ("The trip was shorter than quoted"); that is pricing's honest rule, shown with its reason.
- Driver photos come from safety's verified photo; seeded dev drivers have no file, so the initial shows.
- In-app share uses the Web Share API where the browser has it; otherwise copy.
