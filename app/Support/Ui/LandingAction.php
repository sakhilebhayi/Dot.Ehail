<?php

namespace App\Support\Ui;

use App\Domain\Ops\Access\OpsAccess;
use App\Http\Controllers\Ops\OpsHomeController;
use App\Models\DriverProfile;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * The signed-in landing's one dominant action, chosen from the person's role and from real records (R-50).
 *
 * An operator gets the first setup step that is still missing and that they may open, otherwise the first
 * operations screen their role opens. A driver gets their console, worded for what they would do there now (finish
 * setup, open the trip in progress, go online). A fleet owner gets the fleet. Everyone else is a rider: the ride in
 * progress, or booking a ride when bookings are open. When they are closed a rider is never offered a booking that
 * would be refused; the action becomes something that works today.
 *
 * Read-only: it links to routes that exist and that this person may open, and changes nothing.
 */
final class LandingAction
{
    private const OPS_LABELS = [
        'ops.live' => 'Open the live board',
        'ops.rides' => 'Open rides',
        'ops.verification' => 'Open the verification queue',
        'ops.safety' => 'Open safety incidents',
        'ops.support' => 'Open support cases',
        'ops.finance' => 'Open finance',
        'ops.pricing' => 'Open tariffs',
        'ops.insights' => 'Open insights',
        'ops.drivers' => 'Open drivers',
        'ops.fleets' => 'Open fleets',
        'ops.audit' => 'Open the audit log',
        'ops.roles' => 'Open team and roles',
    ];

    /** What a rider is told is still missing, in a rider's words (never a setting name). */
    private const RIDER_WORDS = [
        'routing' => 'trip pricing by road',
        'payment' => 'a way to pay',
        'area' => 'a service area',
        'category' => 'ride options',
        'tariff' => 'published prices',
    ];

    public function __construct(private RoleHome $roles, private OpsAccess $ops) {}

    /**
     * @return array{role: string, surface: string, headline: string, support: string, notice: ?string, label: string, url: string, icon: string}
     */
    public function for(User $user, BookingReadiness $booking): array
    {
        if ($this->roles->isOperator($user) && ($operator = $this->operator($user, $booking)) !== null) {
            return $operator;
        }

        if ($this->roles->isDriver($user) && Route::has('drive.home')) {
            return $this->driver($user, $booking);
        }

        if ($this->roles->ownsFleet($user) && Route::has('fleet.home')) {
            return [
                'role' => 'fleet',
                'surface' => 'fleet',
                'headline' => 'Your cars and drivers, ready to work',
                'support' => 'See which vehicles can work today, what is stopping the others, and the driver applications waiting for you.',
                'notice' => $booking->isOpen() ? null : 'Passengers cannot book yet: the platform is still being set up, so your drivers will not receive offers.',
                'label' => 'Open your fleet',
                'url' => route('fleet.home'),
                'icon' => 'fleet',
            ];
        }

        return $this->rider($user, $booking);
    }

    /** @return array{role: string, surface: string, headline: string, support: string, notice: ?string, label: string, url: string, icon: string}|null */
    private function operator(User $user, BookingReadiness $booking): ?array
    {
        $base = [
            'role' => 'operator',
            'surface' => 'ops',
            'headline' => 'Run the rides, the drivers and the fares',
            'support' => 'Operations for Dot.Ehail: live rides, driver verification, safety, support, finance and the tariffs passengers are quoted from.',
            'notice' => null,
            'icon' => 'route',
        ];

        if (! $booking->isOpen()) {
            $base['headline'] = 'Passengers cannot book yet';
            $base['support'] = 'A ride can only be booked once every stop on the setup line is in place. What is missing is listed with where to fix it.';

            // The first missing step that is done inside operations and that this operator may open.
            foreach ($booking->missing() as $step) {
                if ($step['route'] !== null && Route::has($step['route']) && $this->ops->allowsRoute($user, $step['route'])) {
                    return $base + [
                        'label' => match ($step['key']) {
                            'tariff' => 'Publish a tariff',
                            'area' => 'Set up a service area',
                            default => 'Switch on a ride category',
                        },
                        'url' => route($step['route']),
                    ];
                }
            }
        }

        foreach (OpsHomeController::ORDER as $name) {
            if (Route::has($name) && $this->ops->allowsRoute($user, $name)) {
                return $base + ['label' => self::OPS_LABELS[$name], 'url' => route($name)];
            }
        }

        return null;
    }

