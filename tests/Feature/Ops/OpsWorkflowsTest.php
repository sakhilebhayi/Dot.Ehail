<?php

namespace Tests\Feature\Ops;

use App\Domain\Dispatch\Models\DriverAvailability;
use App\Domain\Geo\Models\ServiceArea;
use App\Domain\Ops\Access\OpsAccess;
use App\Domain\Ops\Access\RoleAdministration;
use App\Domain\Ops\Availability\AvailabilitySpan;
use App\Domain\Ops\Drivers\DriverSuspension;
use App\Domain\Verification\DriverEligibility;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\OpsFixtures;
use Tests\TestCase;

/** The ops-owned actions end to end: suspensions and appeals, roles administration, zones, measured online time. */
class OpsWorkflowsTest extends TestCase
{
    use OpsFixtures;
    use RefreshDatabase;

    public function test_a_suspension_takes_the_driver_offline_blocks_going_online_and_an_appeal_is_decided_by_someone_else(): void
    {
        [$driver, $profile, $vehicle] = $this->makeVerifiedDriver();
        DriverAvailability::query()->create(['user_id' => $driver->id, 'state' => 'online', 'since' => now(), 'online_since' => now(), 'last_heartbeat_at' => now()]);
        $officer = $this->operatorWith(['safety_officer']);
        $second = $this->operatorWith(['safety_officer']);

        $this->actingAs($this->operatorWith(['dispatcher']))->post(route('ops.drivers.suspend', $driver->id), ['reason_code' => 'conduct'])->assertForbidden();
        $this->actingAs($officer)->post(route('ops.drivers.suspend', $driver->id), ['reason_code' => 'other'])->assertSessionHasErrors('note');
        $this->actingAs($officer)->post(route('ops.drivers.suspend', $driver->id), ['reason_code' => 'conduct', 'note' => 'Shouted at a passenger'])->assertRedirect(route('ops.drivers.show', $driver->id));

        $this->assertSame('suspended', $profile->fresh()->status);
        $this->assertSame('offline', DriverAvailability::query()->where('user_id', $driver->id)->value('state'));
        $this->assertContains('driver_suspended', app(DriverEligibility::class)->canGoOnline($driver, $vehicle)->codes());
        $suspension = DriverSuspension::query()->firstOrFail();

        // The driver appeals (their own JSON endpoint), once.
        $this->actingAs($this->operatorWith(['finance']))->postJson(route('ops.suspension-appeal.store', $suspension), ['text' => 'This was a misunderstanding about the route.'])->assertNotFound();
        $this->actingAs($driver)->postJson(route('ops.suspension-appeal.store', $suspension), ['text' => 'This was a misunderstanding about the route.'])->assertCreated();
        $this->actingAs($driver)->postJson(route('ops.suspension-appeal.store', $suspension), ['text' => 'Second appeal on the same suspension.'])->assertStatus(422);

        $this->actingAs($officer)->post(route('ops.drivers.appeal-decide', $suspension), ['decision' => 'upheld', 'note' => 'Checked'])->assertSessionHasErrors('appeal');
        $this->actingAs($second)->post(route('ops.drivers.appeal-decide', $suspension), ['decision' => 'upheld', 'note' => 'Trip log supports the driver'])->assertRedirect();

        $this->assertSame('approved', $profile->fresh()->status);
        $this->assertNotNull($suspension->fresh()->lifted_at);
        $this->assertSame([], app(DriverEligibility::class)->canGoOnline($driver, $vehicle)->codes());
        foreach (['ops.driver_suspended', 'ops.suspension_appealed', 'ops.suspension_appeal_decided', 'ops.driver_reinstated'] as $action) {
            $this->assertTrue(AuditLog::query()->where('action', $action)->exists(), $action);
        }
    }

