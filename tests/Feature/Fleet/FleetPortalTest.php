<?php

namespace Tests\Feature\Fleet;

use App\Domain\Catalog\Services\CategoryEligibility;
use App\Domain\Dispatch\Models\DriverAvailability;
use App\Domain\Fleet\FleetAlerts;
use App\Domain\Fleet\FleetPerformance;
use App\Domain\Fleet\FleetRegistration;
use App\Domain\Fleet\Models\DriverOnlineSession;
use App\Domain\Fleet\Models\FleetMember;
use App\Domain\Rides\Models\RideEvent;
use App\Models\AuditLog;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\Ride;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Ui\Navigation;
use App\Support\Ui\RoleHome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesEhailFixtures;
use Tests\Concerns\FleetFixtures;
use Tests\TestCase;

class FleetPortalTest extends TestCase
{
    use CreatesEhailFixtures, FleetFixtures, RefreshDatabase;

    public function test_registering_a_fleet_of_one_and_a_company(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user)->get(route('fleet.home'))->assertOk()->assertSee('Register a fleet');
        $this->actingAs($user)->get(route('fleet.register'))->assertOk();
        $this->actingAs($user)->post(route('fleet.register.store'), ['name' => 'Just Me Rides', 'kind' => 'independent'])->assertRedirect();

        $fleet = Fleet::where('name', 'Just Me Rides')->sole();
        $this->assertSame('pending', $fleet->status);
        $this->assertTrue($fleet->isIndependent());
        $this->assertSame('owner', FleetMember::where('fleet_id', $fleet->id)->where('user_id', $user->id)->value('role'));

        $this->actingAs($user)->get(route('fleet.home'))->assertOk()->assertSee('Just Me Rides')->assertSee('In review')->assertDontSee('Applications waiting');

