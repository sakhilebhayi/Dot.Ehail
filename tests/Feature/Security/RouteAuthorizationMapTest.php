<?php

namespace Tests\Feature\Security;

use App\Domain\Business\Models\BusinessMember;
use App\Domain\Business\Statements;
use App\Domain\Dispatch\Models\RideOffer;
use App\Domain\Earnings\Models\DriverEarning;
use App\Domain\Earnings\Payouts\PayoutAccounts;
use App\Domain\Earnings\Payouts\PayoutService;
use App\Domain\Ops\Access\RoutePermissions;
use App\Domain\Payments\Models\PaymentMethod;
use App\Domain\Payments\Models\Receipt;
use App\Domain\Safety\IncidentKind;
use App\Domain\Safety\IncidentService;
use App\Domain\Safety\Models\TrustedContact;
use App\Domain\Safety\TripShareService;
use App\Domain\Support\SupportCaseService;
use App\Domain\Support\SupportCategory;
use App\Domain\Verification\Models\VerificationDocument;
use App\Models\DriverDocument;
use App\Models\Ride;
use App\Models\RideRating;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Concerns\BusinessFixtures;
use Tests\Concerns\FleetFixtures;
use Tests\Concerns\MoneyFixtures;
use Tests\TestCase;

/**
 * R-46 security, scope item 1: authorisation checked mechanically across the whole router.
 *
 *  1. Every route is classified (public / signed-in / operator-with-a-mapped-permission); a new route that is none
 *     of these fails the test, and every signed-in route with an id must say who owns it (OWNERS below).
 *  2. A guest gets a sign-in answer from every non-public route, never the resource.
 *  3. Cross-tenant: a signed-in attacker who is themselves a passenger, a verified driver, a fleet owner and a
 *     business admin calls every owned route with the VICTIM's ids. Reads must be refused (403/404, or a redirect
 *     away); writes must not succeed (no 2xx, no 5xx). The owner, for reads, gets the page (the ids are real).
 *
 * Nested ids are covered by the same run (a ride's offer, a fleet's vehicle with another fleet's driver profile,
 * a business's member/department/policy/approval/statement).
 */
class RouteAuthorizationMapTest extends TestCase
{
    use BusinessFixtures, FleetFixtures, MoneyFixtures, RefreshDatabase;

    /** Framework and package routes (their own protections), and the dev-only Boost logger. */
    private const VENDOR_PREFIXES = ['_boost', 'livewire', 'sanctum', 'storage', '_ignition'];

    /** Reachable without signing in, with why. Anything else unauthenticated fails classification. */
    private const PUBLIC = [
        '/' => 'landing', 'login' => 'sign-in', 'register' => 'closed in production (RegistrationClosedTest)',
        'forgot-password' => 'reset request', 'reset-password' => 'reset (token)', 'reset-password/{token}' => 'reset (token)',
        'two-factor-challenge' => 'second factor', 'terms-of-service' => 'legal', 'privacy-policy' => 'legal', 'cookies' => 'legal',
        'fares' => 'public fare calculator', 'fare-estimate' => 'anonymous estimate, not bookable', 'fare-estimate/categories' => 'public catalogue',
        't/{token}' => 'trip share (256-bit token)', 't/{token}/status' => 'trip share (256-bit token)',
        'safety/contact/confirm/{token}' => 'trusted-contact answer (token)', 'safety/contact/decline/{token}' => 'trusted-contact answer (token)',
        'safety/contact/stop/{contact}' => 'signed URL (ValidateSignature)', 'csp-report' => 'browser reports', 'webhooks/paystack' => 'HMAC-signed',
        'up' => 'health', 'up/realtime' => 'health (ok|degraded only)', 'auth/ecosystem' => 'InfoDot SSO (origin-checked, single-use token)',
        'broadcasting/auth' => 'channel callbacks decide (BroadcastChannelAuthorizationTest)', 'api/user' => 'sanctum-authenticated',
        'manifest.webmanifest' => 'static', 'offline' => 'static',
    ];

