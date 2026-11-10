# R-46 fleet and transport operator management (brief section 15)

Owner: fleet agent (wave 2), branch `feat/r46-fleet`, decisions D-1200 to D-1216 in `R46_DECISIONS.md`, skills record in `R46_SKILLS.md`, screenshots in `docs/R46_screens/fleet/` (`<page>-<1440|390>-<light|dark>.jpg`).

## 1. What a fleet is

Every driver profile already belonged to a fleet (`driver_profiles.fleet_id` is NOT NULL); an independent driver applying with a new fleet name creates their own. R-46 makes that explicit:

- `fleets.kind`: `independent` (a fleet of one: the portal works without managers, applications or assignments; nothing is required that is not used) or `company`. Existing fleets were classified by migration: independent when the owner is the only driver.
- Business profile: trading name, CIPC registration number, contact email and phone, base city. Status `pending` until a platform operator approves (existing vetting, `operator.fleets.*`); `approved_at` is stamped then.
- Operating licences are per vehicle in South Africa: the verification domain's `operating_licence` document (subject vehicle) is part of every vehicle's documents.

## 2. Code

| Path | What |
|---|---|
| `app/Domain/Fleet/FleetAccess.php` | who may see/do what, the current fleet (`?fleet=` remembered in the session), isolation queries |
| `app/Domain/Fleet/VehicleAssignments.php` | permits, assign (compare-and-swap holder + unique open assignment), take back, in-use check |
| `app/Domain/Fleet/FleetVehicles.php` | register, edit (doors, features), retire, out of service / back in service, maintenance records, next service |
| `app/Domain/Fleet/FleetAwareDriverEligibility.php` | decorates verification's go-online gate |
| `app/Domain/Fleet/DriverApplications.php` | approve / decline applications (shared with the legacy URLs) |
| `app/Domain/Fleet/FleetRegistration.php` | register, profile, managers and their areas, identity-review rights |
| `app/Domain/Fleet/{DocumentOverview,FleetEarnings,FleetPerformance,FleetAlerts,FleetTrips}.php` | read models |
| `app/Domain/Fleet/FleetPayeeResolver.php` | money's `PayeeResolver` with the per-fleet arrangement |
| `app/Domain/Fleet/Seams/VehicleDocumentCustody.php` | vehicle documents follow the vehicle (bindIf; default `RehomeVehicleDocuments`) |
| `app/Domain/Fleet/Listeners/*` | online-time sessions; take a driver offline when a trip in an out-of-service vehicle ends |
| `app/Providers/EhailFleetServiceProvider.php` | bindings, the eligibility decoration, model hooks, listeners, limiter `fleet-writes` |
| `app/Http/Controllers/Fleet/*`, `routes/ehail/fleet.php` | portal |
| `resources/views/fleet/**`, `resources/views/components/fleet/page.blade.php`, `resources/css/fleet/fleet.css` | UI |
| `config/ehail_fleet.php` | alert thresholds |
| `database/migrations/2026_11_10_800000_create_fleet_management_tables.php` | additive schema |
| `database/seeders/DemoFleetSeeder.php` | dev-only demonstration fleet (refuses production) |

Tables: `fleet_members`, `fleet_vehicle_permits`, `fleet_vehicle_assignments`, `vehicle_maintenance_records`, `driver_online_sessions`; additive columns on `fleets` and `vehicles` (`fleet_id`, `owned_by`, `doors`, `features`, `odometer_km`, `out_of_service_*`, `retired_at`); `vehicles.driver_profile_id` made nullable.

## 3. Driver-vehicle assignment (custody model, D-1200..D-1204)

- A fleet-held vehicle (`owned_by = fleet`) is held by at most one driver: `vehicles.driver_profile_id`. Dispatch, verification and the accept check already demand that a driver holds the vehicle they drive, so no other domain changed.
- Permits say who may drive which fleet vehicle. Only an approved driver of this fleet with a permit can be given the vehicle.
- Assigning: vehicle row locked (PostgreSQL/MySQL), holder changed by compare-and-swap, an open assignment row with unique `active_vehicle_id` (one active driver per vehicle on every engine), the vehicle's documents re-pointed to the new holder, audit row. It becomes the driver's active vehicle only if they have none and are not online.
- Never while the current holder is online, paused or on a trip in it, or a ride in it is live (409 `vehicle_in_use`).
- A driver's own car (`owned_by = driver`) is never assignable; the fleet records its doors, features and maintenance.
- Concurrency proven with forked processes on one SQLite file (`AssignmentConcurrencyTest`): two managers giving one vehicle to two drivers, one driver given two vehicles at once.

## 4. Maintenance and the go-online gate

Records: kind (service, repair, inspection, tyres, other), date, odometer (only moves forward), description, cost in minor units, next service by date and/or kilometres. Next service = the latest record that sets one; overdue / due within 14 days or 1 000 km.

Out of service: blocks going online in that vehicle (`vehicle_out_of_service`, also `vehicle_retired` and `fleet_vehicle_unassigned`) through the decorated `Verification\DriverEligibility`, which dispatch's `AvailabilityService::goOnline` (via the integration adapter) and the `DriverProfile` is_online guard both use. A driver online in it is taken offline at once; one on a trip is taken offline when the trip ends. Verified end to end: `POST /api/ehail/driver/online` answers 403 with the reason, and succeeds again after "Return to service".

