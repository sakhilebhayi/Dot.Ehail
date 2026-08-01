<?php

namespace Tests\Feature\Ehail;

use App\Models\Ride;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RideViewTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_view_a_ride(): void
    {
        $passenger = User::factory()->create();

        $ride = Ride::create([
            'passenger_id'    => $passenger->id,
            'pickup_address'  => '10 Kloof Street',
            'dropoff_address' => '20 Long Street',
            'status'          => 'requested',
        ]);

        $this->get(route('rides.show', $ride))->assertRedirect('/login');
    }

    public function test_authenticated_user_can_view_a_ride(): void
    {
        $user      = User::factory()->withPersonalTeam()->create();
        $passenger = User::factory()->create();

        $ride = Ride::create([
            'passenger_id'    => $passenger->id,
            'pickup_address'  => '10 Kloof Street',
            'dropoff_address' => '20 Long Street',
            'status'          => 'completed',
            'final_fare'      => 64.00,
        ]);

        $this->actingAs($user)
            ->get(route('rides.show', $ride))
            ->assertOk()
            ->assertViewIs('rides.show')
            ->assertSee('10 Kloof Street')
            ->assertSee('20 Long Street');
    }

    public function test_ride_search_filters_by_pickup_address(): void
    {
        $user      = User::factory()->withPersonalTeam()->create();
        $passenger = User::factory()->create();

        Ride::create([
            'passenger_id'    => $passenger->id,
            'pickup_address'  => 'Unique Pickup Alpha',
            'dropoff_address' => 'Somewhere',
            'status'          => 'requested',
        ]);
        Ride::create([
            'passenger_id'    => $passenger->id,
            'pickup_address'  => 'Totally Different Beta',
            'dropoff_address' => 'Elsewhere',
            'status'          => 'requested',
        ]);

        $response = $this->actingAs($user)->get(route('rides.index', ['q' => 'Alpha']));

        $response->assertOk();
        $response->assertSee('Unique Pickup Alpha');
        $response->assertDontSee('Totally Different Beta');
    }
}
