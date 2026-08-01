<?php

namespace Tests\Feature;

use App\Livewire\NotificationBell;
use App\Models\Ride;
use App\Models\User;
use App\Notifications\RideCompletedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class NotificationBellTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_renders_notification_bell_for_authenticated_user(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSeeLivewire('notification-bell');
    }

    public function test_unread_count_reflects_database_notifications(): void
    {
        $user      = User::factory()->withPersonalTeam()->create();
        $passenger = User::factory()->create();

        $ride = Ride::create([
            'passenger_id'    => $passenger->id,
            'driver_id'       => $user->id,
            'pickup_address'  => '1 Adderley Street',
            'dropoff_address' => '2 Strand Street',
            'status'          => 'completed',
            'final_fare'      => 55,
        ]);

        $user->notify(new RideCompletedNotification($ride));

        $this->assertDatabaseCount('notifications', 1);

        Livewire::actingAs($user)
            ->test(NotificationBell::class)
            ->assertSet('open', false)
            ->call('toggle')
            ->assertSet('open', true)
            ->assertSee('Ride completed');

        $this->assertEquals(1, $user->fresh()->unreadNotifications()->count());
    }

    public function test_mark_all_as_read_clears_unread_count(): void
    {
        $user      = User::factory()->withPersonalTeam()->create();
        $passenger = User::factory()->create();

        $ride = Ride::create([
            'passenger_id'    => $passenger->id,
            'driver_id'       => $user->id,
            'pickup_address'  => '3 Loop Street',
            'dropoff_address' => '4 Wale Street',
            'status'          => 'completed',
            'final_fare'      => 40,
        ]);

        $user->notify(new RideCompletedNotification($ride));

        Livewire::actingAs($user)
            ->test(NotificationBell::class)
            ->call('markAllAsRead');

        $this->assertEquals(0, $user->fresh()->unreadNotifications()->count());
    }
}
