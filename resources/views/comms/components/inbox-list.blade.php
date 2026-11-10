{{--
    The inbox as a route line: each notification is a stop, grouped by day. Unread stops are filled; read ones are rings.
    Opening an item marks it read and goes to its page. Works without script (each row is a small form).
    <x-comms::inbox-list :items="$items" route-prefix="ride" />
--}}
@props(['items' => [], 'routePrefix' => 'ride'])
@php
    $tz = config('ehail_pricing.default_timezone', 'Africa/Johannesburg');
    $groups = collect($items)->groupBy(function ($item) use ($tz) {
        $at = \Illuminate\Support\Carbon::parse($item['created_at'])->setTimezone($tz);
        return match (true) {
            $at->isToday() => 'Today',
            $at->isYesterday() => 'Yesterday',
            default => $at->format('l j F'),
        };
    });
@endphp
<div class="comms-inbox">
    @foreach ($groups as $day => $dayItems)
        <section class="comms-day" aria-labelledby="comms-day-{{ $loop->index }}">
            <h2 class="comms-day__title" id="comms-day-{{ $loop->index }}">{{ $day }}</h2>
            <ol class="comms-line">
                @foreach ($dayItems as $item)
                    @php
                        $at = \Illuminate\Support\Carbon::parse($item['created_at'])->setTimezone($tz);
                        $others = collect($item['deliveries'])->pluck('label')->all();
                    @endphp
                    <li class="comms-stop {{ $item['read'] ? 'is-read' : 'is-unread' }} {{ $item['critical'] ? 'is-critical' : '' }}">
                        <span class="comms-stop__dot" aria-hidden="true"></span>
                        <div class="comms-stop__body">
                            <p class="comms-stop__meta">
                                <time datetime="{{ $item['created_at'] }}">{{ $at->format('H:i') }}</time>
                                <span>{{ $item['category_label'] }}</span>
                                @if (! $item['read'])<span class="eh-sr-only">, unread</span>@endif
                            </p>
                            <h3 class="comms-stop__title">
                                {{ $item['title'] }}
                                @if ($item['critical'])<x-eh.badge tone="stop" icon="shield">Safety</x-eh.badge>@endif
                                @if ($item['expired'])<x-eh.badge tone="neutral">Expired</x-eh.badge>@endif
                            </h3>
                            @if ($item['message'] !== '')<p class="comms-stop__text">{{ $item['message'] }}</p>@endif
                            @if (count($others))
                                <p class="comms-stop__channels">{{ implode('. ', $others) }}.</p>
                            @endif
                            @if ($item['url'] || ! $item['read'])
                                <div class="comms-stop__actions">
                                    @if ($item['url'] && ! $item['expired'])
                                        <form method="POST" action="{{ route($routePrefix.'.notifications.read', $item['id']) }}">
                                            @csrf
                                            <input type="hidden" name="open" value="1">
                                            <x-eh.button type="submit" size="sm" variant="secondary" icon-right="chevron-right">Open</x-eh.button>
                                        </form>
                                    @endif
                                    @if (! $item['read'])
                                        <form method="POST" action="{{ route($routePrefix.'.notifications.read', $item['id']) }}">
                                            @csrf
                                            <x-eh.button type="submit" size="sm" variant="ghost">Mark read</x-eh.button>
                                        </form>
                                    @endif
                                </div>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ol>
        </section>
    @endforeach
</div>
