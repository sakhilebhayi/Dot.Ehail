<?php

namespace Tests\Feature\Safety;

use App\Domain\Safety\CancellationPatternMonitor;
use App\Domain\Safety\Listeners\PromptCheckInOnTripAnomaly;
use App\Domain\Safety\Models\RatingAppeal;
use App\Domain\Safety\Models\SafetyCheckIn;
use App\Domain\Safety\Models\SafetyIncident;
use App\Domain\Safety\Models\UserReport;
use App\Domain\Safety\TrustSignals;
use App\Models\AuditLog;
use App\Models\DriverProfile;
use App\Models\RideRating;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\CreatesSafetyFixtures;
use Tests\TestCase;

class CheckInAndTrustTest extends TestCase
{
    use CreatesSafetyFixtures;
    use RefreshDatabase;

    private function anomaly(int $rideId, string $class = 'RouteDeviationDetected'): object
    {
        // Shaped like the geo domain's event (public rideId plus scalar detail).
        return new class($rideId, $class)
        {
            public function __construct(public int $rideId, public string $kindName, public int $distanceOffRouteM = 850) {}
        };
    }

    public function test_geo_events_are_wired_by_name(): void
    {
        $this->assertTrue(Event::hasListeners('App\\Domain\\Geo\\Events\\RouteDeviationDetected'));
        $this->assertTrue(Event::hasListeners('App\\Domain\\Geo\\Events\\UnusualStopDetected'));
    }

    public function test_deviation_prompts_both_parties_once_per_window(): void
    {
        [$ride, $passenger, $driver] = $this->makeActiveRide();
        $listener = app(PromptCheckInOnTripAnomaly::class);

        $listener->handle($this->anomaly($ride->id));
        $listener->handle($this->anomaly($ride->id));

        $this->assertSame(2, SafetyCheckIn::count());
        $this->assertSame(850, SafetyCheckIn::where('user_id', $passenger->id)->first()->context['distanceOffRouteM']);
        $this->actingAs($driver)->getJson(route('safety.check-ins.index'))->assertOk()->assertJsonCount(1, 'data');
        $this->assertCount(1, $this->notificationsOfType($passenger, 'safety_check_in'));

        // A ride that is not active gets no prompt.
        $ride->forceFill(['status' => 'completed'])->save();
        $this->travel(11)->minutes();
        $listener->handle($this->anomaly($ride->id));
        $this->assertSame(2, SafetyCheckIn::count());
    }

    public function test_ok_answer_raises_nothing_and_need_help_raises_a_critical_incident(): void
    {
        $this->makeOperator();
        [$ride, $passenger, $driver] = $this->makeActiveRide();
        app(PromptCheckInOnTripAnomaly::class)->handle($this->anomaly($ride->id));

        $driverCheckIn = SafetyCheckIn::where('user_id', $driver->id)->firstOrFail();
        $passengerCheckIn = SafetyCheckIn::where('user_id', $passenger->id)->firstOrFail();

        $this->actingAs($passenger)->postJson(route('safety.check-ins.respond', $driverCheckIn), ['answer' => 'ok'])->assertNotFound();
        $this->actingAs($driver)->postJson(route('safety.check-ins.respond', $driverCheckIn), ['answer' => 'ok'])->assertOk();
        $this->assertSame(0, SafetyIncident::count());

        $this->actingAs($passenger)->postJson(route('safety.check-ins.respond', $passengerCheckIn), ['answer' => 'need_help', 'lat' => -26.1, 'lng' => 28.0])
            ->assertOk()->assertJsonPath('what_we_did.incident.kind', 'deviation');

        $incident = SafetyIncident::firstOrFail();
        $this->assertSame('critical', $incident->severity->value);
        $this->assertSame('check_in_need_help', $incident->source);
        $this->assertSame($incident->id, $passengerCheckIn->fresh()->safety_incident_id);
    }