    /**
     * Signed-in routes with an id: who owns the record. 'token' and 'enum' parameters carry no record of anyone's.
     * passenger | driver | fleet_owner | business_admin | own (the caller's own row) | token | team.
     */
    private const OWNERS = [
        'ehail.comms.inbox.read' => 'own', 'drive.notifications.read' => 'own', 'ride.notifications.read' => 'own',
        'ehail.dispatch.driver.rides.cancel' => 'driver', 'ehail.dispatch.driver.rides.no-show' => 'driver', 'ehail.dispatch.driver.rides.stage' => 'driver',
        'ehail.dispatch.offers.accept' => 'driver', 'ehail.dispatch.offers.decline' => 'driver', 'ehail.dispatch.offers.seen' => 'driver',
        'ehail.dispatch.rides.cancel' => 'passenger', 'ehail.dispatch.rides.feed' => 'passenger', 'ehail.dispatch.rides.requote.accept' => 'passenger',
        'ehail.dispatch.rides.reschedule' => 'passenger', 'ehail.dispatch.rides.trip-change' => 'passenger',
        'business.approvals.approve' => 'business_admin', 'business.approvals.decline' => 'business_admin', 'business.approvals.withdraw' => 'business_admin',
        'business.departments.update' => 'business_admin', 'business.departments.destroy' => 'business_admin',
        'business.invitations.accept-listed' => 'own', 'business.invitations.show' => 'token', 'business.invitations.accept' => 'token',
        'business.people.update' => 'business_admin', 'business.people.destroy' => 'business_admin', 'business.policies.update' => 'business_admin',
        'business.statements.show' => 'business_admin', 'business.statements.csv' => 'business_admin',
        'drive.api.offer-earnings' => 'driver', 'drive.api.arrived' => 'driver', 'drive.api.rider' => 'driver', 'drive.api.summary' => 'driver',
        'drivers.documents.show' => 'driver', 'drivers.documents.destroy' => 'driver',
        'drive.earnings.bank-account.verify' => 'driver', 'drive.earnings.payout' => 'driver', 'drive.safety.case' => 'passenger',
        'ops.suspension-appeal.store' => 'driver', 'drive.trips.show' => 'driver',
        'drivers.vehicles.update' => 'driver', 'drivers.vehicles.activate' => 'driver', 'drivers.vehicles.deactivate' => 'driver', 'drivers.vehicles.edit' => 'driver',
        'drivers.show' => 'driver', 'drivers.toggle-online' => 'driver',
        'fleet.applications.approve' => 'fleet_owner', 'fleet.applications.reject' => 'fleet_owner', 'fleet.drivers.show' => 'fleet_owner',
        'fleet.settings.managers.update' => 'fleet_owner', 'fleet.settings.managers.destroy' => 'fleet_owner',
        'fleet.vehicles.show' => 'fleet_owner', 'fleet.vehicles.update' => 'fleet_owner', 'fleet.vehicles.assign' => 'fleet_owner',
        'fleet.vehicles.back-in-service' => 'fleet_owner', 'fleet.vehicles.maintenance.store' => 'fleet_owner', 'fleet.vehicles.out-of-service' => 'fleet_owner',
        'fleet.vehicles.permits.grant' => 'fleet_owner', 'fleet.vehicles.permits.revoke' => 'fleet_owner', 'fleet.vehicles.reinstate' => 'fleet_owner',
        'fleet.vehicles.retire' => 'fleet_owner', 'fleet.vehicles.unassign' => 'fleet_owner',
        'fleets.show' => 'fleet_owner', 'fleets.drivers.approve' => 'fleet_owner', 'fleets.drivers.reject' => 'fleet_owner',
        'geo.rides.driver-location' => 'passenger',
        'money.driver.earning' => 'driver', 'money.driver.payout' => 'driver', 'money.driver.cash-received' => 'driver',
        'money.fleets.earnings' => 'fleet_owner', 'money.fleets.earnings.csv' => 'fleet_owner',
        'money.methods.destroy' => 'passenger', 'money.methods.default' => 'passenger', 'money.payout-accounts.verify' => 'driver',
        'money.receipts.show' => 'passenger', 'money.rides.pay-now' => 'passenger', 'money.rides.choice' => 'passenger',
        'pricing.quotes.show' => 'passenger', 'pricing.rides.fare' => 'passenger',
        'ride.api.places.destroy' => 'passenger', 'ride.api.rides.overview' => 'passenger', 'ride.support.show' => 'passenger',
        'ride.safety.incident' => 'passenger', 'ride.trip' => 'passenger',
        'rides.show' => 'passenger', 'rides.accept' => 'driver', 'rides.advance' => 'driver', 'rides.cancel' => 'passenger', 'rides.pay' => 'passenger',
        'rides.pay.callback' => 'passenger', 'rides.ratings.store' => 'passenger',
        'safety.check-ins.respond' => 'passenger', 'safety.contacts.destroy' => 'passenger', 'safety.contacts.resend' => 'passenger',
        'safety.incidents.show' => 'passenger', 'safety.incidents.location' => 'passenger', 'safety.incidents.safe' => 'passenger',
        'safety.ratings.appeal' => 'driver', 'safety.rides.show' => 'passenger', 'safety.rides.driver-photo' => 'passenger',
        'safety.rides.rating' => 'passenger', 'safety.rides.report' => 'passenger', 'safety.rides.shares.store' => 'passenger', 'safety.rides.sos' => 'passenger',
        'safety.shares.revoke' => 'passenger', 'support.attachments.show' => 'passenger', 'support.cases.show' => 'passenger', 'support.cases.reply' => 'passenger',
        'team-invitations.accept' => 'team', 'teams.show' => 'team',
        'verification.documents.show' => 'driver', 'verification.documents.file' => 'driver',
    ];

