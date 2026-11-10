# R-46 Business accounts (brief section 14)

Owner: business agent, branch `feat/r46-business`, decisions D-1100 to D-1119. Code: `App\Domain\Business\*`, `App\Http\Controllers\Business\*`, `routes/ehail/business.php`, `App\Providers\EhailBusinessServiceProvider`, `config/ehail_business.php`, migration `2026_11_10_711000_create_business_account_tables`, views `resources/views/business/**`, `resources/css/business/portal.css`, `resources/js/business/book.js`, dev-only `database/seeders/DemoBusinessSeeder.php`.

## 1. What a business account is

| Piece | Where | Notes |
|---|---|---|
| Account | `business_accounts` | legal name, registration number, VAT number (optional), billing email, address, country and currency (ZA/ZAR), status `pending_review` / `active` / `suspended` / `declined` / `closed`, billing mode `invoice` or `card`, credit limit, terms days, grace days, optional link to a Jetstream team (an InfoDot company: the team carries `infodot_team_uuid`) |
| People | `business_members` | roles `account_admin`, `travel_manager`, `finance_viewer`, `traveller`; invited by email (token hashed, 14 days), accepted by a signed-in user with the same email; department, ride policy, monthly allowance, employee number |
| Departments / cost centres | `business_departments` | name, cost centre code, optional monthly budget |
| Ride policies | `business_policies` | allowed categories, days, hours (overnight windows wrap), service areas (geofence on pickup and drop-off), maximum fare, purpose required, approval above an amount, approval window, guest bookings; one default policy |
| Approvals | `business_approvals` | pending until `decide_by`, approved requests are good until `use_by` (120 min) for one matching booking |
| Business trips | `business_trips` | the business side of a ride: booker, traveller or guest, department and cost-centre snapshot, purpose, policy snapshot, estimate, reservation, charged amount, spend month |
| Charges | `business_charges` | every amount billed through money's `business_account` gateway (fare or cancellation fee), linked to money's `payments` row |
| Limits | `business_spend_counters` | reserved and spent per traveller per month, per department per month, and reserved credit |
| Statements | `business_statements` | monthly, frozen snapshot, from the ledger |
| Payments received | `business_payments` | EFT recorded by an operator, or a company-card collection through Paystack |

Opening an account: anyone signed in applies at `/business` (the applicant becomes account admin; a default "Standard" policy with no limits is created). An operator reviews it at `/ops/business` and approves with a credit limit, terms and grace, or declines with a reason. Until approved nothing can be billed to it; people and policies can be set up meanwhile. Linking a team is offered only for non-personal teams the applicant owns or administers, and a team can back one live account.

## 2. Roles (`App\Domain\Business\Abilities`)

| Ability | admin | travel manager | finance viewer | traveller |
|---|---|---|---|---|
| overview, people list, policies list, all trips | yes | yes | yes (read) | own trips only |
| invite / change / remove people | yes | travellers only | no | no |
| departments and budgets | yes | no | no | no |
| create and edit policies | yes | yes | no | no |
| decide approvals | yes (all) | yes (own department, or all when either side has none) | no | no |
| statements, settings (read) | yes | no | yes | no |
| billing details, how the company pays | yes | no | no | no |
| ride on the account / book for others | yes / yes | yes / yes | no / no | yes / no (guests if the policy allows) |

