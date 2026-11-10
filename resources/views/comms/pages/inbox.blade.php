<x-comms::shell :surface="$surface" title="Notifications">
    <x-slot:header>
        <div class="eh-pagehead__text">
            <h1 class="eh-h1">Notifications</h1>
            <p class="eh-small" style="margin:0">
                @if ($unreadCount === 0) Nothing unread. @elseif ($unreadCount === 1) 1 unread. @else {{ $unreadCount }} unread. @endif
            </p>
        </div>
        <div class="comms-headactions">
            @if ($unreadCount > 0)
                <form method="POST" action="{{ route($routePrefix.'.notifications.read-all') }}">
                    @csrf
                    <x-eh.button type="submit" variant="secondary" size="sm" icon="check">Mark all read</x-eh.button>
                </form>
            @endif
            <x-eh.button :href="route($routePrefix.'.notifications.settings')" variant="ghost" size="sm" icon="settings">Settings</x-eh.button>
        </div>
    </x-slot:header>

    <nav class="comms-filter" aria-label="Show">
        <x-eh.chip :href="route($routePrefix.'.notifications')" :current="! $showUnread">All</x-eh.chip>
        <x-eh.chip :href="route($routePrefix.'.notifications', ['show' => 'unread'])" :current="$showUnread">Unread</x-eh.chip>
    </nav>

    @if (count($items) === 0)
        <x-eh.empty :title="$showUnread ? 'Nothing unread' : 'No notifications yet'">
            <p style="margin:0">
                @if ($showUnread)
                    You have read everything. Older notifications stay under All.
                @elseif ($surface === 'driver')
                    Ride offers, trip updates, payouts and document reminders will appear here as they happen.
                @elseif ($surface === 'ops')
                    Safety alerts, driver applications and support escalations will appear here as they happen.
                @else
                    Your driver's progress, payments, receipts and safety messages will appear here as they happen.
                @endif
            </p>
            <x-slot:actions>
                <x-eh.button :href="route($routePrefix.'.notifications.settings')" variant="secondary" icon="settings">Choose how we reach you</x-eh.button>
            </x-slot:actions>
        </x-eh.empty>
    @else
        <x-comms::inbox-list :items="$items" :route-prefix="$routePrefix" />
        @if ($nextCursor)
            <div class="comms-more">
                <x-eh.button :href="route($routePrefix.'.notifications', array_filter(['cursor' => $nextCursor, 'show' => $showUnread ? 'unread' : null]))" variant="secondary" icon="history">Older notifications</x-eh.button>
            </div>
        @endif
    @endif
</x-comms::shell>
