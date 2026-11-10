# R-46 comms: notifications and communication (brief section 17)

Owner: comms agent (wave 2), branch `feat/r46-comms`, decisions D-1300 to D-1316 in `R46_DECISIONS.md`. Code: `App\Domain\Comms\*`, `App\Http\Controllers\Ehail\Comms\*`, `App\Providers\EhailCommsServiceProvider`, `config/ehail_comms.php`, `routes/ehail/comms.php`, `resources/views/comms/**`, `resources/css/comms/comms.css`, `resources/js/comms/comms.js`, the push handler appended to `public/sw.js`. Migration `2026_11_10_800000_create_comms_tables`.

## 1. Model in one paragraph

Every notification is written to the in-app inbox first (Laravel `notifications` table, the same record the existing bell reads). Push, email and SMS are copies of that record, each one a row in `notification_deliveries` (unique per event key, person and channel) with its real outcome: `queued`, `sending`, `sent` (the channel accepted it), `failed`, or `skipped` with a reason. Those rows are also the work queue. Content is never stored twice: the worker reads the title, text and link from the in-app record.

## 2. Event to notification matrix

| Event (source) | Who | Type | Category | Channels besides in-app |
|---|---|---|---|---|
| `DriverAssigned` (dispatch) | passenger | `driver_assigned`, or `scheduled_driver_confirmed` for a booked ride | trip / scheduled | push, email (Dot.Notify sends `ride.accepted` when on) |
| heartbeat within `EHAIL_COMMS_APPROACHING_M` (500 m) of pickup, once per assignment (`ApproachWatcher` on `driver_availability` updates) | passenger | `driver_approaching` | trip | push, email |
| `DriverArrived` | passenger | `driver_arrived` | trip | push, email |
| `TripStarted` | passenger | `trip_started` | trip | none (in-app only: they are in the car) |
| `TripCompleted` | both | `ride_completed` (existing `RideCompletedNotification`, fanned out) | trip | push (not to the driver: their own action), email |
| cancelled, requeued, expired, no-show (existing `RideNotices`) | the other party | `ride_cancelled`, `ride_requeued`, `ride_expired`, `ride_no_show` | trip | push, email (Notify precedence for cancel/expire) |
| scheduled ride created (`Ride` created in `scheduled`) | passenger | `scheduled_booked` ("awaiting driver") | scheduled | email (no push: they just booked it) |
| `ScheduledRideReminderDue` | passenger | `scheduled_reminder` | scheduled | push, email |
| `ScheduledRideRequoteRequired` | passenger | `scheduled_requote_required` (new and booked fare) | scheduled | push, email |
| `Receipt` created (money) | passenger | `payment_receipt` ("Payment received", receipt number, link) | payments | push; email is money's own receipt mail, recorded against the same event key |
| card declined, paid, refunded, waived (existing) | passenger / driver | `ride_payment_failed`, `ride_paid`, `ride_refunded`, `ride_fare_waived` | payments | push, email |
| `Payout` status to paid / failed | payee | `payout_paid`, `payout_failed` (with the reason) | earnings | push, email |
| `IncentiveProgress` status to earned | driver | `incentive_earned` | earnings | push, email |
| `RideOffer` created while the driver is online | driver | `offer_received` (replaces the previous unread offer; marked read once the driver is assigned) | offers | none, ever |
| verification reviewed, expiring, expired (existing) | driver | `verification_document_*` | driver_account | push, email (Notify precedence) |
| safety: operator alert, incident acknowledged/resolved, check-in (existing) | operators / reporter / riders | `safety_*` | safety (critical) | push, email, SMS; sent at once, not at the next cron minute |
| support reply, relay, escalation, urgent (existing) | requester / operators | `support_*` | support | push, email |
| rating appeal decided, report reviewed (existing) | the person | `rating_appeal_decided`, `user_report_reviewed` | account | push, email |
| driver application submitted (existing) | fleet owner | `driver_application_submitted` | fleet_admin | push, email |