The last active account admin cannot be removed or demoted. Every route resolves the member from the signed-in user (session choice when someone is on several accounts) and looks record ids up inside that account (another account's id is a 404). The side navigation shows only what the role may open (`App\Domain\Business\PortalAccess`, asked by `App\Support\Ui\Navigation` with `business:<ability>`).

## 3. Enforcement at ride request time

`EhailBusinessServiceProvider` wraps whatever `App\Domain\Dispatch\Contracts\PaymentMethodResolver` is bound (the integration's money resolver) with `BusinessPaymentMethodResolver` using `Container::extend`, so no other owner's file changed. A request with `payment_method = "business_account"` and `payment_ref = <business profile id>`:

1. `resolve()` checks the profile belongs to the passenger and is active (`business_profile_unknown` otherwise).
2. `attach()` runs inside dispatch's ride-creation transaction: it rebuilds the trip from the ride (category, pickup, drop-off, quoted total, currency, scheduled time or now), merges the business details (prepared earlier for the same quote, or sent as `business[...]` with the request), runs `PolicyEvaluator`, then `SpendLimits::reserve`, then marks a covering approval used, then lets money record the per-ride choice. Any refusal throws `DispatchRefused('business_<code>', message)`; the ride rolls back and the API answers 422 (403/409 where stated) with a plain message.

Rules checked (all reported, not only the first): account status, the booker's role, booking for someone else, guest rules (name and phone required), currency, category, day, hours (local, overnight windows), service areas (pickup and drop-off), maximum fare, purpose, traveller allowance, department budget, credit (outstanding receivable from the ledger + reservations + this fare within the limit), approval above the policy amount. Approvers' own bookings (admins and travel managers, including bookings they make for others) never need approval: they are the approvers (D-1105).

Concurrency: reservations are single conditional UPDATEs (`... SET reserved = reserved + x WHERE spent + reserved + x <= limit`) on one counter row per limit and period; atomic on SQLite, PostgreSQL and MySQL. `tests/Feature/Business/BudgetConcurrencyTest` races five forked processes on one SQLite file: exactly the rides the budget covers get through, never one more; the same for credit.

Money's per-ride choice endpoint (`PUT /money/rides/{ride}/payment-choice`) could otherwise move an existing ride onto the account afterwards; `BusinessChoiceGuard` (a `RidePaymentChoice::saving` hook) refuses that with money's own `PaymentMethodRefused` (422). `BusinessBilling::canCharge` also refuses any ride without a booked business trip.

## 4. Billing

- **Trip completes** -> money's `RideSettlement` routes the business choice to `BusinessAccountGateway::capture` -> `AccountBusinessBilling::canCharge` (a booked trip is always paid, even if the account was suspended since: an active trip is never stranded) -> `record` creates the `business_charges` row (tax from money's `FareSource` when the fare carries tax), turns the reservation into spend of the real fare and returns the reference -> money posts `payment-settled:{payment}` (Dr `business_receivable:{account}`, Cr passenger receivable) and issues the receipt.
- **Ride ends without a charge** (cancelled, expired, failed, no-show) -> `ReleaseOnRideEnd` returns the reservation; `business:sweep` also releases trips whose ride ended a day ago without a charge. A cancellation fee charged later is counted as spend then.
- **Statements** (`business:statements`, daily 05:15, issues last month for accounts without one): opening balance, charges and payments are the ledger's entries on `business_receivable:{account}` before and inside the local month; closing = the ledger balance at period end (tested). Lines are the charges whose journals fall in the period, with rider, booker, department, cost centre, purpose, category and route frozen in the snapshot. A VAT line shows only when money's `EHAIL_VAT_REGISTERED` and `EHAIL_VAT_NUMBER` are set and the fares carried tax. HTML at `/business/statements/{id}` is print-ready (A4 print styles; "PDF" is the browser's print to PDF, no PDF library); CSV at `/business/statements/{id}/csv` and month to date at `/business/invoices/month.csv` (formula-neutralised).
- **Invoice mode**: due `terms_days` after issue; an operator records EFTs at `/ops/business/{id}` (ledger `business-payment:{id}`: Dr `platform_bank`, Cr `business_receivable`).
- **Card mode**: an account admin saves the company card in their own wallet (money's card setup, R1 check refunded) and chooses it in Settings; each statement is charged with Paystack `charge_authorization` and counted as paid only when `transaction/verify` confirms our reference, amount and currency (ledger Dr `gateway_clearing`). Unknown outcome stays pending and is verified next run; a decline is retried once a day.
- **Dunning** (same daily command): past due -> `overdue` + email to the billing address; past due + grace days -> account `suspended` (reason overdue) for NEW rides; paying (EFT or card) marks statements paid oldest first and reactivates an account suspended for being overdue.

## 5. API for the passenger app (contract)

All under the web session (same as the rest of the passenger app). Errors: `{error: "business_<code>", message, reasons: [{code, message}]}`.

- `GET /business/profiles` -> `{profiles: [...]}`; empty list when the person is on no business account. Each profile: `id` (= `payment_ref`), `payment_method: "business_account"`, `member_id`, `account {id, name, status, currency, bookable, blocked_reason}`, `role`, `role_label`, `department`, `policy` (categories, days, hours, max fare, areas, purpose_required, approval_above_minor, approval_window_minutes, guest_bookings), `allowance {limit_minor, used_minor, left_minor, month}` or null, `needs_purpose`, `approval_above_minor` (null for approvers), `can_book_for_others`, `can_book_for_guests`, `travellers` (for booking on behalf), `approvals` (open and approved requests), `request_fields`.
- `POST /business/trips/prepare` `{profile_id, quote_id, purpose?, traveller_member_id?, guest_name?, guest_phone?, rider_phone?, approval_id?, scheduled_for?}` -> `{allowed, needs_approval, reasons[], approval, request: {payment_method, payment_ref}}`. Keeps the details against the quote, so the ride request itself needs only `payment_method` and `payment_ref`.
- `POST /business/approvals` (same body) -> 201 `{approval}`; 409 `business_approval_not_needed` when no approval is needed; 422 with reasons when the ride breaks the policy.
- `POST /api/ehail/rides` (dispatch) with `payment_method: "business_account"`, `payment_ref: "<profile id>"` and optionally `business: {purpose, traveller_member_id, guest_name, guest_phone, rider_phone, approval_id}`. An approved request is found automatically when the new quote matches it (same rider and category, stops within 1 km, fare within +15%).

Refusal codes: `business_profile_unknown`, `business_account_pending`, `business_account_suspended`, `business_account_closed`, `business_member_cannot_ride`, `business_cannot_book_for_others`, `business_traveller_not_active`, `business_traveller_unknown`, `business_guest_not_allowed`, `business_guest_details_missing`, `business_currency_mismatch`, `business_category_not_allowed`, `business_day_not_allowed`, `business_time_not_allowed`, `business_area_not_allowed`, `business_fare_above_maximum`, `business_purpose_required`, `business_allowance_exceeded`, `business_budget_exceeded`, `business_credit_limit`, `business_approval_required`, `business_approval_not_matching`, `business_approval_used`, `business_quote_invalid`, `business_quote_used`, `business_pricing_unavailable`.

## 6. Seams for other owners

- **Driver app / dispatch (rider on behalf):** `App\Domain\Business\RiderDetails::forRide(int $rideId): ?array{on_behalf, guest, name, phone, account}`. When a manager books for a traveller or a guest, the booker is the passenger of record (holds the PIN and the live trip); the driver needs the rider's name and phone. Show it only to the assigned driver while the ride holds the driver. Not wired into the driver feed here (driver/dispatch own it).
- **Ops navigation:** this branch registers `ops.business` (`/ops/business`), the route name `Navigation` already lists for operations; the ops agent should not register another.
- **Money:** a refund on a business-account payment is posted by money as Dr refunds / Cr platform_bank (cash-like); for an unpaid statement the right posting is a credit to `business_receivable:{account}`. Statements reconcile with the ledger either way, but the business is not credited automatically. Recorded for money (D-1114).
- **Comms:** approval requests, decisions, invitations, statements and overdue reminders are plain Laravel mail notifications (`App\Domain\Business\Notifications\*`); comms can route them through its channels.

## 7. Integrations checked

- **Dot.Billing / Dot.Finance:** re-checked 2026-11-10: the only mentions are the ecosystem app-switcher links in `config/ecosystem.php` (a name and a URL each) and a code comment; there is no client, credentials, endpoint or data contract for either. No fake integration was built; the accountant uses the statement CSV and money's ledger CSV.
- **InfoDot companies:** a business account can be linked to a Jetstream team that `SyncTeamsFromInfoDot` created from an InfoDot company (owner/admin only). Members are still invited by email; team membership does not grant business roles (a company member is not automatically allowed to bill rides).

## 8. Retention (POPIA)

Guest and rider phone numbers are stored encrypted on the business trip, shown masked in the portal ("•••• 4567"), and wiped by `business:sweep` 30 days after the trip is settled (`EHAIL_BUSINESS_GUEST_PHONE_DAYS`). Invitation tokens are stored as SHA-256 hashes only. Prepared checks are deleted after a day.

## 9. Verification

- PHP: suite before (after merging `feat/r46-integ`) 1115 tests, 1107 pass, 8 skipped; after 1160 tests, 1152 pass, 8 skipped. New: `tests/Feature/Business/{RideRequestPolicyTest, ApprovalsTest, BillingAndStatementsTest, BudgetConcurrencyTest, BusinessAccountsTest, IsolationAndPermissionsTest}.php` (45 tests). One existing assertion changed: `WalletAndSeamsTest` now expects `business_accounts.available = true` (the business domain is bound).
- `npm run build`, `php artisan view:cache`, `php artisan route:cache` pass.
- Browser (dev server on 8613, a local OSRM + Nominatim stub, a demo driver kept online by a dev script, `DemoBusinessSeeder`): signed in as the admin, booked a ride for a traveller through `/business/book` (place search, real quotes, policy check, request through `/api/ehail/rides`), finished it with a dev script so money settled it (payment paid, charge, ledger); signed in as a traveller, saw the purpose refusal, then the approval-needed state, asked for approval; signed in as the admin, approved it from the overview queue; operator review pages; screenshots in `docs/R46_screens/business/` at 1440 and 390, light and night.

## 10. Owner decisions and limits

- Credit limits, terms and grace are set per account by an operator at approval; no default market figure was invented.
- Card mode charges per monthly statement, not per trip (fewer card charges; credit limit bounds the exposure). Per-trip card charging is a possible change.
- Booking on behalf makes the booker the passenger of record, so an on-demand on-behalf booking uses the booker's one active ride; scheduled bookings are not limited.
- The approval window is per policy; the approved-request validity (120 min) and match tolerances are settings (`config/ehail_business.php`).
