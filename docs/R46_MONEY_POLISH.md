# R-46 polish: money, time and labels (pmoney)

Branch `feat/r46-pmoney`, 2026-11-10. Decisions D-1900 to D-1915 in `R46_DECISIONS.md`. This file is the record of
what changed in payloads and contracts, so the passenger, driver and ops surfaces can read them.

## 1. What was fixed (design jury items 1, 2, 5, 14, 15, 16)

| Jury item | Fix | Proof |
|---|---|---|
| 1 Reconciliation | Metered lines bill the quantity they print; the earnings split lists every step from fare to net and names the commission base; the receipt states billed distance and time | `tests/Feature/Pricing/FareReconciliationTest.php`, `tests/Feature/Driver/OfferEarningsWalkTest.php`, `tests/Feature/Money/OperatorIdentityTest.php` |
| 2 Quoting without supply | Calculator, booking sheet and scheduled rides price with nobody online; only a request for now is refused | `tests/Feature/Acceptance/QuotingWithoutSupplyTest.php` |
| 5 Timezone | One display helper, SAST by default, browser told the zone by the page | `tests/Feature/Polish/DataLabelsTest.php`, `tests/js/eh-time.test.mjs`, `OperatorIdentityTest` |
| 14 Money format | One money text, PHP and JS | `tests/Feature/Polish/OneMoneyFormatTest.php`, `tests/js/eh-time.test.mjs` |
| 15 Operator identity | From configuration only; preflight lists what is missing; no no-reply support contact; precise VAT sentence | `OperatorIdentityTest`, `tests/Feature/Business/OverviewSpendAndStatementIdentityTest.php` |
| 16 Data and labels | Badge counts, "New on Dot.Ehail", "Approved, documents missing", business spend, "applying", trips order, names not ids, insights wording | `DataLabelsTest`, `QuotingWithoutSupplyTest`, `OverviewSpendAndStatementIdentityTest` |

## 2. Payload and contract changes

### Pricing

- Fare components (quotes, estimates, receipts): metered lines (`distance`, `time`) now also carry `quantity`
  (string, one decimal), `quantity_tenths` (int), `unit` (`km` or `min`) and `rate_minor`. `amount_minor` equals
  `rate_minor x quantity_tenths / 10` rounded to the cent. `detail` reads "5.7 km x R7.50/km". Existing keys are unchanged.
- Priced options (route `pricing.quotes.store`, the compare list): new keys `supply` (`none`, `available`, `unknown`),
  `drivers_nearby` (int), `requestable_now` (bool), `supply_note` (sentence, ends "A driver is not guaranteed." when
  nobody is nearby). An option is priced whatever the supply.
- Requesting a ride for now with no eligible driver nearby: HTTP 409 with `error: "no_supply"`. A request with
  `scheduled_for` is not gated by supply.
- The public calculator (route `pricing.estimate`) and the booking sheet return identical components and totals for
  the same trip.

### Driver

- Route `drive.api.offer-earnings`: new `lines` (list of `{code, label, amount_minor, detail}`, signed, in
  reading order) and `commission_base` (words, for example "R61.00 (fare before fees)"). Invariant:
  `fare_minor + sum(lines.amount_minor) = net_minor`. Line codes: `not_earned:<component code>`,
  `platform_funded:<component code>`, `commissionable_floor`, `commission`. The trunk's `kept_by_platform`,
  `commission_rule`, `commissionable_minor` and `deductions` are still sent; the offer card prefers `lines`.

### Passenger

- Ride feed `ride.driver.rating` is now `{band, label, count}` (for example
  `{"band":"new","label":"New on Dot.Ehail","count":0}`, `{"band":"excellent","label":"Excellent","count":5}`).
  No decimal average is sent.
- Past trips are ordered by the time shown, newest first.

### Money documents

- Receipt snapshot: new `operator` (the whole configured identity: `legal_name`, `trading_name`, `brand`,
  `registration_number`, `address_lines`, `vat_status`, `vat_number`, `vat_rate_percent`, `support_email`,
  `support_phone`, `support_url`, `eft`),
  `vat_sentence` (string or null), `billed` (`{distance, time}`, each a string like "5.7 km" or null), and
  `fare_basis`. `operator_name` and `vat_number` remain for older readers.
- Business statement snapshot: `operator` now carries the whole configured identity (plus `name` and `vat_number`
  as before) and `vat_sentence`. EFT details are printed only when all of them are configured.
- CSV exports: the business statement's first column header is `Date (SAST)`; the fleet earnings export's is
  `earned_at_sast`. Values are local wall times.

### Notifications

- `Inbox::countFor($user)` counts each ride once however many unread progress notifications it has, plus every
  unread notification not tied to a ride. `Inbox::badge($count)` gives the text ("9+" above nine). The bell's
  accessible name keeps the real number ("Notifications, 12 unread").
- Opening a ride page marks that ride's progress notifications seen (`Inbox::markRideSeen`).

### Time

- Server: `App\Support\Ui\LocalTime` (`timezone()`, `at()`, `format()`, `time()`, `label()`).
- Browser: `resources/js/eh/time.js` (`timeLocal`, `formatLocal`, `wallTimeToIso`, `offsetMinutes`,
  `displayTimezone`). The zone comes from `<meta name="eh-tz" content="Africa/Johannesburg">` in the shared head
  (`layouts/partials/eh-head.blade.php`), or `<html data-eh-tz>`.
- `BusinessClock::timezone()`, the driver pages' `Local::at()` and the notification templates take their zone from
  `LocalTime`; an unknown or empty setting falls back to the platform zone.

### Operations

- Timeline words (`TimelineWords`) and the incident timeline name people through `App\Support\Ui\PersonNames`.
- Driver list and page: badge text "Approved, documents missing" when a required document is not approved and current.
- Fleet overview receives `applicationsCount`.
- Business overview `spent` uses the trip's spend month (same basis as department budgets).

## 3. Configuration (all optional; unset values are left off documents and listed by `ehail:preflight`)

`EHAIL_OPERATOR_LEGAL_NAME`, `EHAIL_OPERATOR_TRADING_NAME`, `EHAIL_OPERATOR_REGISTRATION`, `EHAIL_OPERATOR_ADDRESS`
(lines separated with `|`), `EHAIL_VAT_REGISTERED` (state true or false; unset means no VAT statement is made),
`EHAIL_VAT_NUMBER`, `EHAIL_SUPPORT_EMAIL` (a real inbox; a no-reply address is rejected), `EHAIL_SUPPORT_PHONE`,
`EHAIL_SUPPORT_URL`, `EHAIL_EFT_ACCOUNT_NAME`, `EHAIL_EFT_BANK`, `EHAIL_EFT_ACCOUNT_NUMBER`, `EHAIL_EFT_BRANCH_CODE`,
`EHAIL_EFT_ACCOUNT_TYPE`.

Owner step before launch: set these in the production environment. Until then receipts and statements carry the
application name only, no registration, address or VAT line, and the in-app help case as the support route.

## 4. Not done, and limits

- Receipts and statements issued before this change keep their frozen snapshots (a name only); they are not rewritten.
- The audit and access tables still print the numeric id as a small reference beside the name; that is deliberate.
- Checked by tests and `view:cache`, not by a fresh browser pass after the trunk merge.
