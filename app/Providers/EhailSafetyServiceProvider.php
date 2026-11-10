<?php

namespace App\Providers;

use App\Domain\Safety\Console\SafetySweep;
use App\Domain\Safety\Console\ScanCancellationPatterns;
use App\Domain\Safety\Listeners\PromptCheckInOnTripAnomaly;
use App\Domain\Safety\RatingModeration;
use App\Domain\Safety\Seams\GeoLocationStoreAdapter;
use App\Domain\Safety\Seams\NoRidePin;
use App\Domain\Safety\Seams\RidePinSource;
use App\Domain\Safety\Seams\RideRecordProgressSource;
use App\Domain\Safety\Seams\SafetyLocationSource;
use App\Domain\Safety\Seams\TripProgressSource;
use App\Domain\Safety\Sms\BulkSmsGateway;
use App\Domain\Safety\Sms\NullSmsGateway;
use App\Domain\Safety\Sms\SmsGateway;
use App\Domain\Support\Console\SupportSlaSweep;
use App\Domain\Support\Seams\FareAdjustmentRequest;
use App\Domain\Support\Seams\RecordOnlyFareAdjustmentRequest;
use App\Domain\Verification\Console\SweepVerificationExpiry;
use App\Domain\Verification\DriverEligibility;
use App\Domain\Verification\DriverNotEligibleToGoOnline;
use App\Domain\Verification\EligibilityResult;
use App\Domain\Verification\Models\VerificationDocument;
use App\Domain\Verification\VerificationDocumentPolicy;
use App\Domain\Verification\VerificationDriverEligibility;
use App\Models\DriverProfile;
use App\Models\RideRating;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * R-46 safety domain: verification, passenger and driver safety, incidents, trust, support.
 * Bindings, policies, limiters, listeners and schedules live here (never in route files).
 * Seams other domains own are bound with bindIf so their providers win when merged.
 */
class EhailSafetyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DriverEligibility::class, VerificationDriverEligibility::class);

        $this->app->singleton(SmsGateway::class, function () {
            $config = (array) config('ehail_safety.sms', []);

            if (($config['driver'] ?? 'null') === 'bulksms') {
                $gateway = new BulkSmsGateway((array) ($config['bulksms'] ?? []), (int) ($config['timeout'] ?? 5));

                return $gateway->isConfigured() ? $gateway : new NullSmsGateway;
            }

            return new NullSmsGateway;
        });

        $this->app->bindIf(SafetyLocationSource::class, GeoLocationStoreAdapter::class);
        $this->app->bindIf(TripProgressSource::class, RideRecordProgressSource::class);
        $this->app->bindIf(RidePinSource::class, NoRidePin::class);
        $this->app->bindIf(FareAdjustmentRequest::class, RecordOnlyFareAdjustmentRequest::class);
    }

    public function boot(): void
    {
        // Private disk for support attachments (same shape as driver-documents: never served).
        config(['filesystems.disks.safety-files' => config('filesystems.disks.safety-files') ?? [
            'driver' => 'local',
            'root' => storage_path('app/private/safety-files'),
            'serve' => false,
            'visibility' => 'private',
            'throw' => false,
            'report' => false,
        ]]);

        Gate::policy(VerificationDocument::class, VerificationDocumentPolicy::class);

        $this->limiters();
        $this->listeners();
        $this->guardGoingOnline();

        if ($this->app->runningInConsole()) {
            $this->commands([SweepVerificationExpiry::class, SafetySweep::class, ScanCancellationPatterns::class, SupportSlaSweep::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('ehail:safety-sweep')->everyMinute()->withoutOverlapping();
            $schedule->command('ehail:support-sla')->everyFiveMinutes()->withoutOverlapping();
            $schedule->command('ehail:cancellation-patterns')->hourly()->withoutOverlapping();
            $schedule->command('ehail:verification-expiry')->dailyAt('05:00')->withoutOverlapping();
        });
    }

    private function limiters(): void
    {
        $key = fn (Request $request) => (string) ($request->user()?->id ?? $request->ip());

        RateLimiter::for('ehail-safety-sos', fn (Request $request) => Limit::perMinute(10)->by($key($request)));
        RateLimiter::for('ehail-safety-write', fn (Request $request) => Limit::perMinute(30)->by($key($request)));
        RateLimiter::for('ehail-safety-uploads', fn (Request $request) => Limit::perMinute(20)->by($key($request)));
        RateLimiter::for('ehail-ratings', fn (Request $request) => [Limit::perMinute(10)->by($key($request)), Limit::perDay(100)->by($key($request))]);
        RateLimiter::for('ehail-contacts', fn (Request $request) => Limit::perHour(20)->by($key($request)));
        // Public trip-share page and its 5-second poll: generous per IP, still bounded.
        RateLimiter::for('ehail-trip-share', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));
        RateLimiter::for('ehail-contact-links', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));
    }

    private function listeners(): void
    {
        // The geo domain's events (contract section 3). Registered by name: nothing breaks if the
        // geo branch is not merged yet, and they start working the moment it is.
        foreach (['App\\Domain\\Geo\\Events\\RouteDeviationDetected', 'App\\Domain\\Geo\\Events\\UnusualStopDetected'] as $event) {
            Event::listen($event, PromptCheckInOnTripAnomaly::class);
        }

        RideRating::created(function (RideRating $rating) {
            try {
                app(RatingModeration::class)->screen($rating);
            } catch (Throwable $e) {
                Log::warning('Rating screening failed.', ['rating_id' => $rating->id, 'error' => class_basename($e)]);
            }
        });
    }

    /**
     * Defence in depth for "never allow an unapproved driver or vehicle to go online": whatever
     * code path flips driver_profiles.is_online to true on an existing profile goes through
     * DriverEligibility first (dispatch's AvailabilityService should also call it directly).
     */
    private function guardGoingOnline(): void
    {
        DriverProfile::updating(function (DriverProfile $profile) {
            if (! $profile->isDirty('is_online') || ! $profile->is_online || $profile->getOriginal('is_online')) {
                return;
            }

            $driver = User::find($profile->user_id);
            $vehicle = $profile->vehicles()->where('is_active', true)->first();

            if ($driver === null || $vehicle === null) {
                throw new DriverNotEligibleToGoOnline(new EligibilityResult([
                    ['code' => 'no_active_vehicle', 'subject' => 'vehicle', 'type' => null, 'message' => 'Add a vehicle and make it active before going online.'],
                ]));
            }

            $result = app(DriverEligibility::class)->canGoOnline($driver, $vehicle);

            if (! $result->allowed()) {
                throw new DriverNotEligibleToGoOnline($result);
            }
        });
    }
}
