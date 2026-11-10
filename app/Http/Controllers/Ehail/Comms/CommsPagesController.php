<?php

namespace App\Http\Controllers\Ehail\Comms;

use App\Domain\Comms\ChannelStatus;
use App\Domain\Comms\CommsCategories;
use App\Domain\Comms\Inbox;
use App\Domain\Comms\PreferenceLocked;
use App\Domain\Comms\Preferences;
use App\Domain\Comms\Push\PushSubscriptions;
use App\Domain\Comms\Push\VapidKeys;
use App\Http\Controllers\Controller;
use App\Support\Ui\RoleHome;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Inbox and notification settings pages inside the passenger, driver and ops shells. The pages work
 * without script (forms post back); the push switch needs script and says so when it is missing.
 */
class CommsPagesController extends Controller
{
    /** Surface => route name prefix. */
    public const SURFACE_ROUTES = ['passenger' => 'ride', 'driver' => 'drive', 'ops' => 'ops'];

    public function __construct(private readonly Inbox $inbox, private readonly Preferences $preferences) {}

    public function inboxRedirect(Request $request, RoleHome $roles): RedirectResponse
    {
        return redirect()->route(self::routePrefix($roles->primarySurface($request->user())).'.notifications');
    }

    public function settingsRedirect(Request $request, RoleHome $roles): RedirectResponse
    {
        return redirect()->route(self::routePrefix($roles->primarySurface($request->user())).'.notifications.settings');
    }

    public function inbox(Request $request, string $surface): View
    {
        $user = $request->user();
        $page = $this->inbox->page($user, 20, $request->query('show') === 'unread');

        return view('comms.pages.inbox', [
            'surface' => $surface,
            'routePrefix' => self::routePrefix($surface),
            'items' => $this->inbox->present($user, $page->items()),
            'unreadCount' => $this->inbox->unreadCount($user),
            'nextCursor' => $page->nextCursor()?->encode(),
            'showUnread' => $request->query('show') === 'unread',
        ]);
    }

    public function settings(Request $request, string $surface, ChannelStatus $status, VapidKeys $vapid, PushSubscriptions $subscriptions): View
    {
        return view('comms.pages.settings', [
            'surface' => $surface,
            'routePrefix' => self::routePrefix($surface),
            'state' => PreferencesController::state($request->user(), $surface, $this->preferences, $status, $vapid, $subscriptions),
        ]);
    }

    /** No-script form: every switch on the page is posted; an absent box means off. */
    public function saveSettings(Request $request, string $surface, CommsCategories $categories): RedirectResponse
    {
        $request->validate(['prefs' => ['nullable', 'array']]);
        $posted = (array) $request->input('prefs', []);

        foreach ($categories->forSurface($surface) as $category => $definition) {
            if ($categories->isCritical($category)) {
                continue;
            }

            foreach ($categories->channels($category) as $channel) {
                try {
                    $this->preferences->set($request->user(), $category, $channel, ! empty($posted[$category][$channel]));
                } catch (PreferenceLocked) {
                }
            }
        }

        return redirect()->route(self::routePrefix($surface).'.notifications.settings')->with('status', 'Notification settings saved.');
    }

    public function markRead(Request $request, string $surface, string $notification): RedirectResponse
    {
        $record = $request->user()->notifications()->whereKey($notification)->firstOrFail();
        $record->markAsRead();

        $target = Inbox::localUrl((string) ($record->data['url'] ?? ''));

        return $request->boolean('open') && $target !== null
            ? redirect($target)
            : back()->with('status', 'Marked as read.');
    }

    public function markAllRead(Request $request, string $surface): RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return back()->with('status', 'All notifications marked as read.');
    }

    public static function routePrefix(string $surface): string
    {
        return self::SURFACE_ROUTES[$surface] ?? 'ride';
    }
}
