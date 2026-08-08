# Dot.Ehail: Fleet/Operator Entity + Driver Application Approval Gate

## Context

Dot.Ehail's autonomy classification audit (`Dot.Brain/platforms/dot-ehail.md`, 2026-08-08) found the driver-application review workflow described in `DriverApplicationSubmittedNotification`'s own docblock is real code wired to nothing. Direct inspection of the real codebase (`~/Dot/Dot.Ehail`) found the gap is deeper than a missing approval step: **no code anywhere creates a `DriverProfile`** — not a controller, not a Livewire component, not a seeder. There is no driver-application submission flow at all today.

`wiki.md` documents why, honestly: *"Fleet/Operator as a first-class entity distinct from a Jetstream `Team` does not exist in code yet"* and a prior pass *"left `DriverApplicationSubmittedNotification` unwired and documented why (no operator/team relation exists on `DriverProfile` to notify) rather than fabricating one."* This spec builds the entity that prior pass correctly declined to fabricate, plus the submission flow and the approval gate it unblocks — three pieces, because none of the later two can be honest without the first.

`driver_profiles.status` is currently an enum `['pending', 'approved', 'suspended']` (no rejected state) with no reviewer/reason tracking. `DriverProfilePolicy::view` currently restricts viewing a driver profile to the driver themselves — no operator can see one at all today.

## Goal

A user can apply to drive, either joining an existing fleet or starting their own (matching `wiki.md` §1's own framing: *"anywhere from a single owner-driver to a multi-vehicle fleet company"*). The fleet's owner receives a real notification and can approve or reject the pending application from a real review screen — approving requires nothing new, rejecting requires a reason. Nothing about ride-taking, dispatch, or existing driver profiles changes.

## Changes

### 1. `Fleet` — the first-class Operator entity

New model, new table `fleets`: `id`, `name` (string), `owner_user_id` (FK `users.id`, `cascadeOnDelete`), timestamps. `Fleet::owner(): BelongsTo` (User), `Fleet::driverProfiles(): HasMany` (DriverProfile). The user who creates a fleet is its operator — no separate admin/member roster is built here (YAGNI: nothing today needs more than one owner per fleet; a real multi-admin fleet-staff model is a future extension, not fabricated here).

### 2. `driver_profiles` gains `fleet_id`, `rejected`, and reviewer tracking

Migration: add `fleet_id` (FK `fleets.id`, `cascadeOnDelete`, **not** nullable — every driver profile belongs to exactly one fleet, even a single-owner-driver's fleet of one), extend the `status` enum to `['pending', 'approved', 'suspended', 'rejected']`, add `rejected_reason` (nullable text), `reviewed_by` (nullable FK `users.id`), `reviewed_at` (nullable timestamp). `DriverProfile::$fillable` and a new `DriverProfile::fleet(): BelongsTo` relation updated to match.

### 3. Driver application submission (`DriverApplicationController`)

New controller, `app/Http/Controllers/Ehail/DriverApplicationController.php`, matching this app's existing plain-controller convention (`DriverController`, `RideController` — not Livewire; this app has exactly one Livewire component, `NotificationBell`, and everything else is controllers):

- `create(): View` — the apply form. Route `GET /drive/apply`, name `drive.apply`, behind the existing `auth:sanctum` + session + `verified` middleware group. Shows a dropdown of existing `Fleet::all()` to join, or a "Start my own fleet" text input for a new fleet name (mutually exclusive — the form validates exactly one is filled).
- `store(Request $request): RedirectResponse` — Route `POST /drive/apply`, name `drive.apply.store`, same middleware. Validates: `license_number` (required, string, unique on `driver_profiles`), `id_number` (required, string, unique), `existing_fleet_id` (nullable, exists:fleets,id), `new_fleet_name` (nullable, string, required_without:existing_fleet_id), vehicle fields `vehicle_make`/`vehicle_model` (required strings), `vehicle_year` (required integer), `vehicle_color` (required string), `vehicle_plate_number` (required, unique on `vehicles`), `vehicle_type` (required, in: economy,standard,premium,suv). If `new_fleet_name` was submitted, creates a `Fleet` with `owner_user_id = auth()->id()` first; otherwise uses `existing_fleet_id`. Creates one `DriverProfile` (`status: 'pending'`, the resolved `fleet_id`, `user_id: auth()->id()`) and one `Vehicle` linked to it. Fires `DriverApplicationSubmittedNotification` to the fleet's `owner` (this is the real trigger the notification's docblock said didn't exist yet — update that docblock as part of this change, it's now inaccurate). Redirects to the new `drivers.show` page for the driver's own profile with a success flash message.

Every existing user who already has a `user_id` used as a `DriverProfile.user_id` is untouched — `driver_profiles.user_id` stays unique (already enforced), so a user can only ever have one driver application/profile, matching current behavior.

### 4. Review screen (`FleetController`)

New controller, `app/Http/Controllers/Ehail/FleetController.php`:

- `show(Fleet $fleet): View` — Route `GET /fleets/{fleet}`, name `fleets.show`. Authorization: only `auth()->id() === $fleet->owner_user_id` (new `FleetPolicy::view`, following this app's existing single-purpose-policy convention — see `DriverProfilePolicy`'s own docblock precedent for why every direct-access route needs an explicit policy check, not an implicit one). Lists the fleet's `driverProfiles` split into pending (with vehicle + user info to review) and already-reviewed (approved/suspended/rejected).
- `approveDriver(Fleet $fleet, DriverProfile $driverProfile): RedirectResponse` — Route `POST /fleets/{fleet}/drivers/{driverProfile}/approve`. Same `FleetPolicy::view`-equivalent authorization (only the fleet owner). Refuses (422 + flash error, redirect back) unless `$driverProfile->fleet_id === $fleet->id` and `$driverProfile->status === 'pending'`. Sets `status: 'approved'`, `reviewed_by: auth()->id()`, `reviewed_at: now()`.
- `rejectDriver(Fleet $fleet, DriverProfile $driverProfile, Request $request): RedirectResponse` — Route `POST /fleets/{fleet}/drivers/{driverProfile}/reject`. Same checks as approve, plus validates `reason` as `required|string` — rejecting with an empty reason is a validation failure, not silently accepted. Sets `status: 'rejected'`, `rejected_reason`, `reviewed_by`, `reviewed_at`.

### 5. `DriverProfilePolicy::view` extended

A fleet owner can now also view a pending/reviewed driver profile that belongs to their fleet (previously: only the driver themselves, ever, full stop). Add: `|| $user->id === $driverProfile->fleet->owner_user_id` to the existing check. This is additive to the 2026-08-01 security fix documented in that policy's own docblock, not a reversal of it — a stranger still cannot view an unrelated driver's profile; only that driver's own fleet owner gains access, and only because they now have a real reason to (reviewing the application, seeing ride history/rating of their own fleet's driver).