    /** Reads where a refusal is a redirect into the caller's own area rather than a 403/404. */
    private const REDIRECTING_READS = ['business.statements.csv', 'team-invitations.accept'];

    /** Owner reads that do not answer 200 to the owner for a reason unrelated to authorisation. */
    private const OWNER_READ_EXCEPTIONS = [
        'drivers.documents.show' => 'fixture has no file on disk (404 file missing)',
        'verification.documents.file' => 'fixture has no file on disk',
        'support.attachments.show' => 'the message has no attachment',
        'safety.rides.driver-photo' => 'ride is completed (photo only during an active ride)',
        'geo.rides.driver-location' => 'ride is completed (position only while tracking)',
        'rides.pay.callback' => 'no checkout to confirm (redirects back)',
        'business.statements.csv' => 'download',
        'money.fleets.earnings.csv' => 'download',
        'pricing.quotes.show' => 'no stored quote fixture (QuotesAndRidesTest covers ownership)',
        'drive.api.rider' => 'not a business ride on behalf (404 by design)',
        'teams.show' => 'Jetstream team page',
        'team-invitations.accept' => 'signed URL',
        'drive.safety.case' => 'case is the passenger\'s; the driver sees it as counterparty only',
    ];

    public function test_every_route_is_classified(): void
    {
        $unclassified = [];
        $unowned = [];
        $unmappedOperator = [];

        foreach ($this->appRoutes() as $route) {
            $uri = $route->uri();
            $middleware = implode(',', $route->gatherMiddleware());
            $name = (string) $route->getName();

            if (str_contains($middleware, 'EnsurePlatformOperator') || str_contains($middleware, 'operator')) {
                if (! RoutePermissions::isMapped($name)) {
                    $unmappedOperator[] = $name ?: $uri;
                }

                continue;
            }

            if (str_contains($middleware, 'auth')) {
                if (str_contains($uri, '{') && ! isset(self::OWNERS[$name])) {
                    $unowned[] = $name ?: $uri;
                }

                continue;
            }

            if (! array_key_exists($uri, self::PUBLIC)) {
                $unclassified[] = $route->methods()[0].' '.$uri;
            }
        }

        $this->assertSame([], $unclassified, 'Routes reachable without signing in must be listed in PUBLIC with a reason.');
        $this->assertSame([], $unowned, 'Signed-in routes with an id must say who owns the record (OWNERS).');
        $this->assertSame([], $unmappedOperator, 'Every operator route maps to an operations permission (RoutePermissions).');
    }

