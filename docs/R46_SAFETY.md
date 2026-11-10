# R-46 safety, verification, trust and support

Owner: safety agent (wave 1). Branch `feat/r46-safety`. Decisions D-500 to D-519 in `R46_DECISIONS.md`. Research in `R46_RESEARCH.md` ("Safety and trust").

## 1. What an emergency does, and what it does not

When a rider or driver presses SOS (one tap, then a 3-second cancel window in the wave-2 UI, then `POST /safety/rides/{ride}/sos`):

**Done, every time**
1. A `safety_incidents` row is created (severity critical, status open). A second press by the same person on the same ride returns the same incident (unique idempotency key, race-safe); the key is released when the incident is resolved, so a later emergency is a new incident.
2. Context is captured with it: ride status, pickup and drop-off, both parties, vehicle and registration, the reporter's phone position if sent, and the driver's last position and trail **when the geo domain provides them** (otherwise recorded as absent, never invented).
3. Every platform operator gets an in-app alert. Dot.Notify gets the same event when configured (recorded as "queued": Dot.Ehail cannot see whether Notify's email arrived).
4. The person's **confirmed, not opted-out** trusted contacts get an email at once (sent in the request, not queued: the host's queue runs once a minute), with a live trip-share link if the ride is still going, the vehicle, the last position and the emergency numbers. An SMS is sent too **only if an SMS provider is configured** and the contact gave a mobile number.
5. If nobody acknowledges within the severity's timer (critical: 2 minutes), the incident escalates: level 1 (on-call lead), level 2 (safety manager), then repeated reminders to the whole operations team on the same timer. A level with nobody configured is recorded as "nobody configured", not passed silently.
6. The response is the **"what we did"** payload, built only from `incident_notifications` rows: each email/SMS/in-app alert with its real status (`sent`, `failed`, `skipped` with why, `not_delivered` when the mail transport only logs, `queued`).

**Not done (stated on screen)**
- Dot.Ehail does **not** call the police, an ambulance or a security company, and has no private armed-response or medical partner integrated. The screen shows 10111, 10177 and 112 with tel: links and says to call.
- No audio or video is recorded. No automatic live location stream to contacts beyond the share link.

## 2. Architecture

Namespaces: `App\Domain\Verification` (documents, review, eligibility, file security), `App\Domain\Safety` (contacts, shares, incidents, check-ins, trust, ratings moderation, SMS), `App\Domain\Support` (cases). Provider: `App\Providers\EhailSafetyServiceProvider` (bindings, policy, limiters, listeners, schedules, the go-online guard). Config: `config/ehail_safety.php`. Routes: `routes/ehail/safety.php` (authenticated JSON APIs), `routes/ehail/safety-public.php` (required from `public.php`: `/t/{token}` and the trusted-contact answer pages).

Tables: `verification_documents`, `verification_reviews` (append-only), `trusted_contacts` (encrypted name/email/phone, keyed email hash), `trip_shares` (SHA-256 of the token only), `safety_incidents`, `incident_events` (append-only timeline), `incident_notifications` (every alert and its outcome), `safety_check_ins`, `user_reports`, `support_cases`, `support_messages`, `rating_appeals`; `ride_ratings` gains `comment_status`, `moderated_by`, `moderated_at`, `moderation_reason`, `excluded_at`.