        $this->actingAs($user)->post(route('fleet.register.store'), ['name' => 'Bad', 'kind' => 'galaxy'])->assertSessionHasErrors('kind');
    }

    public function test_a_fleet_created_by_a_driver_application_gets_its_owner_member_and_an_approval_date(): void
    {
        $owner = User::factory()->create();
        $fleet = Fleet::create(['name' => 'Legacy Way', 'owner_user_id' => $owner->id, 'status' => 'pending']);

        $this->assertSame(1, FleetMember::where('fleet_id', $fleet->id)->where('role', 'owner')->count());
        $this->assertNull($fleet->approved_at);
        $fleet->update(['status' => 'approved']);
        $this->assertNotNull($fleet->fresh()->approved_at);
    }

    public function test_adding_a_vehicle_with_features_shows_the_ride_categories_it_qualifies_for(): void
    {
        $fleet = $this->fleetOwnedBy();
        $owner = User::find($fleet->owner_user_id);

        $vehicle = ['plate_number' => 'nd 123-456', 'make' => 'Toyota', 'model' => 'Quantum', 'year' => 2021, 'color' => 'Silver', 'type' => 'standard', 'seats' => 10, 'doors' => 4];
        $this->actingAs($owner)->post(route('fleet.vehicles.store'), $vehicle + ['features' => ['made_up_feature']])->assertSessionHasErrors('features.0');
        $this->actingAs($owner)->post(route('fleet.vehicles.store'), $vehicle + ['features' => ['wheelchair_access']])->assertRedirect();

        $vehicle = Vehicle::where('plate_number', 'ND 123-456')->sole();
        $this->assertSame(['wheelchair_access'], $vehicle->features);
        $this->assertSame($fleet->id, $vehicle->fleet_id);
        $this->assertContains('accessible', app(CategoryEligibility::class)->servableCategoryKeys($vehicle));

        $this->actingAs($owner)->get(route('fleet.vehicles.show', $vehicle))->assertOk()->assertSee('ND 123-456')->assertSee('Qualifies')->assertSee('Nobody has this vehicle');
        $this->actingAs($owner)->post(route('fleet.vehicles.store'), ['plate_number' => 'ND 123-456', 'make' => 'X', 'model' => 'Y', 'year' => 2020, 'color' => 'Red', 'type' => 'standard', 'seats' => 4])
            ->assertSessionHasErrors('plate_number');
    }

    public function test_managers_are_added_by_their_dot_email_with_areas_and_unknown_emails_are_refused(): void
    {
        $fleet = $this->fleetOwnedBy();
        $owner = User::find($fleet->owner_user_id);
        $manager = User::factory()->withPersonalTeam()->create(['email' => 'naledi@example.test']);

        $this->actingAs($owner)->post(route('fleet.settings.managers.store'), ['email' => 'nobody@example.test', 'scopes' => ['vehicles']])->assertSessionHasErrors('fleet');
        $this->actingAs($owner)->post(route('fleet.settings.managers.store'), ['email' => 'Naledi@Example.test', 'scopes' => ['drivers', 'earnings']])->assertSessionHasNoErrors();

        $member = FleetMember::where('user_id', $manager->id)->sole();
        $this->assertSame(['drivers', 'earnings'], $member->scopes);
        $this->actingAs($manager)->get(route('fleet.earnings'))->assertOk();
        $this->actingAs($manager)->get(route('fleet.vehicles'))->assertForbidden();

        // The manager gets the fleet surface in the account menu.
        $this->assertContains('fleet', app(RoleHome::class)->surfaces($manager));

        $this->actingAs($owner)->patch(route('fleet.settings.managers.update', $member->id), ['scopes' => ['vehicles']])->assertSessionHasNoErrors();
        $this->actingAs($manager)->get(route('fleet.vehicles'))->assertOk();
    }

    public function test_settings_save_the_profile_and_payee_arrangement(): void
    {
        $fleet = $this->fleetOwnedBy();
        $owner = User::find($fleet->owner_user_id);

        $this->actingAs($owner)->patch(route('fleet.settings.update'), [
            'name' => 'Soweto Cabs (Pty) Ltd', 'kind' => 'company', 'registration_number' => '2021/123456/07',
            'contact_phone' => '+27 11 555 0101', 'payee_mode' => 'fleet_owner',
        ])->assertSessionHasNoErrors();

        $fleet->refresh();
        $this->assertSame('fleet_owner', $fleet->payee_mode);
        $this->assertSame('2021/123456/07', $fleet->registration_number);
        $this->actingAs($owner)->patch(route('fleet.settings.update'), ['contact_phone' => '<script>'])->assertSessionHasErrors('contact_phone');
    }

    public function test_the_navigation_lists_every_fleet_area(): void
    {
        $fleet = $this->fleetOwnedBy();
        $keys = collect(app(Navigation::class)->items('fleet', User::find($fleet->owner_user_id)))->pluck('key')->all();

        $this->assertSame(['overview', 'vehicles', 'drivers', 'earnings', 'documents', 'maintenance', 'trips', 'alerts', 'settings'], $keys);
    }

    public function test_online_time_is_measured_from_availability_changes_only(): void
    {
        $fleet = $this->fleetOwnedBy();
        [$driver] = $this->fleetDriver($fleet);
        $performance = app(FleetPerformance::class);

        $this->assertSame([], $performance->onlineSeconds([$driver->id], now()->subDay(), now()), 'nothing measured yet: unknown, not zero');

        $this->travelTo(now()->subHours(2));
        $row = DriverAvailability::create(['user_id' => $driver->id, 'state' => DriverAvailability::OFFLINE, 'since' => now()]);
        $row->forceFill(['state' => DriverAvailability::ONLINE])->save();
        $row->forceFill(['last_heartbeat_at' => now()])->save(); // a heartbeat changes nothing
        $this->travelBack();
        $this->travelTo(now()->subMinutes(30));
        $row->forceFill(['state' => DriverAvailability::ON_TRIP])->save(); // still out of offline: same session
        $this->travelBack();
        $row->forceFill(['state' => DriverAvailability::OFFLINE])->save();

        $this->assertSame(1, DriverOnlineSession::count());
        $seconds = $performance->onlineSeconds([$driver->id], now()->subDay(), now())[$driver->id];
        $this->assertEqualsWithDelta(7200, $seconds, 5);
        $this->assertNull(DriverOnlineSession::sole()->open_user_id);
    }

    public function test_driver_cancellations_and_unusual_cancellation_alerts(): void
    {
        $fleet = $this->fleetOwnedBy();
        [$driver, $profile] = $this->fleetDriver($fleet, 'Cancel Prone');
        foreach (range(1, 3) as $i) {
            $ride = $this->makeRide(['status' => 'cancelled_by_driver']);
            RideEvent::create(['ride_id' => $ride->id, 'sequence' => 1, 'type' => 'transition', 'from_status' => 'driver_assigned', 'to_status' => 'cancelled_by_driver', 'actor_type' => 'driver', 'actor_id' => $driver->id, 'occurred_at' => now()]);
            Ride::query()->update(['active_passenger_id' => null]);
        }
        $this->makeRide(['status' => 'completed', 'driver_id' => $driver->id, 'completed_at' => now()]);

        $row = collect(app(FleetPerformance::class)->forFleet(User::find($fleet->owner_user_id), $fleet, now()->subWeek(), now())['rows'])->sole();
        $this->assertSame(1, $row['trips']);
        $this->assertSame(3, $row['cancellations']);
        $this->assertSame(2500, $row['completion_bp']);
        $this->assertNull($row['acceptance_bp']);
        $this->assertSame('new', $row['rating']['band']);

        $alerts = collect(app(FleetAlerts::class)->forFleet(User::find($fleet->owner_user_id), $fleet))->where('kind', 'cancellations');
        $this->assertSame(['Cancel Prone'], $alerts->pluck('subject')->all());
        $this->actingAs(User::find($fleet->owner_user_id))->get(route('fleet.drivers'))->assertOk()->assertSee('25%');
    }

    public function test_legacy_fleet_urls_still_work_in_the_new_shell(): void
    {
        $fleet = $this->fleetOwnedBy();
        $owner = User::find($fleet->owner_user_id);
        $applicant = User::factory()->create(['name' => 'Legacy Applicant']);
        $profile = DriverProfile::factory()->create(['user_id' => $applicant->id, 'fleet_id' => $fleet->id, 'status' => 'pending']);

        $html = $this->actingAs($owner)->get(route('fleets.show', $fleet))->assertOk()->assertSee('Legacy Applicant')->assertSee('Open the fleet portal')->getContent();
        $this->assertStringContainsString('data-surface="fleet"', $html);

        $this->actingAs($owner)->post(route('fleets.drivers.approve', [$fleet, $profile->id]))->assertRedirect();
        $this->assertSame('approved', $profile->fresh()->status);
        $this->actingAs($owner)->post(route('fleets.drivers.approve', [$fleet, $profile->id]))->assertSessionHasErrors('status');
        $this->assertSame(1, AuditLog::where('action', 'fleet.driver_application_approved')->count());

        // The operator's legacy fleet queue moved into the operations centre (R-46 ops, D-1008): it redirects there.
        $this->actingAs($this->makeOperator())->get(route('operator.fleets.index'))->assertRedirect(route('ops.fleets'));
        $this->actingAs($this->makeOperator())->followingRedirects()->get(route('operator.fleets.index'))->assertOk();
    }

    public function test_applications_are_decided_in_the_portal_by_owner_or_a_drivers_manager_never_the_applicant(): void
    {
        $fleet = $this->fleetOwnedBy();
        $owner = User::find($fleet->owner_user_id);
        $a = DriverProfile::factory()->create(['fleet_id' => $fleet->id, 'status' => 'pending']);
        $b = DriverProfile::factory()->create(['fleet_id' => $fleet->id, 'status' => 'pending']);
        $manager = User::factory()->withPersonalTeam()->create(['email' => 'm@example.test']);
        app(FleetRegistration::class)->addManager($fleet, 'm@example.test', ['drivers'], $owner);

        $this->actingAs($owner)->post(route('fleet.applications.approve', $a->id))->assertSessionHasNoErrors();
        $this->actingAs($manager)->post(route('fleet.applications.reject', $b->id), ['reason' => 'No PrDP yet'])->assertSessionHasNoErrors();
        $this->assertSame(['approved', 'rejected'], [$a->fresh()->status, $b->fresh()->status]);
        $this->assertSame('No PrDP yet', $b->fresh()->rejected_reason);

        // The applicant, made a manager, still cannot decide their own application.
        $self = DriverProfile::factory()->create(['fleet_id' => $fleet->id, 'status' => 'pending']);
        $selfUser = User::find($self->user_id);
        $selfUser->forceFill(['email' => 'self@example.test'])->save();
        app(FleetRegistration::class)->addManager($fleet, 'self@example.test', ['drivers'], $owner);
        $this->actingAs($selfUser)->post(route('fleet.applications.approve', $self->id))->assertForbidden();
        $this->assertSame('pending', $self->fresh()->status);
    }
}