Types not in `config('ehail_comms.types')` fall into `general`. `tests/Feature/Comms/NotificationMatrixTest` drives each row through the real dispatch, state machine and models.

## 3. Channels

- **In-app:** always on; the inbox (`/ride|drive|ops/notifications`, JSON `GET /api/ehail/comms/inbox`) shows only the person's own real items, with each copy's outcome in words ("Email: not delivered (this service only logs email)"). Links are only ever paths on this site.
- **Email:** Laravel mail (`CommsMail`, markdown view `comms.mail.notification`). If `MAIL_MAILER` is `log` or `array`, nothing is sent and the row is `skipped / mail_transport_not_delivering`; the settings page says "Not delivered". `sent` only when the mailer accepted it (provider `mail:<mailer>`, message id kept).
- **Web Push:** RFC 8030 + 8291 (aes128gcm) + 8292 (VAPID), written on PHP's openssl (D-1301), verified byte for byte against RFC 8291 Appendix A. Subscriptions: one row per endpoint (encrypted at rest, endpoint hashed for uniqueness), moved to whichever account subscribes last on that browser, revoked on "turn off", on 404/410 from the push service, and when more than 10 devices. Endpoints must be https on a known push service (`EHAIL_PUSH_ALLOWED_HOSTS`), so a forged subscription cannot make the server call any other host. `Topic` header (hash of the collapse key) lets the push service replace an undelivered older update; the service worker shows one notification per `tag`. Unsupported browsers and iPhone Safari outside the home screen get an exact sentence, not a dead switch.
- **SMS:** through safety's `SmsGateway` only. Only critical (safety) categories, only to a number the person confirmed with a 6-digit code sent by SMS (HMAC stored, 10 minutes, 5 attempts, 1 per minute, 5 per hour). With no provider configured the number field is not shown at all (no data collected for nothing) and rows say `sms_not_configured`.
- **Dot.Notify:** existing client, off unless `NOTIFY_INGEST_URL` and `NOTIFY_INGEST_SECRET` are set. **Precedence (D-1305): when Notify is on, it owns email for every event in its catalogue; comms' own mail never sends a second copy** (`skipped / sent_by_dot_notify`). A source notification declares its Notify event through `toNotifyEvent()`; comms' own types list it in `notify_handled_types`. A person's explicit "email off" now also stops Notify (`NotifyClient::queue` checks it); defaults never do, so pre-R-46 behaviour is unchanged until someone chooses.

## 4. Rules that keep it quiet and correct

- Idempotency: `insertOrIgnore` on `(event_key, user_id, channel)`; the in-app record is only written when its row is new. Doubled domain events, retried jobs and a second cron run send nothing more (tested).
- Own action: no push or SMS to the person who caused the event (the passenger who booked, the driver who completed). Not "whoever made the request": dispatch moves rides on during anyone's poll.
- Approaching: once per assignment, by distance only (no ETA claimed).
- Collapse: an older queued push about the same ride is skipped as `superseded` when a newer one exists; trip pushes older than 30 minutes are `expired`; ride offers replace the previous unread offer.
- Preferences: per category and channel (push, email), defaults in config; safety cannot be switched off (422 `locked`, shown as facts with the reason, not as disabled switches).
- Delivery: `DeliverNotifications` job after commit (critical: after the response, or in place in a console run); `ehail:comms-deliver` every minute picks due and abandoned rows (lease 120 s, CAS claim); retries at 60 s, 5 min, 15 min, 1 h, then `failed`. `ehail:comms-prune` daily deletes records older than 90 days (`EHAIL_COMMS_RETENTION_DAYS`) and long-revoked subscriptions.
- Rolled-back transitions notify nobody (listeners run after commit; tested).

## 5. UI and seams for other agents

Pages (in the shells, working without script except the push and SMS controls, which say so in `<noscript>`): `ride.notifications`, `ride.notifications.settings`, `drive.notifications`, `drive.notifications.settings`, `ops.notifications`, `ops.notifications.settings` (operator only); `/notifications` and `/notifications/settings` (`comms.inbox`, `comms.settings`) redirect to the person's primary surface.

