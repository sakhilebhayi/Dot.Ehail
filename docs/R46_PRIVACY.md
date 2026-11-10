# R-46 Dot.Ehail: POPIA technical record

A technical record of what Dot.Ehail holds about people, why, for how long, and what the code does about it. **It is not legal advice.** The lawful bases, periods and the cross-border position below are proposals for the owner and the information officer to confirm. Prepared by the security agent (decisions D-1400 to D-1418); findings and fixes are in `docs/R46_SECURITY.md`.

## 1. Roles

- Responsible party: the operator of Dot.Ehail (owner step: name the legal entity in the privacy policy).
- **Information officer: an owner step.** POPIA s55-56 requires one (by default the head of the responsible party), registered with the Information Regulator, with deputies as needed. Their contact belongs in the privacy policy and in the "Download my data" export header text, which already points to "the information officer named in the privacy policy".
- Operators (staff) act under the role matrix (`docs/R46_UI_OPS.md`); what they open is audited (section 8).

## 2. Personal information inventory (by subject)

| Subject | Where (tables, files) | Sensitive fields | Protection | Purpose |
|---|---|---|---|---|
| Account holders (passengers, drivers, staff) | `users`, `sessions`, `security_known_devices`, `password_reset_tokens`, `personal_access_tokens`, `team_*` | name, email, password hash, 2FA secrets, IP + user agent in sessions, device hash | 2FA secrets encrypted (Fortify); devices stored as a keyed hash only; sessions 120 min | sign-in, account security |
| Passengers' trips | `rides`, `ride_events`, `ride_offers`, `ride_routes`, `ride_fares`, `fare_quotes`, `ride_payment_choices`, `idempotency_keys`, `passenger_saved_places` | pickup/drop-off addresses and coordinates, trip changes with coordinates, planned route, saved home/work | trip PIN encrypted; addresses shown to the driver only for the current trip; fleets see first names, no addresses (D-1206, D-1402) | providing the ride, fares, disputes |
| Drivers | `driver_profiles`, `driver_availability`, `driver_online_sessions`, `ops_availability_spans`, `driver_suspensions`, `driver_money_settings`, `vehicles`, fleet tables | SA ID and licence numbers, online periods, suspension reasons (possibly alleged offences), plate | identifiers encrypted with HMAC blind indexes; masked for fleets and for ops roles without verification.review | eligibility, safety, dispatch, payouts |
| Identity documents | `verification_documents`, `verification_reviews`, `driver_documents` (legacy); files on the private `driver-documents` disk | ID/passport, licence, PrDP (implies a police clearance), profile photo | private disk never served; sniffed and re-encoded (EXIF/GPS stripped); streamed only after policy + role check with a sandboxing CSP; every view audited | verification before driving |
| Locations | `driver_locations` (latest), `trip_points` (trail), `route_cache`, `driver_availability.last_lat/lng` | live and historical driver positions (and so passengers' during trips) | passenger sees only the assigned driver while tracking; drivers never receive passenger positions; operators need drivers.positions, only while the ride holds the driver, audited | dispatch, ETA, fare distance, safety |
| Payments | `payment_methods`, `ride_payments`, `refunds`, `receipts`, `ledger_*`, `payment_webhook_events`, `business_charges` | Paystack authorisation code (card token), last4, brand, payer email sent to Paystack | authorisation code encrypted; no card number ever handled (Paystack hosted page); webhook payloads stored minus customer/authorisation/IP | charging, receipts, accounting (statutory) |
| Payout accounts | `payout_accounts`, `payouts`, `payout_batches`, `driver_earnings` | account holder and number, bank | holder and number encrypted, blind index for duplicate detection, masked in UI; changes need recent sign-in, 72 h cooling-off, notice | paying drivers and fleets |
| Business travellers and guests | `business_members`, `business_trips`, `business_approvals`, `business_statements`, `business_accounts` | traveller and guest names, guest phone, trip purpose, company registration/VAT | rider phone encrypted, purged 30 days after the trip; approvals details purged after a year | company travel billing |
| Trusted contacts | `trusted_contacts` | name, email, phone of a third party | encrypted; double opt-in; opt-out link; declined/unconfirmed removed after 30 days | safety alerts |
| Incidents (special information possible: health in accidents, alleged criminal conduct) | `safety_incidents` (context snapshot: names, addresses, last trail points, driver position), `incident_events`, `incident_notifications`, `safety_check_ins` | descriptions, positions, notes | ops safety roles only, audited; trail held while open and 365 days after | safety response, evidence |
| Support and reports | `support_cases`, `support_messages` + files on `safety-files`, `user_reports`, `ride_ratings`, `rating_appeals` | free text, attachments, allegations | role-guarded, attachments streamed privately and audited | help, disputes, safety |
| Notifications | `notifications`, `notification_deliveries`, `push_subscriptions`, `notification_sms_numbers`, `notification_preferences` | message texts, push endpoints, phone numbers | endpoints and numbers encrypted; texts use first names and no addresses (D-1313, S-23) | service messages |
| Audit | `audit_logs` | who did what to which record | append-only in practice; operators cannot edit | accountability (s19 security safeguards) |

Special personal information (POPIA s26): no biometric processing (photos are not matched); criminal behaviour may appear in incident and report text and in suspension reasons; health may appear in accident incidents. Processing rests on s27 grounds to be confirmed by the information officer (proposed: establishment, exercise or defence of a right; and the data subject's consent where obtained). Access is restricted to safety roles and audited.

## 3. Purpose and lawful basis (proposal, s11)

- Contract (s11(1)(b)): accounts, rides, payments, receipts, driver payouts, business billing.
- Legal obligation (s11(1)(c)): accounting and tax records (ledger, receipts, payments, payouts), kept for the statutory period.
- Legitimate interest (s11(1)(f)): fraud prevention (blind indexes, rate limits, new-device notices), safety (incident records, trails held while open), platform security (audit logs).
- Consent (s11(1)(a)): trusted contacts (the contact confirms by link), optional SMS and push channels, marketing (none built).

## 4. Data minimisation in the product

First names between passenger and driver; masked phones for business riders; no passenger live position anywhere; positions on trip shares rounded to about 110 m; fleets see status and masked identifiers only with an operator grant; notification texts without addresses; avatars generated locally (no third-party request with initials, S-30).

## 5. Retention schedule (as implemented)

All scheduled from the host's one-minute cron (`schedule:run`); `php artisan schedule:list` shows each.

| Data | Period | Mechanism |
|---|---|---|
| Raw trip points | 90 days after the trip (summary kept), unless an incident holds the ride (open, or resolved in the last 365 days) | `ehail:geo-purge` daily 02:40 + `OpenIncidentTrailHold` |
| Driver latest location | 7 days silent | `ehail:geo-purge` |
| Offline driver's last position | cleared on going offline; old rows nulled daily | `AvailabilityService::goOffline`, `ehail:privacy-purge` |
| Route cache | its expiry (minutes) | `ehail:geo-purge` |
| Unused quotes | 24 h after expiry | `ehail:pricing:prune-quotes` hourly |
| Consumed quotes' coordinates | coarsened to ~1 km after 90 days | `ehail:privacy-purge` daily 03:10 |
| Planned route polylines of finished rides | 90 days | `ehail:privacy-purge` |
| Idempotency keys | their expiry | `ehail:dispatch-tick` every minute |
| Business rider phone | 30 days after the trip | `business:sweep` every minute |
| Decided business approval details (guest name, addresses, purpose) | 1 year | `ehail:privacy-purge` |
| Notification delivery records; revoked push subscriptions | 90 days | `ehail:comms-prune` daily 03:30 |
| In-app notifications | read: 180 days; unread: 360 days | `ehail:privacy-purge` |
| Closed incidents' snapshot (context, position, description) | 5 years after closing | `ehail:privacy-purge` |
| Closed support cases' messages and attachments | 2 years after closing | `ehail:privacy-purge` |
| Superseded, rejected, expired identity document files | 90 days (row kept, file deleted, path marked `purged/`) | `ehail:privacy-purge` |
| Declined or never-confirmed trusted contacts | 30 days | `ehail:privacy-purge` |
| Failed jobs; password reset tokens | 30 days; 1 day | `ehail:privacy-purge` |
| Processed payment webhook events | 1 year | `ehail:privacy-purge` |
| Audit logs | 6 years | `ehail:privacy-purge` |
| Sessions | 120 minutes | session garbage collection |
| Rides, payments, ledger, receipts, payouts, earnings | statutory accounting period (owner: confirm, commonly 5 years under the Companies Act and Tax Administration Act) | not purged by the application; ledger and receipts are immutable |

Periods live in `config/ehail_security.php` (`retention`, environment-overridable). Owner step: confirm them with the information officer.

## 6. Account closure (s24 correction and deletion)

`DeleteUser` (Jetstream's "Delete account") calls `AccountClosure` (D-1411):
- no ride, money, safety, business or fleet history: deleted outright, as before;
- otherwise anonymised: the `users` row becomes a tombstone ("Closed account", unusable email, random password, no 2FA, `closed_at`), contact data and channels deleted, card tokens nulled, identity numbers replaced (the keyed blind index stays so a suspended driver cannot re-register with the same ID), identity document files deleted; rides, payments, ledger, receipts, payouts, incidents, reports, suspensions and support cases stay, linked to the tombstone;
- refused while a ride is under way.

Gap (owner step): Jetstream's delete form asks for the local password, which InfoDot-provisioned users do not know; until the form accepts a fresh sign-in instead, such users ask support (or the information officer), who runs the same closure.

## 7. Data subject access (s23)

`GET /account/data-export` ("Download my data" on the passenger and driver account pages): one JSON file with the person's account, rides as passenger (addresses and coordinates) and as driver (no rider details beyond what they saw), driver profile (their own identifiers), documents metadata, vehicles, saved cards (brand and last4), payments, receipts, earnings, payouts, payout accounts (masked), trusted contacts, incidents reported, reports made, support cases with their own messages, ratings given and received (no rater names), saved places, business memberships, notification preferences and notifications, sign-in summary, their own audited actions. Never included: password hash, 2FA secrets, card authorisation tokens, the trip PIN, other people's contact details or positions. Needs a recent sign-in, 5 per hour, audited (`account.data_exported`), `no-store`.

## 8. Operator access logging

Audited views: identity documents (R-46 and legacy), driver profiles with identifiers, driver photo, support attachments, incident opens (page and API), driver positions read through a ride, ops views of rides, drivers, incidents, cases; data exports. The ops page "Who viewed what" (`/ops/safety/access`, audit.view) lists them with filters.

## 9. Cross-border transfers (s72) and processors

| Provider | Data | Where | Note |
|---|---|---|---|
| OpenStreetMap tile servers (default map tiles) | the viewer's IP and the map area | OSMF, UK/EU + CDN | browser request; owner may switch to a South African or self-hosted tile source (`EHAIL_TILES_URL`) |
| Nominatim (default geocoder, server-side) | typed address searches, reverse-geocoded pickup/drop-off coordinates | OSMF, UK | proxied by the server (no user IP); owner decision on a local geocoder |
| Optional routing/geocoding (Google, Mapbox, ORS, OSRM, Valhalla, Photon) | coordinates | US/DE or self-hosted | off by default; configure only with a processor agreement |
| Paystack | payer email, amount, reference; payout recipient name, account number, bank | Paystack (Stripe group); card entry on Paystack's hosted page | operator agreement with Paystack covers processing |
| BulkSMS (when configured) | trusted-contact phone numbers, SOS text with an approximate location link | provider's region | safety category only |
| Web Push services (FCM, Mozilla, Apple) | encrypted payloads only | US and others | content end-to-end encrypted |
| Mail (SMTP from env), Dot.Notify, InfoDot SSO, Dot.Analytics | email and notice content; identity; aggregates only | Dot infrastructure | same responsible party group |

Owner step: confirm s72 grounds (adequate protection or consent or contract necessity) for OSM/Nominatim and any configured provider; already listed as open in `docs/R46_RESEARCH.md`.

## 10. Security safeguards (s19) and breach response (s22)

Safeguards: `docs/R46_SECURITY.md` (enforced CSP, role matrix, encryption at rest, private files, rate limits, recent-sign-in for sensitive changes, immutable ledger, audit log).

Breach response hooks as built:
- Detection signals: `csp.violation` log lines; finance issues (duplicate payout accounts, duplicate payments, cash discrepancies); new-device sign-in notices to holders; audit log of operator views; failed-login rate limiting.
- Scoping: the audit log answers "who opened which record when"; `ehail:privacy-purge --dry-run` and the data export show what a given person's record contains.
- Containment: SECURITY_CSP_REPORT_ONLY stays false; operator access is removed through `/ops/team` (assignments end with it, S-06); InfoDot password change ends other sessions (AuthenticateSession).
- Owner steps (not automatable here): notify the Information Regulator and affected people "as soon as reasonably possible" (s22) using the Regulator's prescribed form; keep a breach register; rotate APP_KEY-derived secrets only with a re-encryption plan (encrypted columns depend on it).