    public function test_only_an_administrator_manages_roles_and_the_last_administrator_is_kept(): void
    {
        $admin = $this->operatorWith(['admin']);
        $finance = $this->operatorWith(['finance']);
        $newcomer = User::factory()->withPersonalTeam()->create(['email' => 'newcomer@example.test']);

        $this->actingAs($finance)->post(route('ops.roles.store'), ['email' => 'newcomer@example.test', 'roles' => ['finance']])->assertForbidden();
        $this->actingAs($admin)->post(route('ops.roles.store'), ['email' => 'newcomer@example.test', 'roles' => ['support_agent', 'nonsense']])->assertSessionHasErrors('roles.1');
        $this->actingAs($admin)->post(route('ops.roles.store'), ['email' => 'newcomer@example.test', 'roles' => ['support_agent']])->assertRedirect(route('ops.roles'));

        $newcomer->refresh();
        $this->assertTrue($newcomer->isPlatformOperator());
        $this->assertSame(['support_agent'], app(OpsAccess::class)->roles($newcomer));
        $this->assertTrue(AuditLog::query()->where('action', 'ops.roles_changed')->where('subject_id', $newcomer->id)->exists());

        $this->actingAs($admin)->put(route('ops.roles.update', $newcomer), ['roles' => []])->assertSessionHasErrors('roles');
        // The legacy operator created by the fixture is still a full-access administrator, so make it the last one first.
        User::query()->where('is_platform_operator', true)->whereKeyNot($admin->id)->get()->each(fn ($u) => app(RoleAdministration::class)->setRoles($admin, $u, ['ops_agent']));
        $this->actingAs($admin)->put(route('ops.roles.update', $admin), ['roles' => ['finance']])->assertSessionHasErrors('roles');

        $this->actingAs($admin)->delete(route('ops.roles.destroy', $newcomer))->assertRedirect();
        $this->assertFalse($newcomer->fresh()->isPlatformOperator());
        $this->actingAs($admin)->delete(route('ops.roles.destroy', $admin))->assertSessionHasErrors('user');
        $this->actingAs($admin)->get(route('ops.roles'))->assertOk()->assertSee('What each role can do');
    }

    public function test_a_pricing_manager_draws_a_service_area_and_a_zone_and_switches_them(): void
    {
        $pm = $this->operatorWith(['pricing_manager']);
        $square = [[-26.0, 28.0], [-26.0, 28.1], [-26.1, 28.1], [-26.1, 28.0]];

        $this->actingAs($this->operatorWith(['dispatcher']))->postJson(route('ops.zones.areas.store'), ['name' => 'Test area', 'polygon' => $square])->assertForbidden();
        $this->actingAs($pm)->postJson(route('ops.zones.areas.store'), ['name' => 'Two corners', 'polygon' => [[-26.0, 28.0], [-26.1, 28.1]]])->assertStatus(422);
        $id = $this->actingAs($pm)->postJson(route('ops.zones.areas.store'), ['name' => 'Test area', 'polygon' => $square])->assertCreated()->json('data.id');

        $area = ServiceArea::query()->findOrFail($id);
        $this->assertFalse($area->is_active, 'a new area starts switched off');
        $this->assertEqualsWithDelta(-26.1, $area->min_lat, 0.0001);

        $this->actingAs($pm)->patchJson(route('ops.zones.areas.update', $area), ['is_active' => true])->assertOk();
        $this->assertTrue($area->fresh()->is_active);

        $zoneId = $this->actingAs($pm)->postJson(route('ops.zones.store', $area), ['name' => 'Test airport', 'kind' => 'airport', 'fee_code' => 'airport', 'polygon' => [[-26.02, 28.02], [-26.02, 28.04], [-26.04, 28.04]]])->assertCreated()->json('data.id');
        $this->actingAs($pm)->patchJson(route('ops.zones.update', $zoneId), ['kind' => 'castle'])->assertStatus(422);
        $this->assertSame(3, AuditLog::query()->where('action', 'like', 'ops.%zone%')->orWhere('action', 'like', 'ops.service_area%')->count());
        $this->actingAs($pm)->get(route('ops.zones'))->assertOk()->assertSee('Test airport');
    }

    public function test_online_time_is_measured_from_availability_changes(): void
    {
        $driver = $this->makeApprovedDriver('standard', false);
        $this->travelTo(now()->setTime(8, 0));
        $row = DriverAvailability::query()->create(['user_id' => $driver->id, 'state' => 'online', 'since' => now()]);
        $this->travel(30)->minutes();
        $row->forceFill(['state' => 'on_trip', 'since' => now()])->save();
        $row->forceFill(['last_heartbeat_at' => now()])->save();
        $this->travel(20)->minutes();
        $row->forceFill(['state' => 'offline', 'since' => now()])->save();

        $spans = AvailabilitySpan::query()->orderBy('id')->get();
        $this->assertSame(['online', 'on_trip', 'offline'], $spans->pluck('state')->all(), 'a heartbeat alone opens no span');
        $this->assertSame(1800, (int) $spans[0]->started_at->diffInSeconds($spans[0]->ended_at));
        $this->assertSame(1200, (int) $spans[1]->started_at->diffInSeconds($spans[1]->ended_at));
        $this->assertNull($spans[2]->ended_at);
    }
}