## Testing

New test files, matching this app's existing PHPUnit + `RefreshDatabase` conventions (see `RideObserverTest` for the house style referenced in `wiki.md`'s own changelog):

- `tests/Feature/DriverApplicationTest.php` — applying with a new fleet name creates both the `Fleet` (owner = applicant) and a `pending` `DriverProfile` + `Vehicle`; applying to an existing fleet does not create a second fleet; applying with both `existing_fleet_id` and `new_fleet_name` set, or neither, fails validation; a user who already has a `DriverProfile` cannot submit a second one (DB unique constraint surfaces as a validation/500 — assert the second attempt does not create a second row); the fleet owner receives a real `DriverApplicationSubmittedNotification` (assert via `Notification::fake()`/`assertSentTo`, not manual dispatch).
- `tests/Feature/FleetDriverReviewTest.php` — the fleet owner can approve a pending driver in their fleet; the fleet owner can reject with a reason; rejecting without a reason is blocked (422, status stays `pending`); a non-owner (including another fleet's owner) cannot view the fleet's review screen or approve/reject its drivers; approving/rejecting an already-decided driver profile is refused (status doesn't change).
- Existing `DriverProfilePolicy`/`DriverController` tests (if any) re-run to confirm the `view` extension doesn't regress the 2026-08-01 fix — a stranger (not the driver, not their fleet owner) still gets denied.

## Explicitly out of scope

- Multi-admin fleets (more than one user who can review applications for a fleet) — YAGNI until a real need appears.
- Editing/withdrawing a submitted application — only submit, approve, reject exist.
- Suspending an already-approved driver, or un-rejecting a rejected one — those transitions already exist in the `status` enum's design intent but have no UI today, on either side of this change; not this spec's concern.
- Notifying the applicant of the approve/reject decision — only the fleet owner gets notified (of the new application); the applicant finding out by revisiting their own `drivers.show` page is the honest current state, not silently expanded.
- Fixing the dashboard's own missing operator gate (`/dashboard` is documented in its own code comment as "a platform-wide ops view" but has no authorization restricting who can see it) — a real, separate finding, flagged here rather than silently fixed as a scope-creep bystander change.
- Registering this change in Dot.Brain's `platforms/dot-ehail.md` or `platforms/autonomy-signals.json` — a separate, future re-audit pass, not part of building the feature.