    public function test_guests_get_a_sign_in_answer_from_every_non_public_route(): void
    {
        $leaks = [];

        foreach ($this->appRoutes() as $route) {
            if (array_key_exists($route->uri(), self::PUBLIC)) {
                continue;
            }

            $method = $route->methods()[0];
            $url = '/'.ltrim(preg_replace('/\{(\w+)\??\}/', '1', $route->uri()), '/');
            $response = $this->json($method, $url);

            if (! in_array($response->status(), [401, 403, 404, 405], true) && ! ($response->isRedirect() && str_contains((string) $response->headers->get('Location'), 'login'))) {
                $leaks[] = "{$method} {$url} -> {$response->status()}";
            }
        }

        $this->assertSame([], $leaks);
    }

    public function test_a_signed_in_stranger_cannot_use_anyone_elses_ids_on_any_route(): void
    {
        $w = $this->world();
        $attacker = $w['attacker'];
        $failures = [];
        $ownerFailures = [];

        foreach ($this->appRoutes() as $route) {
            $name = (string) $route->getName();
            $owner = self::OWNERS[$name] ?? null;

            if ($owner === null || $owner === 'token') {
                continue;
            }

            $method = $route->methods()[0];
            $url = $this->urlFor($route, $w);

            $response = $method === 'GET' ? $this->actingAs($attacker)->get($url) : $this->actingAs($attacker)->json($method, $url, $this->bodyFor($name));
            $status = $response->status();

            $refused = $method === 'GET'
                ? (in_array($status, [403, 404], true) || ($response->isRedirect() && in_array($name, self::REDIRECTING_READS, true)))
                : ($status >= 300 && $status < 500 && $status !== 405);

            if (! $refused) {
                $failures[] = "{$method} {$url} ({$name}) -> {$status}";
            }

            // The ids are real: the owner reads the same URL.
            if ($method === 'GET' && ! isset(self::OWNER_READ_EXCEPTIONS[$name])) {
                $actor = $w['owners'][$owner];
                $ownerStatus = $this->actingAs($actor)->get($url)->status();
                if ($ownerStatus !== 200) {
                    $ownerFailures[] = "{$url} ({$name}) as {$owner} -> {$ownerStatus}";
                }
            }
        }

        $this->assertSame([], $failures, 'Cross-tenant access must be refused.');
        $this->assertSame([], $ownerFailures, 'Owners must reach their own records (the fixture ids are meaningful).');
    }

    /** @return list<RoutingRoute> */
    private function appRoutes(): array
    {
        return collect(Route::getRoutes()->getRoutes())
            ->reject(fn (RoutingRoute $r) => Str::startsWith($r->uri(), self::VENDOR_PREFIXES))
            ->values()->all();
    }

    /** @return array<string, mixed> */
    private function bodyFor(string $name): array
    {
        return match (true) {
            str_contains($name, 'trip-change') => ['kind' => 'stop', 'lat' => -33.93, 'lng' => 18.42],
            str_contains($name, 'cash-received') => ['amount_minor' => 0],
            str_contains($name, 'ratings') || str_contains($name, '.rating') => ['rating' => 1],
            str_contains($name, 'sos') => ['description' => 'x'],
            str_contains($name, 'report') => ['category' => 'other', 'description' => 'Cross-tenant probe'],
            str_contains($name, 'reply') => ['body' => 'Cross-tenant probe'],
            str_contains($name, 'stage') => [],
            default => [],
        };
    }

