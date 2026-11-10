<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesSafetyFixtures;
use Tests\TestCase;

/**
 * R-46 security S-05: a public trip-share link follows the car only while its creator is a party to the ride and
 * the ride still holds its driver.
 */
class TripShareLeakTest extends TestCase
{
    use CreatesSafetyFixtures;
    use RefreshDatabase;

    private function shareToken(User $creator, $ride): string
    {
        Mail::fake();
        $url = $this->actingAs($creator)->postJson(route('safety.rides.shares.store', $ride))->assertCreated()->json('url');

        return basename(parse_url($url, PHP_URL_PATH));
    }

    public function test_a_driver_who_left_the_ride_loses_their_share_link(): void
    {
        [$ride, , $driver] = $this->makeActiveRide('driver_assigned');
        $token = $this->shareToken($driver, $ride);
        $this->get(route('trip-share.status', $token))->assertOk();

        [$replacement] = $this->makeVerifiedDriver();
        DB::table('rides')->where('id', $ride->id)->update(['driver_id' => $replacement->id]);

        $this->get(route('trip-share.status', $token))->assertNotFound();
        $this->get(route('trip-share.show', $token))->assertNotFound();
    }

    public function test_a_ride_that_released_its_driver_shows_no_position(): void
    {
        [$ride, $passenger, $driver] = $this->makeActiveRide('driver_assigned');
        $token = $this->shareToken($passenger, $ride);

        DB::table('driver_locations')->updateOrInsert(['driver_id' => $driver->id], [
            'lat' => -26.2041, 'lng' => 28.0473, 'accuracy_m' => 5, 'recorded_at' => now(), 'received_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('rides')->where('id', $ride->id)->update(['status' => 'needs_review']);

        $this->get(route('trip-share.status', $token))->assertOk()->assertJsonPath('approximate_position', null);
    }
}
