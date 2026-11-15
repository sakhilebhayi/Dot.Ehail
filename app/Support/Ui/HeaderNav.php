<?php

namespace App\Support\Ui;

use App\Domain\Comms\Inbox;
use App\Http\Controllers\Ehail\Comms\CommsPagesController;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Laravel\Jetstream\Jetstream;

/**
 * What the shared dot-ui header (R-52) prints for each Dot.Ehail shell, built from the same Navigation the bottom tab
 * bar and the side drawers already use, so no destination appears that the role cannot reach.
 *
 *  - primary(): the few daily destinations of the top bar (passenger and driver shells). The side shells keep their own
 *    grouped sidebar and pass the header no items; the account and the safety control are not repeated here.
 *  - account(): the account menu (and the phone drawer's account section): who, notifications with the unread count,
 *    account and security, the team switch, the other surfaces this person may enter, sign out.
 */
final class HeaderNav
{
    public function __construct(private readonly Navigation $navigation) {}

    /**
     * @return list<array{label: string, href: string, current: bool}>
     */
    public function primary(string $surface, ?User $user): array
    {
        if (! in_array($surface, ['passenger', 'driver'], true)) {
            return [];
        }

        // The account has its own menu on the right and Safety its own button, so neither is repeated as a link.
        $items = collect($this->navigation->items($surface, $user))
            ->reject(fn (array $item) => in_array($item['key'], ['account', 'safety'], true))
            ->map(fn (array $item) => ['label' => $item['label'], 'href' => $item['url'], 'current' => $item['active']])
            ->values()
            ->all();

        if ($surface === 'passenger' && Route::has('ride.support')) {
            $items[] = ['label' => 'Help', 'href' => route('ride.support'), 'current' => request()->routeIs('ride.support', 'ride.support.*')];
        }

        return $items;
    }

    /**
     * @return array{name: string, email: string, team: ?string, links: list<array<string, mixed>>, signOut: array{action: string}, theme: bool}
     */
    public function account(string $surface, User $user, bool $theme = true): array
    {
        $links = [];

        $accountRoute = ['passenger' => 'ride.account', 'driver' => 'drive.account'][$surface] ?? null;
        if ($accountRoute !== null && Route::has($accountRoute)) {
            $links[] = ['label' => $surface === 'driver' ? 'Driver account' : 'Account', 'href' => route($accountRoute), 'current' => request()->routeIs($accountRoute)];
        }

        $notifications = $this->notificationsRoute($surface);
        if ($notifications !== null) {
            $count = Inbox::countFor($user);
            $links[] = ['label' => 'Notifications', 'href' => route($notifications), 'badge' => $count > 0 ? Inbox::badge($count) : null, 'current' => request()->routeIs($notifications)];
        }

        $links[] = ['label' => 'Profile and security', 'href' => route('profile.show'), 'current' => request()->routeIs('profile.show')];

        if (Jetstream::hasTeamFeatures() && $user->currentTeam) {
            $links[] = ['label' => 'Team settings', 'href' => route('teams.show', $user->currentTeam->id), 'current' => request()->routeIs('teams.show')];

            $teams = $user->allTeams();
            if ($teams->count() > 1) {
                $links[] = ['heading' => 'Switch team'];
                foreach ($teams as $team) {
                    $links[] = ['label' => $team->name, 'current' => $user->isCurrentTeam($team), 'form' => ['action' => route('current-team.update'), 'method' => 'PUT', 'fields' => ['team_id' => $team->id]]];
                }
            }
        }

        $switches = $this->navigation->switches($surface, $user);
        if ($switches !== []) {
            $links[] = ['heading' => 'Switch to'];
            foreach ($switches as $switch) {
                $links[] = ['label' => $switch['label'], 'href' => $switch['url']];
            }
        }

        return [
            'name' => $user->name,
            'email' => $user->email,
            'team' => $user->currentTeam?->name,
            'links' => $links,
            'signOut' => ['action' => route('logout')],
            'theme' => $theme,
        ];
    }

    private function notificationsRoute(string $surface): ?string
    {
        $prefix = CommsPagesController::SURFACE_ROUTES[$surface] ?? null;
        $route = $prefix !== null ? $prefix.'.notifications' : null;

        return $route !== null && Route::has($route) ? $route : null;
    }
}