Includes (anonymous components, `resources/views/comms/components`):
- `<x-comms::bell surface="passenger|driver|ops" />`: top-bar link with the unread count. **For ds / passenger / driver / ops:** put it in the shell's top-bar slot (next to the account menu) and load `resources/css/comms/comms.css` (one `@vite` entry, 1.4 KB gzip). Not added to the ds layouts by this branch (not comms-owned).
- `<x-comms::inbox-list :items="$items" route-prefix="ride" />` with `$items` from `App\Domain\Comms\Inbox::present()`.
- `<x-comms::shell :surface :title>` wraps a page in the right shell with the comms assets.

JSON API (`/api/ehail/comms`, auth, throttled): `GET inbox` (`per_page`, `unread`, `cursor`), `GET inbox/summary`, `POST inbox/{id}/read`, `POST inbox/read-all`, `GET|PUT preferences` (`{choices: [{category, channel, enabled}]}`), `GET push/key`, `POST|DELETE push/subscriptions`, `POST push/status`, `POST push/test` (sends to your own devices now and reports what each push service said), `POST|DELETE sms/number`, `POST sms/confirm`.

Seam for other domains: write any in-app notification as before (`$user->notify(...)` with `type`, `title`, `message`, `url` in `toDatabase`) and add its `type` to `config('ehail_comms.types')`; comms fans it out. Or send directly: `app(NotificationRouter::class)->send(new CommsMessage(...))`.

## 6. Owner steps

1. Push: `php artisan ehail:comms-vapid-keys`, put the three lines in the host `.env` (never commit). Changing keys later means every device turns push on again.
2. Mail: a real `MAIL_MAILER` (SMTP via no-reply@infodot.co.za). Until then every email row says not delivered, and the screen says so.
3. SMS: safety's BulkSMS settings (`EHAIL_SMS_DRIVER=bulksms`, token id and secret).
4. Cron: the existing `schedule:run` and `queue:work --stop-when-empty` every minute are enough.
5. Dot.Notify: unchanged; if turned on, the precedence rule above applies.

## 7. Verification

- PHP: 1166 tests after merging the integrated trunk (1158 pass, 8 skipped, 0 failures). New: `tests/Feature/Comms/{NotificationMatrixTest,DeliveryTest,PreferencesAndInboxTest,CommsPagesTest}`, `tests/Unit/Comms/PayloadEncrypterTest` (41 tests). Changed: `RideLifecycleTest::test_completing_a_ride_notifies_the_passenger_and_driver` now expects the passenger's trip updates beside the completion notice (legitimate behaviour change).
- JS: `tests/js/comms.test.mjs` (3): push support states (incl. iPhone outside the home screen), VAPID key decoding. Suite 28 -> 31.
- `npm run build`, `php artisan view:cache`, `php artisan route:cache` pass.
- Browser (dev server 8615, seeded dev user, notifications written through the real router): passenger inbox at 390 light (stops, safety stop, outcomes, Open and Mark read), settings at 390 light (channel statuses, push blocked by the browser shown honestly, safety locked as facts, Save), driver settings at 1440 night (toggled a switch, word changed to On, saved, row persisted), ops inbox at 1440 light (Mark read), driver inbox at 390 night. The embedded browser denies notification permission, so a real push to a device was not exercised; the push path is covered by tests against a faked push service with the body decrypted using the browser key.

## 8. Limits (honest)

- No real device received a push in this run (see above). Apple's push service is in the allow list; it was not called.
- `taste inspect.mjs` was not run: the pages need a signed-in session the inspector cannot carry. Checked by eye in the browser instead; `lint.mjs` and `impeccable detect` are clean.
- Notifications created in the same second may list in either order (the notifications table has random UUIDs).
- No quiet hours, digest emails, or per-device category choices; no marketing messages exist and none are sent.
- The legacy Livewire bell (old app shell) is unchanged; it reads the same records.