    /** @return array{role: string, surface: string, headline: string, support: string, notice: ?string, label: string, url: string, icon: string} */
    private function driver(User $user, BookingReadiness $booking): array
    {
        // DriverProfile carries a per-user global scope; this reads this user's own profile only.
        $profile = DriverProfile::withoutGlobalScope('user')->where('user_id', $user->getKey())->first();
        $approved = $profile?->status === 'approved';
        $onTrip = $approved && Ride::query()->where('active_driver_id', $user->getKey())->exists();

        [$label, $icon] = match (true) {
            ! $approved => ['Finish your driver setup', 'id'],
            $onTrip => ['Open your current trip', 'navigation'],
            (bool) $profile->is_online => ['Open your driver console', 'steering-wheel'],
            default => ['Go online', 'steering-wheel'],
        };

        return [
            'role' => 'driver',
            'surface' => 'driver',
            'headline' => $approved ? 'See the trip and your earnings first' : 'A few steps before your first trip',
            'support' => $approved
                ? 'Every offer shows the pickup distance, the trip and what you earn before you accept. Your statement shows the commission line by line.'
                : 'Your driver application is '.str_replace('_', ' ', (string) $profile?->status).'. Your documents and vehicle are checked before you can go online.',
            'notice' => $approved && ! $booking->isOpen() ? 'Passengers cannot book yet: the platform is still being set up, so no offers will arrive for now.' : null,
            'label' => $label,
            'url' => route('drive.home'),
            'icon' => $icon,
        ];
    }

    /** @return array{role: string, surface: string, headline: string, support: string, notice: ?string, label: string, url: string, icon: string} */
    private function rider(User $user, BookingReadiness $booking): array
    {
        $base = [
            'role' => 'rider',
            'surface' => 'passenger',
            'headline' => 'See the whole fare before you ride',
            'support' => 'Dot.Ehail shows the fare line by line before you request, and the trip PIN, trip sharing and SOS travel with you on every ride.',
            'notice' => null,
        ];

        $active = Ride::query()->where('active_passenger_id', $user->getKey())->first();

        if ($active !== null && Route::has('ride.trip')) {
            return $base + ['label' => 'Open your current ride', 'url' => route('ride.trip', $active), 'icon' => 'navigation'];
        }

        if ($booking->isOpen()) {
            return $base + ['label' => 'Book a ride', 'url' => route('ride.home'), 'icon' => 'pickup'];
        }

        $words = array_values(array_intersect_key(self::RIDER_WORDS, array_flip(array_column($booking->missing(), 'key'))));
        $base['headline'] = 'Bookings are not open yet';
        $base['notice'] = 'You cannot book a ride yet. Still to come from the operator: '.$this->sentenceList($words).'.';

        if (Ride::query()->where('passenger_id', $user->getKey())->exists()) {
            return $base + ['label' => 'See your trips', 'url' => route('ride.trips'), 'icon' => 'list'];
        }

        return $base + ['label' => 'Add your trusted contacts', 'url' => route('ride.safety'), 'icon' => 'users'];
    }

    /** @param  list<string>  $words */
    private function sentenceList(array $words): string
    {
        if (count($words) < 2) {
            return (string) ($words[0] ?? 'the last setup steps');
        }

        $last = array_pop($words);

        return implode(', ', $words).' and '.$last;
    }
}