    private function urlFor(RoutingRoute $route, array $w): string
    {
        $name = (string) $route->getName();
        $ids = $w['ids'];

        // Ambiguous parameter names resolve by route.
        $overrides = [
            'drive.earnings.bank-account.verify' => ['account' => $ids['payout_account']],
            'money.payout-accounts.verify' => ['account' => $ids['payout_account']],
            'drivers.documents.show' => ['document' => $ids['legacy_document']],
            'drivers.documents.destroy' => ['document' => $ids['legacy_document']],
            'drive.safety.case' => ['case' => $ids['case']],
            'ehail.dispatch.driver.rides.stage' => ['ride' => $ids['active_ride'], 'stage' => 'arrived'],
            'business.people.update' => ['member' => $ids['business_member']],
            'business.people.destroy' => ['member' => $ids['business_member']],
            'business.invitations.accept-listed' => ['member' => $ids['business_invite']],
            'fleet.settings.managers.update' => ['member' => $ids['fleet_member']],
            'fleet.settings.managers.destroy' => ['member' => $ids['fleet_member']],
            'fleets.drivers.approve' => ['fleet' => $ids['fleet'], 'driverProfileId' => $ids['applicant_profile']],
            'fleets.drivers.reject' => ['fleet' => $ids['fleet'], 'driverProfileId' => $ids['applicant_profile']],
            'fleet.applications.approve' => ['profile' => $ids['applicant_profile']],
            'fleet.applications.reject' => ['profile' => $ids['applicant_profile']],
        ];

        $activeRideRoutes = ['ehail.dispatch.rides.cancel', 'ehail.dispatch.rides.feed', 'ehail.dispatch.rides.trip-change', 'ehail.dispatch.rides.reschedule',
            'ehail.dispatch.rides.requote.accept', 'ehail.dispatch.driver.rides.cancel', 'ehail.dispatch.driver.rides.no-show', 'drive.api.arrived',
            'safety.rides.sos', 'safety.rides.shares.store', 'rides.cancel', 'rides.advance', 'rides.accept', 'money.rides.choice'];

        $params = [];
        foreach ($route->parameterNames() as $param) {
            $params[$param] = $overrides[$name][$param] ?? match ($param) {
                'ride' => in_array($name, $activeRideRoutes, true) ? $ids['active_ride'] : $ids['ride'],
                'offer' => $ids['offer'], 'quote' => (string) Str::uuid(), 'receipt' => $ids['receipt'], 'payout' => $ids['payout'],
                'earning' => $ids['earning'], 'method' => $ids['method'], 'incident' => $ids['incident'], 'case' => $ids['case'],
                'message' => $ids['message'], 'share' => $ids['share'], 'contact' => $ids['contact'], 'checkIn' => $ids['check_in'],
                'rating' => $ids['rating'], 'document' => $ids['verification_document'], 'vehicle' => $ids['vehicle'],
                'driverProfile' => $ids['profile'], 'profile' => $ids['profile'], 'fleet' => $ids['fleet'], 'suspension' => $ids['suspension'],
                'notification' => $ids['notification'], 'place' => $ids['place'], 'approval' => $ids['approval'], 'department' => $ids['department'],
                'policy' => $ids['policy'], 'statement' => $ids['statement'], 'member' => $ids['business_member'], 'team' => $ids['team'],
                'invitation' => $ids['team_invitation'], 'account' => $ids['payout_account'],
                default => 1,
            };
        }

        $url = route($name, $params, false);

        // Fleet portal pages pick the fleet from ?fleet=; the attacker names the victim's fleet.
        return str_starts_with($name, 'fleet.') ? $url.(str_contains($url, '?') ? '&' : '?').'fleet='.$ids['fleet'] : $url;
    }