    public function test_unanswered_check_in_escalates_to_an_incident_after_the_deadline(): void
    {
        [$ride, $passenger] = $this->makeActiveRide();
        app(PromptCheckInOnTripAnomaly::class)->handle($this->anomaly($ride->id, 'UnusualStopDetected'));

        $this->travel(2)->minutes();
        $this->artisan('ehail:safety-sweep')->assertSuccessful();
        $this->assertSame(0, SafetyIncident::count());

        $this->travel(2)->minutes();
        // Polling also advances the sweep (no daemon).
        $this->actingAs($passenger)->getJson(route('safety.check-ins.index'))->assertOk()->assertJsonCount(0, 'data');

        $this->assertSame(2, SafetyIncident::where('source', 'check_in_timeout')->count());
        $this->assertSame(2, SafetyCheckIn::where('status', 'unanswered')->count());

        // A late "I need help" is still honoured.
        $late = SafetyCheckIn::where('user_id', $passenger->id)->firstOrFail();
        $this->actingAs($passenger)->postJson(route('safety.check-ins.respond', $late), ['answer' => 'need_help'])->assertOk();
        $this->assertSame(1, SafetyIncident::where('source', 'check_in_need_help')->count());
    }

    public function test_ratings_rate_limited_one_per_side_completed_only_and_abusive_comments_held(): void
    {
        [$ride, $passenger, $driver] = $this->makeActiveRide();

        $this->actingAs($passenger)->postJson(route('safety.rides.rating', $ride), ['rating' => 5])->assertForbidden();

        $ride->forceFill(['status' => 'completed', 'completed_at' => now()])->save();
        $this->actingAs($passenger)->postJson(route('safety.rides.rating', $ride), ['rating' => 1, 'comment' => 'Driver said he would kill me'])
            ->assertCreated()->assertJsonPath('data.comment_status', 'held');
        $this->actingAs($passenger)->postJson(route('safety.rides.rating', $ride), ['rating' => 5])->assertStatus(409);
        $this->actingAs($driver)->postJson(route('safety.rides.rating', $ride), ['rating' => 4, 'comment' => 'Pleasant'])
            ->assertCreated()->assertJsonPath('data.comment_status', 'visible');

        // Held comments are not shown to the rated driver, but the star value counts.
        $this->actingAs($driver)->getJson(route('safety.ratings.received'))
            ->assertJsonPath('data.0.comment', null)->assertJsonPath('data.0.comment_hidden', true)->assertJsonPath('band.band', 'new');

        for ($i = 0; $i < 10; $i++) {
            $last = $this->actingAs($passenger)->postJson(route('safety.rides.rating', $ride), ['rating' => 5]);
        }
        $last->assertStatus(429);
    }

    public function test_upheld_appeal_removes_the_rating_from_the_average_and_hidden_comments_leave_the_profile(): void
    {
        $operator = $this->makeOperator();
        [$ride, $passenger, $driver] = $this->makeActiveRide();
        $ride->forceFill(['status' => 'completed', 'completed_at' => now()])->save();
        $this->actingAs($passenger)->postJson(route('safety.rides.rating', $ride), ['rating' => 1, 'comment' => 'Rude'])->assertCreated();
        $rating = RideRating::firstOrFail();
        $profile = DriverProfile::withoutGlobalScope('user')->where('user_id', $driver->id)->first();
        $this->assertSame('1.00', (string) $profile->rating);

        $this->actingAs($passenger)->postJson(route('safety.ratings.appeal', $rating), ['reason' => 'Not mine to appeal'])->assertNotFound();
        $this->actingAs($driver)->postJson(route('safety.ratings.appeal', $rating), ['reason' => 'The passenger was abusive from the start.'])->assertCreated();
        $this->actingAs($driver)->postJson(route('safety.ratings.appeal', $rating), ['reason' => 'Again, appealing a second time.'])->assertUnprocessable();

        $appeal = RatingAppeal::firstOrFail();
        $this->actingAs($driver)->postJson(route('safety.ops.appeals.decide', $appeal), ['decision' => 'upheld'])->assertForbidden();
        $this->actingAs($operator)->postJson(route('safety.ops.appeals.decide', $appeal), ['decision' => 'upheld'])->assertOk();

        $this->assertNotNull($rating->fresh()->excluded_at);
        $this->assertSame('0.00', (string) $profile->fresh()->rating);

        $this->actingAs($operator)->postJson(route('safety.ops.comments.moderate', $rating), ['action' => 'hide', 'reason' => 'abusive'])->assertOk();
        $this->actingAs($operator)->get(route('drivers.show', $profile->id))->assertOk()->assertDontSee('Rude');
        $this->assertTrue(AuditLog::where('action', 'ride_rating.comment_hidden')->exists());
    }

