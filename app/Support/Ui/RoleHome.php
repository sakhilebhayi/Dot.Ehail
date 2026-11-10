<?php

namespace App\Support\Ui;

use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * Which app surface a person belongs in. Read-only role facts: a platform operator works in /ops, a person with a
 * driver profile drives from /drive, everyone else rides from /ride. A person may hold several roles (a driver also
 * books rides; a fleet owner may drive), so `surfaces()` lists every surface they may enter, primary first.
 */
final class RoleHome
{
    /** @var array<int, array{driver: bool, fleetOwner: bool}> */
    private array $facts = [];

    public function isOperator(User $user): bool
    {
        return $user->isPlatformOperator();
    }

    public function isDriver(User $user): bool
    {
        return $this->facts($user)['driver'];
    }

    public function ownsFleet(User $user): bool
    {
        return $this->facts($user)['fleetOwner'];
    }

    /** The surface a person lands on: ops, driver or passenger. */
    public function primarySurface(User $user): string
    {
        return match (true) {
            $this->isOperator($user) => 'ops',
            $this->isDriver($user) => 'driver',
            default => 'passenger',
        };
    }

    /**
     * Every surface this person may enter, primary first, limited to surfaces whose home route exists.
     *
     * @return list<string>
     */
    public function surfaces(User $user): array
    {
        $surfaces = [$this->primarySurface($user), 'passenger'];

        if ($this->isDriver($user)) {
            $surfaces[] = 'driver';
        }
        if ($this->ownsFleet($user)) {
            $surfaces[] = 'fleet';
        }
        if ($this->isOperator($user)) {
            $surfaces[] = 'ops';
        }

        return array_values(array_filter(
            array_unique($surfaces),
            fn (string $surface) => Route::has(self::HOME_ROUTES[$surface]),
        ));
    }

    /** URL of this person's home; falls back to the dashboard while a home route is missing. */
    public function url(User $user): string
    {
        $route = self::HOME_ROUTES[$this->primarySurface($user)];

        return Route::has($route) ? route($route) : route('dashboard');
    }

    public const HOME_ROUTES = [
        'passenger' => 'ride.home',
        'driver' => 'drive.home',
        'ops' => 'ops.home',
        'business' => 'business.home',
        'fleet' => 'fleet.home',
    ];

    public const LABELS = [
        'passenger' => 'Ride',
        'driver' => 'Drive',
        'ops' => 'Operations',
        'business' => 'Business',
        'fleet' => 'Fleet',
    ];

    /** @return array{driver: bool, fleetOwner: bool} */
    private function facts(User $user): array
    {
        return $this->facts[$user->getKey()] ??= [
            // DriverProfile carries a per-user global scope; the role check is about this user only.
            'driver' => DriverProfile::withoutGlobalScope('user')->where('user_id', $user->getKey())->exists(),
            'fleetOwner' => Fleet::where('owner_user_id', $user->getKey())->exists(),
        ];
    }
}