    /** @return array{attacker: User, owners: array<string, User>, ids: array<string, int|string>} */
    private function world(): array
    {
        Mail::fake();
        $this->setUpBusiness();
        $this->enablePaystack();
        $this->fakeMoneyPaystack();
        config(['money.commission.percent' => 20, 'money.payouts.minimum_minor' => 100]);

        // The victim side: a fleet with a verified driver, a passenger with history, a business account.
        $fleetOwner = User::factory()->withPersonalTeam()->create(['name' => 'Victim FleetOwner']);
        $fleet = $this->fleetOwnedBy($fleetOwner);
        [$driver, $profile] = $this->fleetDriver($fleet, 'Victim Driver');
        $vehicle = $this->giveVehicle($fleet, $this->fleetVehicle($fleet, 'CA 999 111'), $profile);
        [, $applicant] = $this->fleetDriver($fleet, 'Victim Applicant', 'pending');
        $fleetManager = User::factory()->create();
        $fleetMember = DB::table('fleet_members')->insertGetId(['fleet_id' => $fleet->id, 'user_id' => $fleetManager->id, 'role' => 'manager', 'scopes' => json_encode(['drivers']), 'created_at' => now(), 'updated_at' => now()]);

        $passenger = User::factory()->withPersonalTeam()->create(['name' => 'Victim Passenger']);
        $this->savedCard($passenger);
        $ride = $this->completeTrip($passenger, $driver, '100.00');
        $active = $this->makeRide(['status' => 'driver_assigned', 'driver_id' => $driver->id, 'vehicle_id' => $vehicle->id, 'accepted_at' => now(), 'pickup_lat' => -33.94, 'pickup_lng' => 18.41, 'dropoff_lat' => -33.91, 'dropoff_lng' => 18.43], $passenger);
        $offer = RideOffer::query()->create(['ride_id' => $active->id, 'driver_id' => $driver->id, 'vehicle_id' => $vehicle->id, 'status' => RideOffer::ACCEPTED, 'sent_at' => now(), 'expires_at' => now()->addMinute()]);

        $incident = app(IncidentService::class)->raise($ride, $passenger, IncidentKind::Other, ['description' => 'Victim incident']);
        $incidentId = ($incident['incident'] ?? $incident[0] ?? null)?->id;
        $case = app(SupportCaseService::class)->open($passenger, SupportCategory::LostProperty, 'Victim case', 'Victim case body', ['ride_id' => $ride->id]);
        $share = app(TripShareService::class)->create($active, $passenger)['share'];
        $contact = TrustedContact::create(['user_id' => $passenger->id, 'name' => 'Victim Contact', 'email' => 'victim-contact@example.com', 'email_hash' => hash('sha256', 'victim-contact@example.com')]);
        $checkIn = DB::table('safety_check_ins')->insertGetId(['ride_id' => $active->id, 'user_id' => $passenger->id, 'role' => 'passenger', 'trigger' => 'route_deviation', 'status' => 'pending', 'prompted_at' => now(), 'respond_by' => now()->addMinutes(5), 'dedupe_key' => 'probe-'.Str::random(8), 'created_at' => now(), 'updated_at' => now()]);
        $rating = RideRating::query()->forceCreate(['ride_id' => $ride->id, 'rated_by' => $passenger->id, 'rated_user_id' => $driver->id, 'rated_role' => 'driver', 'rating' => 2]);
        $legacy = DriverDocument::withoutGlobalScopes()->forceCreate(['driver_profile_id' => $profile->id, 'type' => 'license', 'file_path' => 'legacy/x.pdf', 'original_filename' => 'x.pdf']);
        $suspension = DB::table('driver_suspensions')->insertGetId(['driver_profile_id' => $profile->id, 'user_id' => $driver->id, 'reason_code' => 'safety_review', 'previous_status' => 'approved', 'suspended_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $notification = (string) Str::uuid();
        DB::table('notifications')->insert(['id' => $notification, 'type' => 'probe', 'notifiable_type' => $passenger->getMorphClass(), 'notifiable_id' => $passenger->id, 'data' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $place = DB::table('passenger_saved_places')->insertGetId(['user_id' => $passenger->id, 'kind' => 'home', 'label' => 'Home', 'address' => '1 Victim Road', 'lat' => -33.9, 'lng' => 18.4, 'created_at' => now(), 'updated_at' => now()]);

        $payoutAccount = app(PayoutAccounts::class)->add($driver, ['payee_type' => 'driver', 'fleet_id' => null, 'account_holder' => 'Victim Driver', 'account_number' => '62000011112', 'bank_code' => '250655', 'bank_name' => 'FNB', 'account_type' => 'personal']);
        $this->travel(1)->days();
        $payout = app(PayoutService::class)->draftBatch(CarbonImmutable::today())->payouts()->first();

        [$account, $admin] = $this->businessAccount(['legal_name' => 'Victim Co (Pty) Ltd']);
        $department = $this->department($account);
        $policy = $this->policy($account);
        $traveller = $this->member($account);
        $invite = BusinessMember::create(['business_account_id' => $account->id, 'email' => 'invitee@victim.example', 'role' => BusinessMember::TRAVELLER, 'status' => BusinessMember::INVITED, 'invite_expires_at' => now()->addDay()]);
        $approval = DB::table('business_approvals')->insertGetId(['business_account_id' => $account->id, 'requester_member_id' => $traveller->id, 'status' => 'pending', 'pickup_lat' => -33.9, 'pickup_lng' => 18.4, 'dropoff_lat' => -33.91, 'dropoff_lng' => 18.43, 'estimate_minor' => 9000, 'currency' => 'ZAR', 'decide_by' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);
        $statement = app(Statements::class)->issue($account, now()->subMonth()->format('Y-m'), false)->id;
        $teamInvitation = DB::table('team_invitations')->insertGetId(['team_id' => $passenger->currentTeam->id, 'email' => 'someone@victim.example', 'role' => 'editor', 'created_at' => now(), 'updated_at' => now()]);

        // The attacker is everything at once, in their own tenancy: passenger, verified driver, fleet owner, business admin.
        $attacker = User::factory()->withPersonalTeam()->create(['name' => 'Mallory Attacker']);
        $ownFleet = $this->fleetOwnedBy($attacker, 'approved', 'Mallory Cabs');
        DB::table('driver_profiles')->insert(['user_id' => $attacker->id, 'fleet_id' => $ownFleet->id, 'license_number' => encrypt('MAL-1', false), 'id_number' => encrypt('9001015009087', false), 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()]);
        $this->businessAccount(['legal_name' => 'Mallory Co (Pty) Ltd'], 1_000_000, $attacker);

        return [
            'attacker' => $attacker,
            'owners' => ['passenger' => $passenger, 'driver' => $driver, 'fleet_owner' => $fleetOwner, 'business_admin' => $admin->user, 'own' => $passenger, 'team' => $passenger],
            'ids' => [
                'ride' => $ride->id, 'active_ride' => $active->id, 'offer' => $offer->id, 'receipt' => Receipt::query()->where('ride_id', $ride->id)->value('id'),
                'payout' => $payout?->id ?? 0, 'earning' => DriverEarning::query()->where('driver_id', $driver->id)->value('id'),
                'method' => PaymentMethod::query()->where('user_id', $passenger->id)->value('id'), 'incident' => $incidentId, 'case' => $case->id,
                'message' => $case->messages()->value('id'), 'share' => $share->id, 'contact' => $contact->id, 'check_in' => $checkIn, 'rating' => $rating->id,
                'verification_document' => VerificationDocument::query()->where('user_id', $driver->id)->value('id'), 'legacy_document' => $legacy->id,
                'vehicle' => $vehicle->id, 'profile' => $profile->id, 'applicant_profile' => $applicant->id, 'fleet' => $fleet->id, 'fleet_member' => $fleetMember,
                'suspension' => $suspension, 'notification' => $notification, 'place' => $place, 'payout_account' => $payoutAccount->id,
                'approval' => $approval, 'department' => $department->id, 'policy' => $policy->id, 'statement' => $statement,
                'business_member' => $traveller->id, 'business_invite' => $invite->id, 'team' => $passenger->currentTeam->id, 'team_invitation' => $teamInvitation,
            ],
        ];
    }
}