## 5. Permissions (brief 15.3)

| Who | Sees | Changes |
|---|---|---|
| Owner | every area of their fleets | everything, plus managers and settings |
| Manager | only the areas given (drivers, vehicles, maintenance, documents, earnings, trips) | within those areas; never members or settings |
| Platform operator | every area of every fleet (read) | nothing in the portal; legacy application decisions; identity-review rights |
| Anyone else | nothing (404) | nothing |

- Isolation: every query is scoped to `fleet_id` (profiles, vehicles, earnings) or to the fleet's drivers and vehicles (trips). Objects of another fleet 404 even by id.
- Passengers appear by first name only; no addresses or positions (D-1206).
- Identity: status only by default. Masked numbers, the legacy full-number driver page and legacy identity files open for an owner only after an operator grants identity-review rights; managers never (D-1205). Verification documents stay closed to fleets (safety D-503).
- Pending fleet: the owner sees no other driver's records until approved (D-1208).

## 6. Earnings (from the ledger)

Per period (day/week/month, moving back and forward), per driver and per vehicle (the trip's vehicle), from `driver_earnings` rows recorded for the fleet; CSV export (formula-neutralised). Ledger check: `fleet_payable:{fleet}` against unpaid fleet-owner earnings, each driver's `driver_payable` against their unpaid earnings when all of them are in this fleet; any difference is shown as "Does not match". Payee arrangement per fleet (`payee_mode`), commission from pricing's commission plans (fleet plan, else market plan). There is no fleet-to-driver split inside Dot.Ehail; the page says so. `FleetEarningsTest` completes trips through the real lifecycle and money settlement and asserts the statement equals the ledger.

## 7. Performance and alerts

Factual only (D-1211, D-1212): trips, driver cancellations of held rides, completion rate, acceptance rate (offers), rating band (never a decimal), time online measured from this release (shown "Not measured" before). Alerts per page load: documents blocking or expiring within 30 days (one alert per driver or vehicle), service overdue/due soon, out of service, driver offline > 24 h holding a fleet vehicle, unusual cancellations (3+ and 20 %+ in 7 days).

## 8. Portal

`/fleet` overview ("N of M vehicles can work now; K cannot: why"), `/fleet/vehicles` (+ add, vehicle page with who drives it, documents, maintenance, ride categories it qualifies for via the catalogue, details, retire), `/fleet/drivers` (applications, drivers with performance, driver page), `/fleet/maintenance`, `/fleet/documents`, `/fleet/earnings` (+ CSV), `/fleet/trips`, `/fleet/alerts`, `/fleet/settings` (profile, payee, managers), `/fleet/register`. Legacy `/fleets/{fleet}` and its approve/reject posts keep working, now in the fleet shell. Navigation lists all nine areas (three lines added to `Navigation::SURFACES`). Forms are plain POST forms (no script needed); secondary actions are inline disclosures.

Browser walkthrough (headless Chrome through Playwright, own profile, after merging `feat/r46-ehail`; `DemoFleetSeeder` data, dev login): every page loaded at 1440 and 390 in light and night (52 captures), no console errors from fleet pages, no horizontal overflow. Actions clicked at 1440, each answered with its confirmation: return GP 88 RD to service; give GP 88 RD to Thabo (allowed driver, already online in another car, so it stays inactive and the overview says why); take GP 72 KL out of service with a reason; record a repair with odometer and cost; decline an application with a reason; download the earnings CSV; add a manager by email; filter trips by driver (3 rows). At 390: return GP 72 KL to service, open the navigation drawer. Captures `home-after-actions-*` and `nav-drawer-390-light` show the result. The same flows are covered by feature tests.

## 9. Tests

`tests/Feature/Fleet/`: `FleetIsolationTest` (matrix: owner, other owner, manager by area, revoked manager, operator read-only, stranger, pending fleet, first names only, identity status only), `VehicleAssignmentTest`, `AssignmentConcurrencyTest` (forked processes), `MaintenanceEligibilityTest` (gate, guard, dispatch API, offline after trip, next service, alerts), `FleetEarningsTest` (statement = ledger, mismatch shown, payee arrangement, isolation), `FleetPortalTest` (registration, legacy routes, applications, managers, settings, navigation, online time, cancellations). Existing tests changed for D-1205 are listed there.

## 10. Not built, and why (owner or other domains)

- **Vehicle document upload by the fleet.** VerificationService::submit requires the uploader to hold the vehicle and records them as owner; uploads come from the holding driver (driver app). A fleet-manager upload path needs a safety-owned seam (D-1213).
- **Safety: key vehicle documents by vehicle** instead of (holder, vehicle); then `VehicleDocumentCustody` can be bound to a no-op.
- **Ops:** an identity-review toggle on the ops fleets page (`POST /fleet/ops/{fleet}/identity-review` exists); fleet suspension.
- **Money:** `DriverActivitySource::onlineSeconds` could read `driver_online_sessions` from now on (integration D-709 left it unmeasured).
- **Legacy driver page** (`/drivers/{id}`) still shows passenger names and pickup/drop-off addresses to whoever may open it; for fleet owners that is now only with identity-review rights. The driver app owner should trim it.
- No invitations by email for managers (accounts come from InfoDot); no fleet-to-driver commission split; no automatic vehicle feature verification (features are the fleet's declaration).