    public function test_rating_band_hides_raw_average_below_minimum_count(): void
    {
        $this->assertSame('new', TrustSignals::band(5.0, 4)['band']);
        $this->assertSame('excellent', TrustSignals::band(4.85, 20)['band']);
        $this->assertSame('good', TrustSignals::band(4.2, 20)['band']);
        $this->assertSame('mixed', TrustSignals::band(3.1, 20)['band']);
    }

    public function test_private_reports_are_deduplicated_never_shown_to_the_reported_user_and_reviewed_by_ops(): void
    {
        $operator = $this->makeOperator();
        [$ride, $passenger, $driver] = $this->makeActiveRide();

        $this->actingAs($driver)->postJson(route('safety.rides.report', $ride), ['category' => 'harassment', 'description' => 'Verbal abuse'])->assertCreated();
        $this->actingAs($driver)->postJson(route('safety.rides.report', $ride), ['category' => 'harassment'])->assertUnprocessable();
        $this->actingAs(User::factory()->withPersonalTeam()->create())->postJson(route('safety.rides.report', $ride), ['category' => 'harassment'])->assertNotFound();

        $report = UserReport::firstOrFail();
        $this->assertSame($passenger->id, $report->reported_user_id);
        $this->assertCount(0, $this->notificationsOfType($passenger, 'user_report_reviewed'));

        $this->actingAs($passenger)->getJson(route('safety.ops.reports.index'))->assertForbidden();
        $this->actingAs($operator)->getJson(route('safety.ops.reports.index'))->assertOk()->assertJsonPath('data.0.category', 'harassment');
        $this->actingAs($operator)->postJson(route('safety.ops.reports.review', $report), ['status' => 'actioned', 'note' => 'Warned'])->assertOk();
        $this->actingAs($operator)->postJson(route('safety.ops.reports.review', $report), ['status' => 'dismissed'])->assertUnprocessable();
        $this->assertCount(1, $this->notificationsOfType($driver, 'user_report_reviewed'));
    }

    public function test_cancellation_patterns_are_flagged_for_review_once_and_nobody_is_banned(): void
    {
        $passenger = User::factory()->withPersonalTeam()->create();
        [$driver] = $this->makeVerifiedDriver();

        for ($i = 0; $i < 5; $i++) {
            $this->makeRide(['status' => 'cancelled', 'cancelled_by' => $passenger->id, 'accepted_at' => now(), 'cancelled_at' => now(), 'driver_id' => $driver->id], $passenger);
            AuditLog::create(['action' => 'ride.driver_cancelled', 'actor_user_id' => $driver->id, 'subject_type' => 'ride', 'subject_id' => 1, 'meta' => []]);
        }

        $monitor = app(CancellationPatternMonitor::class);
        $this->assertSame(2, $monitor->scan());
        $this->assertSame(0, $monitor->scan());

        $this->assertSame(['driver', 'passenger'], UserReport::where('category', 'cancellation_pattern')->get()->pluck('evidence.subject_role')->sort()->values()->all());
        $this->assertSame('approved', DriverProfile::withoutGlobalScope('user')->where('user_id', $driver->id)->value('status'));
    }
}
