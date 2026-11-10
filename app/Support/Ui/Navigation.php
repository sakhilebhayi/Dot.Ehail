<?php

namespace App\Support\Ui;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * Role-aware navigation for the app shells. A destination is shown only when one of its candidate routes exists
 * (wave-2 routes first, then today's working pages) and the person's role allows it, so no link ever leads to a
 * page that is not there. Candidates are tried in order; the first registered route wins.
 */
final class Navigation
{
    /**
     * @var array<string, list<array{key: string, label: string, icon: string, routes: list<string>, requires?: string}>>
     */
    public const SURFACES = [
        'passenger' => [
            ['key' => 'ride', 'label' => 'Ride', 'icon' => 'car', 'routes' => ['ride.home']],
            ['key' => 'trips', 'label' => 'Trips', 'icon' => 'history', 'routes' => ['ride.trips', 'rides.index']],
            ['key' => 'safety', 'label' => 'Safety', 'icon' => 'shield', 'routes' => ['ride.safety']],
            ['key' => 'account', 'label' => 'Account', 'icon' => 'account', 'routes' => ['ride.account', 'profile.show']],
        ],
        'driver' => [
            ['key' => 'drive', 'label' => 'Drive', 'icon' => 'steering-wheel', 'routes' => ['drive.home']],
            ['key' => 'earnings', 'label' => 'Earnings', 'icon' => 'coins', 'routes' => ['drive.earnings'], 'requires' => 'driver'],
            ['key' => 'trips', 'label' => 'Trips', 'icon' => 'history', 'routes' => ['drive.trips', 'rides.available'], 'requires' => 'driver'],
            ['key' => 'account', 'label' => 'Account', 'icon' => 'account', 'routes' => ['drive.account', 'profile.show']],
        ],
        'ops' => [
            ['key' => 'live', 'label' => 'Live', 'icon' => 'activity', 'routes' => ['ops.live', 'ops.home'], 'requires' => 'operator'],
            ['key' => 'rides', 'label' => 'Rides', 'icon' => 'route', 'routes' => ['ops.rides', 'operator.rides.index'], 'requires' => 'operator'],
            ['key' => 'drivers', 'label' => 'Drivers', 'icon' => 'steering-wheel', 'routes' => ['ops.drivers'], 'requires' => 'operator'],
            ['key' => 'verification', 'label' => 'Verification', 'icon' => 'licence-disc', 'routes' => ['ops.verification'], 'requires' => 'operator'],
            ['key' => 'safety', 'label' => 'Safety', 'icon' => 'shield-alert', 'routes' => ['ops.safety'], 'requires' => 'operator'],
            ['key' => 'support', 'label' => 'Support', 'icon' => 'headset', 'routes' => ['ops.support'], 'requires' => 'operator'],
            ['key' => 'pricing', 'label' => 'Pricing', 'icon' => 'percent', 'routes' => ['ops.pricing'], 'requires' => 'operator'],
            ['key' => 'finance', 'label' => 'Finance', 'icon' => 'receipt', 'routes' => ['ops.finance'], 'requires' => 'operator'],
            ['key' => 'fleets', 'label' => 'Fleets', 'icon' => 'fleet', 'routes' => ['ops.fleets', 'operator.fleets.index'], 'requires' => 'operator'],
            ['key' => 'business', 'label' => 'Business', 'icon' => 'briefcase', 'routes' => ['ops.business'], 'requires' => 'operator'],
            ['key' => 'insights', 'label' => 'Insights', 'icon' => 'chart', 'routes' => ['ops.insights'], 'requires' => 'operator'],
            ['key' => 'audit', 'label' => 'Audit log', 'icon' => 'eye', 'routes' => ['ops.audit'], 'requires' => 'operator'],
            ['key' => 'team', 'label' => 'Team and roles', 'icon' => 'key', 'routes' => ['ops.roles'], 'requires' => 'operator'],
        ],
        'business' => [
            ['key' => 'overview', 'label' => 'Overview', 'icon' => 'briefcase', 'routes' => ['business.home']],
            ['key' => 'trips', 'label' => 'Trips', 'icon' => 'history', 'routes' => ['business.trips']],
            ['key' => 'people', 'label' => 'People', 'icon' => 'users', 'routes' => ['business.people']],
            ['key' => 'policies', 'label' => 'Ride policies', 'icon' => 'document', 'routes' => ['business.policies']],
            ['key' => 'invoices', 'label' => 'Invoices', 'icon' => 'receipt', 'routes' => ['business.invoices']],
            ['key' => 'settings', 'label' => 'Settings', 'icon' => 'settings', 'routes' => ['business.settings']],
        ],
        'fleet' => [
            ['key' => 'overview', 'label' => 'Overview', 'icon' => 'fleet', 'routes' => ['fleet.home']],
            ['key' => 'vehicles', 'label' => 'Vehicles', 'icon' => 'car', 'routes' => ['fleet.vehicles']],
            ['key' => 'drivers', 'label' => 'Drivers', 'icon' => 'steering-wheel', 'routes' => ['fleet.drivers']],
            ['key' => 'earnings', 'label' => 'Earnings', 'icon' => 'coins', 'routes' => ['fleet.earnings']],
            ['key' => 'documents', 'label' => 'Documents', 'icon' => 'document-check', 'routes' => ['fleet.documents']],
            ['key' => 'maintenance', 'label' => 'Maintenance', 'icon' => 'wrench', 'routes' => ['fleet.maintenance']],
        ],
    ];

    public function __construct(private readonly RoleHome $roles) {}

    /**
     * The destinations this person sees on a surface, each with a resolved URL and active flag.
     *
     * @return list<array{key: string, label: string, icon: string, route: string, url: string, active: bool}>
     */
    public function items(string $surface, ?User $user, ?Request $request = null): array
    {
        $request ??= request();
        $items = [];

        foreach (self::SURFACES[$surface] ?? [] as $definition) {
            if (! $this->allowed($definition['requires'] ?? null, $user)) {
                continue;
            }

            $route = $this->firstExistingRoute($definition['routes']);
            if ($route === null) {
                continue;
            }

            $items[] = [
                'key' => $definition['key'],
                'label' => $definition['label'],
                'icon' => $definition['icon'],
                'route' => $route,
                'url' => route($route),
                'active' => $request->routeIs($route, $route.'.*'),
            ];
        }

        return $items;
    }

    /**
     * Other surfaces this person may switch to (for the account menu), excluding the current one.
     *
     * @return list<array{surface: string, label: string, url: string}>
     */
    public function switches(string $current, ?User $user): array
    {
        if ($user === null) {
            return [];
        }

        return array_values(array_map(
            fn (string $surface) => [
                'surface' => $surface,
                'label' => RoleHome::LABELS[$surface],
                'url' => route(RoleHome::HOME_ROUTES[$surface]),
            ],
            array_filter($this->roles->surfaces($user), fn (string $surface) => $surface !== $current),
        ));
    }

    /** @param list<string> $routes */
    private function firstExistingRoute(array $routes): ?string
    {
        foreach ($routes as $route) {
            if (Route::has($route)) {
                return $route;
            }
        }

        return null;
    }

    private function allowed(?string $requirement, ?User $user): bool
    {
        return match ($requirement) {
            null => true,
            'operator' => $user !== null && $this->roles->isOperator($user),
            'driver' => $user !== null && $this->roles->isDriver($user),
            'fleet' => $user !== null && $this->roles->ownsFleet($user),
            default => false,
        };
    }
}
