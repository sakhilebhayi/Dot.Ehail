{{-- Trips: the ride going now, scheduled rides, and history with fares and receipts. --}}
@php
    $fmt = fn ($minor, $cur) => \App\Support\Ui\MoneyFormatter::format((int) $minor, $cur ?: 'ZAR');
    $tz = config('app.timezone');
@endphp
<x-passenger-layout title="Trips">
    <x-slot:head>@vite(['resources/css/passenger.css'])</x-slot:head>
    <x-slot:header>
        <div class="eh-pagehead__text"><h1 class="eh-h1">Trips</h1></div>
        <x-eh.button :href="route('ride.home')" icon="plus">Book a ride</x-eh.button>
    </x-slot:header>

    <div class="pax-page">
        @if ($active)
            <section aria-labelledby="trips-now">
                <h2 id="trips-now">Happening now</h2>
                <ul class="eh-list">
                    <li>
                        <a class="pax-trip-row" href="{{ route('ride.trip', $active) }}">
                            <span class="pax-trip-row__route">{{ $active->pickup_address }} to {{ $active->dropoff_address }}</span>
                            <span class="pax-trip-row__meta"><x-eh.status :status="$active->status" /></span>
                            <span class="pax-trip-row__money"><x-eh.icon name="chevron-right" /></span>
                        </a>
                    </li>
                </ul>
            </section>
        @endif

        <section aria-labelledby="trips-scheduled">
            <h2 id="trips-scheduled">Scheduled</h2>
            @if ($scheduled->isEmpty())
                <p class="pax-lead">No rides booked ahead. Choose "Later" when you book to schedule one {{ $schedule['min_lead_minutes'] }} minutes to {{ $schedule['max_lead_days'] }} days ahead.</p>
            @else
                <ul class="eh-list">
                    @foreach ($scheduled as $ride)
                        @php $phase = \App\Domain\Rides\ScheduledRides::phase($ride); @endphp
                        <li>
                            <a class="pax-trip-row" href="{{ route('ride.trip', $ride) }}">
                                <span class="pax-trip-row__route">{{ $ride->scheduled_for?->timezone($tz)->format('D j M, H:i') }}: {{ \Illuminate\Support\Str::before((string) $ride->dropoff_address, ',') }}</span>
                                <span class="pax-trip-row__meta">
                                    <x-eh.badge :tone="$phase === 'driver_confirmed' ? 'go' : 'caution'">{{ $phase === 'driver_confirmed' ? 'Driver confirmed' : 'Awaiting driver' }}</x-eh.badge>
                                    <span>From {{ \Illuminate\Support\Str::before((string) $ride->pickup_address, ',') }}</span>
                                </span>
                                <span class="pax-trip-row__money">@if($ride->quoted_total_minor !== null)<x-eh.money :minor="$ride->quoted_total_minor" :currency="$ride->currency ?? 'ZAR'" />@endif</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section aria-labelledby="trips-history">
            <h2 id="trips-history">Past trips</h2>
            @if ($history->isEmpty())
                <x-eh.empty title="No trips yet" :heading-level="3">
                    <p style="margin:0">Your trips appear here with the fare you were quoted, what you paid and the receipt.</p>
                    <x-slot:actions><x-eh.button :href="route('ride.home')">Book your first ride</x-eh.button></x-slot:actions>
                </x-eh.empty>
            @else
                <ul class="eh-list">
                    @foreach ($history as $ride)
                        @php
                            $minor = $ride->final_fare_minor ?? $ride->cancellation_fee_minor ?? $ride->quoted_total_minor;
                            $receipt = $receipts->get($ride->id);
                        @endphp
                        <li>
                            <div class="pax-trip-row">
                                <a class="pax-trip-row__route eh-link" href="{{ route('ride.trip', $ride) }}" style="text-decoration:none;color:inherit">{{ \Illuminate\Support\Str::before((string) $ride->pickup_address, ',') ?: 'Pickup' }} to {{ \Illuminate\Support\Str::before((string) $ride->dropoff_address, ',') ?: 'destination' }}</a>
                                <span class="pax-trip-row__meta">
                                    <span>{{ ($ride->completed_at ?? $ride->requested_at ?? $ride->created_at)?->timezone($tz)->format('j M Y, H:i') }}</span>
                                    <x-eh.status :status="$ride->status" />
                                    @if ($ride->status === 'completed' && in_array($ride->payment_status, ['failed', 'unpaid'], true))
                                        <x-eh.badge tone="stop">{{ $ride->paymentStatusEnum()->label() }}</x-eh.badge>
                                    @endif
                                </span>
                                <span class="pax-trip-row__money">
                                    @if ($minor !== null)<x-eh.money :minor="$minor" :currency="$ride->currency ?? 'ZAR'" />@endif
                                    @if ($receipt)<a class="eh-link" href="{{ route('money.receipts.show', $receipt) }}">Receipt {{ $receipt->number }}</a>@endif
                                </span>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section aria-labelledby="trips-help">
            <h2 id="trips-help">Something wrong with a trip?</h2>
            <p class="pax-lead">Lost something, a fare that looks wrong, or anything else: open a help case and follow it here.</p>
            <div class="pax-actions pax-actions--row">
                <x-eh.button variant="secondary" icon="lifebuoy" :href="route('ride.support.create')">Get help</x-eh.button>
                <x-eh.button variant="ghost" :href="route('ride.support')">Your help cases</x-eh.button>
            </div>
        </section>
    </div>
</x-passenger-layout>
