# R-46 Money: payments, ledger, earnings, payouts, incentives, receipts

Owner: money agent. Code: `App\Domain\Payments\*`, `App\Domain\Ledger\*`, `App\Domain\Earnings\*`, `App\Http\Controllers\Money\*`, `routes/ehail/money.php`, `App\Providers\EhailMoneyServiceProvider`, `config/money.php`, migrations `2026_11_10_6000xx`. Research: `docs/R46_RESEARCH.md` ("Payments and driver economics"). Decisions: D-600 to D-619.

All amounts are integer minor units (cents) with an ISO currency. No float touches an amount (`App\Domain\Payments\Money`: string decimal parsing, basis-point percentages, half-up rounding).

## 1. Flows

### Trip completes
Trigger: the contract event `App\Domain\Rides\Events\TripCompleted` (stub written here; dispatch owns it) **and** the ride row reaching `completed` by any code path (existing lifecycle, operator). Both queue `SettleCompletedRide` after commit; the work is idempotent, so both firing is harmless.

`RideSettlement::onTripCompleted`:
1. Final fare from the `FareSource` seam (today: `rides.final_fare`; at merge: pricing's `FareFinaliser`).
2. Route: the ride's choice (`ride_payment_choices`: card / cash / business account), else the passenger's default chargeable card, else the existing Paystack checkout link (`payment_status = unpaid`), else **not collected** (payments switched off: `not_due`, no journal, no earning, exactly the pre-R-46 behaviour).
3. Recognise the fare (journal `trip-revenue:{ride}`) and create the driver's **pending** earning (split from the `EarningsSplitSource` seam).
4. Collect: card = server-side `charge_authorization`, then `verify`; cash = `awaiting_cash`; business account = `BusinessBilling` seam.

### Card charge (exactly once)
- One `ride_payments` row per (ride, purpose, attempt) with `idempotency_key = ride:{id}:{purpose}:{n}` and a unique reference, created under the ride row lock. A request while an attempt is pending/paid gets that attempt back (concurrent capture attempts send one charge: tested).
- The gateway call happens after the row is committed. **Paid only from Paystack's verify answer** with matching reference, amount and currency. A timeout leaves it `pending`; `money:sweep` (every minute) verifies it. Paystack "reference not found" more than 10 minutes later = never charged = failed.
- Declined: `failed`, passenger notified, retries after `EHAIL_CHARGE_RETRY_MINUTES` (default 15, 120, 1440). After the last retry: finance issue `payment_retries_exhausted`, and the passenger is **blocked from new requests** (`PaymentDueGuard`, middleware alias `ehail.payment-clear`, HTTP 402 for JSON) until they pay with another card (`POST /money/rides/{ride}/pay-now`) or an operator waives.
- Unpaid checkout rides also block after `EHAIL_PAYMENT_DUE_GRACE_MINUTES` (default 15).

### Webhooks
`POST /webhooks/paystack` (existing route): signature checked before parsing (unchanged); the event is stored once in `payment_webhook_events` (customer, card, recipient and metadata stripped; idempotency key = hash of event name + object id/reference/status/codes); `ProcessPaystackWebhook` (queued, 5 tries) applies it. Storing is the acknowledgement: a processing failure is retried by the queue and by `money:sweep`, and after the last try becomes finance issue `webhook_failed`.
- `charge.success` -> verify, then: legacy checkout (`RidePayments`, now also verify-first), card setup, or a pending server-side charge.
- `refund.*` -> `GET /refund/{id}`, then apply.
- `transfer.*` -> `GET /transfer/verify/{reference}`, then apply.

### Adding a card
`POST /money/cards` -> card-only Paystack checkout for `EHAIL_CARD_SETUP_AMOUNT_MINOR` (default R1.00). Callback/webhook -> verify -> if Paystack marks the authorisation `reusable`, store the token encrypted (`payment_methods.authorization_code`, cast `encrypted`) with brand, last4, expiry, bank; de-duplicated by Paystack's `signature`. The R1.00 is refunded at once (`POST /refund`); if the refund cannot be sent, finance issue `card_setup_refund`. Removing a card wipes the token.

### Cash
Off unless `EHAIL_CASH_ENABLED=true`; optional category list `EHAIL_CASH_CATEGORIES`; each driver opts in (`PUT /money/driver/settings`). The driver confirms the amount received (`POST /money/driver/rides/{ride}/cash-received`). The ledger records what the driver says they hold, up to the fare. Less than the fare -> finance issue `cash_discrepancy` + the `SupportCaseOpener` seam (safety/support domain); the passenger is **not** blocked on the driver's word; the shortfall stays receivable until an operator resolves it (adjustment or write-off). More than the fare is the driver's (change/tip).

### Refunds (operator only)
`POST /money/ops/payments/{payment}/refunds` (`amount_minor`, `reason`, `note`, `charge_back_driver`). Never more than paid minus processed and open refunds. Card: Paystack refund API; **processed only when Paystack says processed** (create response or webhook + fetch). Cash/business: recorded as manual and processed immediately (the operator returned the money). `charge_back_driver` recovers up to the driver's net for the trip as a negative adjustment earning.

### Cancellation fees
On `RideCancelled` (stub) or a ride row reaching `cancelled*`, **only when a `CancellationFeeSource` is bound** (pricing's `CancellationFeePolicy`). Default `NoCancellationFee`: no fee, no job. A fee is charged to a saved card or business account only; a fee nobody can collect is not recognised. Driver share `EHAIL_CANCELLATION_DRIVER_SHARE` (default 80%).

### Waivers
The existing operator waive action now also posts `fare-waived:{ride}` (receivable to `waivers_expense`) and makes the driver's earning available: the driver is paid for a trip the platform wrote off.

## 2. Chart of accounts

| Account | Nature | Meaning |
|---|---|---|
| `passenger_receivable` | asset | fares and fees passengers owe |
| `gateway_clearing` | asset | money held at Paystack (card receipts in, refunds and transfers out) |
| `platform_bank` | asset | the operator's bank (manual payouts and manual refunds leave it) |
| `cash_with_driver:{driver}` | asset | cash fares a driver holds for the platform |
| `business_receivable:{account}` | asset | trips billed to a business account |
| `card_verification_clearing` | liability | R1 card checks awaiting refund |
| `driver_payable:{driver}` | liability | earnings owed to a driver |
| `fleet_payable:{fleet}` | liability | earnings owed to a fleet owner (when configured as payee) |
| `tax_payable` | liability | tax inside fares (only when pricing's tax mode adds it) |
| `platform_revenue_commission` | revenue | commission (+ driver deductions, platform share of cancellation fees) |
| `platform_revenue_fees` | revenue | booking/service fees kept whole by the platform |
| `promotions_expense` | expense | promotions funded by the platform |
| `incentives_expense` | expense | driver incentives awarded |
| `refunds` | expense | refunds to passengers (credited back by charge-backs) |
| `waivers_expense` | expense | fares written off |
| `adjustments` | expense | manual adjustments |

## 3. Posting rules (`App\Domain\Ledger\PostingRules`)

| Event | Key | Lines |
|---|---|---|
| Trip completed | `trip-revenue:{ride}` | Dr receivable (gross - promo), Dr promotions (promo); Cr tax, Cr fees, Cr commission (+deductions), Cr driver/fleet payable (net) |
| Payment verified | `payment-settled:{payment}` | Dr gateway_clearing / cash_with_driver:{d} / business_receivable:{b}; Cr receivable |
| Card check charge | `card-setup:{payment}` | Dr gateway_clearing; Cr card_verification_clearing (reversed by the refund) |
| Cancellation fee | `cancellation-fee:{ride}` | Dr receivable; Cr commission (platform share), Cr payable (driver share) |
| Refund processed | `refund:{refund}` | Dr refunds; Cr gateway_clearing (Paystack) or platform_bank (manual) |
| Refund charged back | `refund-charge-back:{refund}` | Dr driver payable; Cr refunds |
| Fare waived | `fare-waived:{ride}` | Dr waivers_expense; Cr receivable (outstanding) |
| Incentive earned | `incentive:{program}:{driver}:{window}` | Dr incentives_expense; Cr payable |
| Payout paid | `payout:{payout}` | Dr payable(s); Cr cash_with_driver (netted), Cr platform_bank or gateway_clearing (sent) |
| Payout reversed | `payout-reversal:{payout}` | the mirror of the above |
| Adjustment | `adjustment:{uuid}` | operator lines over the chart, mandatory reason, audited |

Invariants (tested on SQLite and on PostgreSQL 16 locally (money + Paystack suites, 170 tests), including a 300-iteration property test): every journal balances; posting a key twice writes once; amounts are positive and one-sided; ledger rows cannot be updated or deleted (model guards everywhere; database triggers on SQLite and PostgreSQL; on PostgreSQL a deferred constraint trigger also refuses to commit an unbalanced journal; MySQL: immutability triggers attempted, logged if the host refuses); receipts cannot be changed (model guard + trigger).

## 4. Driver earnings
One `driver_earnings` row per trip, cancellation fee, incentive or adjustment: fare, commission, deductions (fees + tax + split deductions), incentive, net, cash collected, estimate, breakdown (plan label, fare components, estimate vs actual with pricing's reason codes, distance, duration), status pending -> available (when the trip's payment is settled or waived; optional hold `EHAIL_EARNINGS_HOLD_HOURS`) -> paid. Statements by day/week/month with totals and averages computed only from trips in the period (null when there is nothing to average); CSV export (formula-neutralised). Balances: available, pending, cash held, cash commission owed, next payout estimate.

## 5. Payouts
- **Schedule:** `money:draft-payouts` daily at `EHAIL_PAYOUT_TIME`, acting only on ISO weekday `EHAIL_PAYOUT_DAY` (default Monday), for earnings up to the end of yesterday. Operators can draft any past period end (`POST /money/ops/payout-batches`).
- **Composition:** per payee (driver, or fleet owner when `EHAIL_FLEET_DRIVER_PAYEE=fleet_owner`), the sum of available unpaid earnings, minus cash their drivers hold (not already netted in another open payout). Below `EHAIL_PAYOUT_MINIMUM_MINOR` (default R50) or without a bank account: carried forward, reasons in `payout_batches.summary`. A payout fully netted against cash moves no money and is settled at once.
- **Manual (always available):** export the bank CSV (full account numbers, operator only, audited), pay from the operator's bank, mark paid (all or selected). Mark failed releases the earnings to the next batch; reverse re-posts what is owed.
- **Paystack Transfers (off by default):** `EHAIL_PAYSTACK_TRANSFERS=true` + Paystack configured. Creates the `basa` recipient once, `POST /transfer` with the payout reference (idempotent at Paystack), status processing; paid only when `transfer/verify` says success (webhook or sweep). OTP-pending transfers are flagged with the fix.
- **Bank accounts:** holder and number encrypted; HMAC blind index flags one account used by two payees (critical finance issue). Verification through Paystack `POST /bank/validate` when configured (ID number used once, never stored); otherwise status `unavailable` and the operator checks it.
- Drivers see payout history with the exact earnings and cash netted.

## 6. Incentives
Programmes (`trip_count`, `area` (pickup polygon), `milestone` (lifetime trips, once), `online_hours` (+ acceptance), `referral`), windows day/week/month/programme, optional `min_rating`, optional fleet scope, budget cap, statuses draft/active/paused/ended. `money:evaluate-incentives` hourly evaluates the current and just-closed window from completed trips. A reward becomes an earning and a journal **only when every rule is met**; progress shows measured facts and "in progress" until then; a closed window becomes `not_met`; an exhausted budget `budget_exhausted`. **Online hours, acceptance and referrals are "unmeasurable" (never met)** until the dispatch domain (availability, offers) and a referral system bind the `DriverActivitySource` seam.

## 7. Receipts
Issued when a fare or fee is paid (card, checkout, cash confirmed, business account): gapless number `EHR-00000001` (row-locked `money_sequences`), frozen snapshot: route, times, distance and duration (when known), fare lines, promotion, total, masked method, VAT number/line only when `EHAIL_VAT_REGISTERED` and `EHAIL_VAT_NUMBER` are set AND the fare carries tax, driver first name, vehicle, support link. HTML at `/money/receipts/{id}` (print-friendly, A4 print styles; **no PDF library is installed, so "PDF" is the browser's print-to-PDF**), JSON for the app, and an email via the mail channel (`ReceiptIssuedNotification`, link to the page). Designed with the impeccable skill (detector: no findings), checked on desktop dark and mobile light.

## 8. API (all under the authenticated web group; JSON; errors JSON for `money/*` when the client expects JSON)
Passenger: `GET /money/wallet`, `GET /money/payment-due`, `POST /money/cards`, `GET /money/cards/callback`, `POST /money/methods/{id}/default`, `DELETE /money/methods/{id}`, `PUT /money/rides/{ride}/payment-choice`, `POST /money/rides/{ride}/pay-now`, `GET /money/receipts`, `GET /money/receipts/{id}`.
Driver: `GET /money/driver/earnings?period=day|week|month&date=`, `GET /money/driver/earnings.csv`, `GET /money/driver/earnings/{id}`, `POST /money/driver/rides/{ride}/cash-received`, `GET|PUT /money/driver/settings`, `GET /money/driver/payouts`, `GET /money/driver/payouts/{id}`, `GET /money/driver/incentives`, `GET|POST /money/payout-accounts`, `POST /money/payout-accounts/{id}/verify`.
Fleet owner: `GET /money/fleets/{fleet}/earnings`, `.csv`.
Operations (`operator` middleware): payments, payment detail, refunds (create/list), payout batches (list/draft/show/export/mark-paid/transfers), payout fail/reverse, reconciliation report, settlement CSV compare, finance issues (list/resolve), incentives (list/create/status/evaluate), trial balance, ledger CSV, adjustments.
Isolation (tested): drivers see only their earnings and payouts; fleet owners only their approved fleets' drivers; passengers only their receipts and methods; every `money.ops.*` route refuses non-operators.

## 9. Automatic vs manual

| Automatic | Manual (operator) |
|---|---|
| Card charge after a trip, verify, retries, payment-due block | Refunds (every one), refund reasons |
| Webhook processing, pending verification (`money:sweep`, every minute); never-settled completed rides re-queued and listed (`ehail:settlement-sweep`, every five minutes, D-1607) | Cash discrepancy resolution (adjustment / write-off) |
| Ledger posting for every money event | Payout batch: export, bank payment, mark paid / failed / reversed |
| Earnings availability on settlement | Paystack transfers: pressing "send" (when enabled) |
| Weekly payout batch **draft** | Bank account checks when Paystack validation is not configured |
| Incentive evaluation and award (hourly) | Creating, activating, pausing incentive programmes |
| Receipts and receipt email | Reconciliation against the Paystack export; resolving finance issues |
| Nightly reconciliation (`money:reconcile`) lists mismatches | Ledger adjustments |

## 10. Owner steps
1. **Paystack live keys:** `PAYSTACK_SECRET_KEY=sk_live_...` and `PAYSTACK_ALLOW_LIVE=true` (the existing double switch). Webhook URL in the Paystack dashboard: `https://<host>/webhooks/paystack`.
2. **Cron:** the existing `schedule:run` and `queue:work --stop-when-empty` crons run everything here (sweep, incentives, payout drafts, reconciliation, webhooks, receipts).
3. **Commission:** set `EHAIL_COMMISSION_PERCENT` (default 20 is a placeholder setting, not a market figure) until pricing's commission plans are bound.
4. **Cash:** decide `EHAIL_CASH_ENABLED` and `EHAIL_CASH_CATEGORIES` (off by default; see the research on driver safety).
5. **Payouts:** `EHAIL_PAYOUT_DAY`, `EHAIL_PAYOUT_TIME`, `EHAIL_PAYOUT_MINIMUM_MINOR`, `EHAIL_FLEET_DRIVER_PAYEE`. For automatic transfers: a Paystack **Registered Business** with transfers enabled, a funded balance (EFT top-up), OTP disabled for API transfers, then `EHAIL_PAYSTACK_TRANSFERS=true`. Until then, pay from the bank file.
6. **VAT:** decide registration; set `EHAIL_VAT_REGISTERED`, `EHAIL_VAT_NUMBER`, `EHAIL_VAT_RATE`; pricing's tax mode decides whether fares carry tax.
7. **Receipts:** `EHAIL_OPERATOR_LEGAL_NAME`, `EHAIL_SUPPORT_URL` or `EHAIL_SUPPORT_EMAIL`, `EHAIL_RECEIPT_PREFIX`.
8. **Card check amount:** confirm R1.00 (`EHAIL_CARD_SETUP_AMOUNT_MINOR`) is accepted on the live account.
9. **Accounting:** there is no Dot.Finance contract for Ehail; export the ledger CSV for the accountant.

## 11. Seams for the merge (bound with `bindIf` defaults in `EhailMoneyServiceProvider`)

| Seam (money interface) | Default now | Bind at merge to |
|---|---|---|
| `FareSource::finalFareFor(Ride): ?FareBreakdown` | `LegacyFareSource` (rides.final_fare) | adapter over pricing `FareFinaliser::finalise` + `ride_fares` snapshot (components, promotion, tax, platform fees, estimate, reason codes, distance, duration) |
| `EarningsSplitSource::split(Ride, FareBreakdown, User)` | `ConfigCommissionSplitter` | adapter over pricing `EarningsSplitter::split` + `commission_plans` |
| `CancellationFeeSource::feeFor(Ride, actor, at): ?int` | `NoCancellationFee` | pricing `CancellationFeePolicy` |
| `BusinessBilling` (`canCharge`, `record`) | `UnconfiguredBusinessBilling` | wave-2 business domain |
| `PayeeResolver::payeeFor(User)` | `ConfigPayeeResolver` | wave-2 fleet arrangements |
| `DriverActivitySource` (online seconds, acceptance, referrals) | `UnmeasuredDriverActivity` | dispatch `driver_availability` / `ride_offers`; a referral system |
| `SupportCaseOpener::open(...)` | `NoSupportCases` | safety `Support` cases |
| Events `TripCompleted`, `RideCancelled` | stubs in `App\Domain\Rides\Events` | dispatch's classes (keep `rideId`, `actorId`, `occurredAt`, `reasonCode`; `RideCancelled` also `actor`) |
| Middleware `ehail.payment-clear` | provided | dispatch applies it to ride-request routes |
