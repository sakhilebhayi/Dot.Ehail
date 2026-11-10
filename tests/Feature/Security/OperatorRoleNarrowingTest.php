<?php

namespace Tests\Feature\Security;

use App\Domain\Verification\Models\VerificationDocument;
use App\Models\DriverProfile;
use App\Models\User;
use App\Support\DriverIdentifiers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\FleetFixtures;
use Tests\Concerns\OpsFixtures;
use Tests\TestCase;

/**
 * R-46 security S-02, S-03, S-04, S-06, S-10: places outside /ops that let any platform operator in by the flag
 * alone now follow the operations role matrix.
 */
class OperatorRoleNarrowingTest extends TestCase
{
    use FleetFixtures;
    use OpsFixtures;
    use RefreshDatabase;

    private function assertDenied(TestResponse $response): void
    {
        $this->assertContains($response->status(), [403, 404], 'Expected a refusal, got '.$response->status());
    }

    public function test_only_a_fleet_reviewer_decides_driver_applications(): void
    {
        $fleet = $this->fleetOwnedBy();
        [, $applicant] = $this->fleetDriver($fleet, 'Applicant One', 'pending');

        foreach (['pricing_manager', 'finance', 'dispatcher', 'support_agent', 'ops_agent', 'safety_officer'] as $role) {
            $operator = $this->operatorWith([$role]);
            $r = $this->actingAs($operator)->post(route('fleet.applications.approve', ['profile' => $applicant->id, 'fleet' => $fleet->id]));
            $this->assertDenied($r);
            $r = $this->actingAs($operator)->post(route('fleets.drivers.approve', [$fleet, $applicant->id]));
            $this->assertDenied($r);
            $this->assertSame('pending', DriverProfile::withoutGlobalScope('user')->find($applicant->id)->status, $role);
        }

        $reviewer = $this->operatorWith(['verification_reviewer']);
        $this->actingAs($reviewer)->post(route('fleets.drivers.approve', [$fleet, $applicant->id]));
        $this->assertSame('approved', DriverProfile::withoutGlobalScope('user')->find($applicant->id)->status);
    }

    public function test_the_fleet_portal_and_legacy_fleet_page_need_fleets_review(): void
    {
        $fleet = $this->fleetOwnedBy();
        $this->fleetDriver($fleet);
        $pricing = $this->operatorWith(['pricing_manager']);

        $this->actingAs($pricing)->get(route('fleets.show', $fleet))->assertForbidden();
        $r = $this->actingAs($pricing)->get(route('fleet.drivers', ['fleet' => $fleet->id]));
        $this->assertDenied($r);
        $r = $this->actingAs($pricing)->get(route('fleet.earnings', ['fleet' => $fleet->id]));
        $this->assertDenied($r);

        $reviewer = $this->operatorWith(['verification_reviewer']);
        $this->actingAs($reviewer)->get(route('fleets.show', $fleet))->assertOk();
        $this->actingAs($reviewer)->get(route('fleet.drivers', ['fleet' => $fleet->id]))->assertOk();
        // Earnings also need finance.view.
        $this->actingAs($reviewer)->get(route('fleet.earnings', ['fleet' => $fleet->id]))->assertForbidden();
    }

    public function test_ride_pages_and_fares_need_rides_view(): void
    {
        [$ride] = $this->makeActiveRide('completed');
        $reviewer = $this->operatorWith(['verification_reviewer']);
        $pricing = $this->operatorWith(['pricing_manager']);

        foreach ([$reviewer, $pricing] as $operator) {
            $this->actingAs($operator)->get(route('rides.show', $ride))->assertForbidden();
            $this->actingAs($operator)->getJson(route('pricing.rides.fare', $ride))->assertForbidden();
            $this->actingAs($operator)->get(route('rides.index'))->assertOk()->assertDontSee('12 Secret Home Road');
        }

        $dispatcher = $this->operatorWith(['dispatcher']);
        $this->actingAs($dispatcher)->get(route('rides.show', $ride))->assertOk()->assertSee('12 Secret Home Road');
    }

    public function test_the_driver_page_masks_identity_numbers_for_roles_without_identity_review(): void
    {
        [$driver, $profile] = $this->makeVerifiedDriver();
        $profile->forceFill(['id_number' => '8001015009087', 'license_number' => 'ZA-LICENCE-557731'])->save();

        $finance = $this->operatorWith(['finance']);
        $this->actingAs($finance)->get(route('drivers.show', $profile))->assertOk()
            ->assertDontSee('8001015009087')->assertDontSee('ZA-LICENCE-557731')
            ->assertSee(DriverIdentifiers::mask('8001015009087'));

        $reviewer = $this->operatorWith(['verification_reviewer']);
        $this->actingAs($reviewer)->get(route('drivers.show', $profile))->assertOk()->assertSee('8001015009087');
        $this->actingAs($driver)->get(route('drivers.show', $profile))->assertOk()->assertSee('8001015009087');
    }

    public function test_a_removed_operator_loses_documents_once_assigned_to_them(): void
    {
        [, $profile] = $this->makeVerifiedDriver();
        $document = VerificationDocument::query()->where('user_id', $profile->user_id)->firstOrFail();
        $former = User::factory()->withPersonalTeam()->create();
        $document->forceFill(['assigned_reviewer_id' => $former->id])->save();

        $this->actingAs($former)->get(route('verification.documents.show', $document))->assertNotFound();
        $this->actingAs($former)->get(route('verification.documents.file', $document))->assertNotFound();
    }

    public function test_an_operator_cannot_approve_their_own_fleet(): void
    {
        $operator = $this->operatorWith(['verification_reviewer']);
        $fleet = $this->fleetOwnedBy($operator, 'pending');

        $this->actingAs($operator)->post(route('operator.fleets.approve', $fleet))->assertForbidden();
        $this->assertSame('pending', $fleet->fresh()->status);
    }
}