### Interfaces other domains use
- `App\Domain\Verification\DriverEligibility::canGoOnline(User $driver, Vehicle $vehicle): EligibilityResult` with `allowed()`, `codes()`, `reasons[] {code, subject, type, message}`. Codes: `no_driver_profile`, `driver_not_approved`, `driver_suspended`, `vehicle_not_owned`, `vehicle_inactive`, `document_missing`, `document_pending_review`, `document_rejected`, `document_resubmission_requested`, `document_expired`, `document_too_old`. **Dispatch's `AvailabilityService::goOnline` must call it.** Defence in depth: a `DriverProfile::updating` guard refuses any change of `is_online` from false to true that the gate refuses (throws `DriverNotEligibleToGoOnline`: 422 JSON with reasons, or back with an `online` error).
- `IncidentService::raise(?Ride, User, IncidentKind, array)`, `acknowledge`, `escalate`, `escalateDue`, `resolve`, `close`, `whatWeDid`.
- `TripShareService::create(Ride, User, ?TrustedContact)` returns the URL once.
- `IncidentNotifier` (the contract's TrustedContactNotifier).
- `SmsGateway` (`null` default, `bulksms` adapter).

### Seams this domain consumes (bound with `bindIf`, so the owning domain's provider wins)
| Seam | Default here | Owner to bind |
|---|---|---|
| `Safety\Seams\SafetyLocationSource` | `GeoLocationStoreAdapter`: forwards to `App\Domain\Geo\LocationStore::latest()/trail()` when it exists, else nothing | geo (nothing to do if their interface name matches the contract) |
| `Safety\Seams\TripProgressSource` | `RideRecordProgressSource`: ride status + location source + ETA from `App\Domain\Dispatch\RideFeed::forPassenger()` when present (`eta_seconds`/`eta_s`) | dispatch (optional) |
| `Safety\Seams\RidePinSource` | `NoRidePin` (no PIN) | dispatch: return `{required, pin}` for the passenger |
| `Support\Seams\FareAdjustmentRequest` | `RecordOnlyFareAdjustmentRequest`: records on the case, answers `not_connected` | pricing |
| Geo events `App\Domain\Geo\Events\RouteDeviationDetected`, `UnusualStopDetected` | listener registered by class name; reads public `ride` (Ride) or `rideId`/`ride_id`, forwards scalar properties as context | geo |

## 3. Workflows

### Verification
Document types per market are config (`ehail_safety.markets.ZA.document_types`): subject driver or vehicle, required, expiry required, accepted kinds, per-category overrides (premium vehicle photos must be under 180 days old, SUV 365). Upload: `POST /verification/documents` (type, document, vehicle_id, expires_on). Files: type sniffed from bytes; images decoded and re-encoded with GD (EXIF and GPS dropped, upright, capped at 2400 px and 40 MP), PDFs checked for `%PDF-` and stored as uploaded; private disk; streamed only after the policy check with `nosniff`, `no-store`, a sandbox CSP. **No virus scanning** (no scanner on the host). States: pending -> approved | rejected | resubmission_requested; approved -> expired | superseded; a new upload supersedes unapproved ones at once, but an approved document stays in force until its replacement is approved (renewals never take a driver offline). Reviewers: platform operators only, never their own document, reason codes from config (`other` needs a note), approving an expiring type needs the expiry date. Daily `ehail:verification-expiry`: warnings at 30 and 7 days (once each) and expiry; an expired or rejected mandatory document takes an online driver offline (audited `driver.forced_offline`). Access: owner, assigned reviewer, operators; fleet owners are **not** allowed (D-503); every non-owner view is an `audit_logs` row.

Existing `driver_documents` rows are carried into `verification_documents` as **pending** (licence -> drivers_licence, id -> identity_document, vehicle_inspection with a vehicle -> roadworthy_certificate); the legacy upload flow still works and is not removed (wave-2 driver UI should switch to `/verification/*`). Legacy files were not re-encoded (their EXIF stays until re-uploaded).

### Trusted contacts and trip sharing
Up to 5 contacts; confirmation by an emailed link (token hashed, 72 h, GET shows a button, POST confirms, so link scanners cannot confirm); every alert/share email carries a signed, non-expiring "stop alerts" link. Share links: 256-bit random token, only its SHA-256 stored, hard expiry 6 h, dead 30 minutes after the trip ends or when revoked, max 10 live per ride; the public page and its 5-second poll run without session, cookie or CSRF middleware, `noindex`, `no-store`, `no-referrer`; payload has first names, vehicle and registration, trip state, position rounded to 3 decimals (about 110 m) when the geo domain provides one, ETA only when dispatch provides one.

### Check-ins (route deviation, unusual stop)
On a geo event both parties get an in-app "are you OK?" prompt (deduplicated per ride, trigger and person per 10 minutes). "I need help" raises a critical deviation incident and alerts that person's contacts; no answer within `EHAIL_CHECKIN_MINUTES` (default 3) raises a high incident for operations (contacts are not alerted on silence alone). The sweep runs every minute and on every check-in poll.

### Driver safety and trust
The driver's card shows facts only: first name, email verified, phone verification (always `null`: phones are not collected yet), account age band, completed trips, rating band. Private reports (11 categories) are separate from ratings, deduplicated per ride/category, never shown to the reported person; reviewing records an outcome only. Hourly `ehail:cancellation-patterns` flags (never bans) accounts with 5+ cancellations after assignment in 7 days.

### Ratings
One per trip per side and completed rides only (existing rules), JSON endpoint rate-limited (10/min, 100/day). Comments with held terms are held for moderation; operators hide or release; drivers appeal once within 30 days; an upheld appeal excludes the rating from the average (row kept). Sensitive surfaces show bands (`new` below 5 ratings, then mixed/good/very good/excellent), not decimals.

### Support
Categories, statuses (new, triaged, in_progress, waiting_on_user, resolved, closed with guarded transitions), priorities with SLA timers (config), escalation on breach (once per breach kind, priority raised, operators told), resolution codes, attachments (same file security). Lost property brings in the other party as counterparty; their messages are relayed with phone numbers and emails masked, and each side sees "the driver"/"the passenger", not names. Fare disputes call `FareAdjustmentRequest`. A safety-category case raises an incident for the safety queue.

## 4. Scheduler (provider registers; needs the host's existing `schedule:run` cron)
`ehail:safety-sweep` every minute, `ehail:support-sla` every five minutes, `ehail:cancellation-patterns` hourly, `ehail:verification-expiry` daily 05:00.

## 5. Owner decisions (open)
1. **Response partner**: integrate a private armed-response / medical provider for SOS? Needs a contract and their API; until then the copy must keep saying Dot.Ehail does not dispatch help.
2. **SMS provider**: BulkSMS adapter is built and tested against its documented API with faked HTTP only (never against the live service). To turn on: `EHAIL_SMS_DRIVER=bulksms`, `BULKSMS_TOKEN_ID`, `BULKSMS_TOKEN_SECRET`, optional `BULKSMS_SENDER`. Clickatell or another provider = one more adapter.
3. **Legal confirmation of the document list** (PrDP category, operating licence under the amended NLTA, roadworthy frequency, police clearance) and of retention periods (POPIA). Config only; no code change needed.
4. **Escalation recipients**: `EHAIL_SAFETY_ONCALL_USER_IDS`, `EHAIL_SAFETY_ONCALL_EMAILS`, `EHAIL_SAFETY_MANAGER_USER_IDS`, `EHAIL_SAFETY_MANAGER_EMAILS`. With none set, levels are recorded as "nobody configured" and all operators keep being reminded.
5. **Mail**: production `MAIL_MAILER` must be a real transport (SMTP via no-reply@infodot.co.za per the ecosystem); with `log`/`array` every alert is reported as not delivered.
6. **Dot.Notify catalogue**: new events `safety.incident_alert`, `driver.document_reviewed`, `driver.document_expiring`, `driver.document_expired` must be added in Dot.Notify or Notify will refuse them (harmless, logged).
7. Not built, each needs a decision: audio recording, live selfie/face checks, women-preference matching, phone-number verification, document OCR/authenticity checks, virus scanning.
8. **Migration switch**: `EHAIL_VERIFICATION_REQUIRE_DOCUMENTS=true` (default) means every existing approved driver goes offline-ineligible until their documents are uploaded and approved. Set it to false only for a planned transition window (logged on every check); profile approval and an active vehicle are always required.
