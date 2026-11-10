{{-- Trip history: rides this driver drove, newest first. --}}
<x-driver-layout title="Trips">
    <x-slot:head>
        @vite(['resources/css/driver.css'])
    </x-slot:head>
    <x-slot:header>
        <div class="dr-head"><h1>Trips</h1></div>
    </x-slot:header>
    <div class="dr-page">
        @if ($rides->isEmpty())
            <x-eh.empty title="No trips yet" :pending="false">
                <p style="margin:0">Trips you accept appear here with the final fare, what you earned and a way to report a problem.</p>
                <x-slot:actions><x-eh.button size="driver" :href="route('drive.home')" icon="steering-wheel">Go to the console</x-eh.button></x-slot:actions>
            </x-eh.empty>
        @else
            <ul class="eh-list">
                @foreach ($rides as $ride)
                    @php($amount = $ride->final_fare_minor ?? $ride->quoted_total_minor)
                    <li>
                        <a class="dr-trip-row" href="{{ route('drive.trips.show', $ride) }}">
                            <span class="dr-trip-row__title">{{ \Illuminate\Support\Str::limit($ride->dropoff_address, 56) }}</span>
                            <span class="dr-trip-row__meta">From {{ \Illuminate\Support\Str::limit($ride->pickup_address, 40) }}, {{ \App\Http\Controllers\Driver\Support\Local::at($ride->completed_at ?? $ride->cancelled_at ?? $ride->requested_at ?? $ride->created_at)?->isoFormat('D MMM HH:mm') }}</span>
                            <span class="dr-trip-row__amount">
                                @if ($amount !== null)<x-eh.money :minor="$amount" :currency="$ride->currency ?? 'ZAR'" />@endif
                                <x-eh.status :status="$ride->status" />
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>
            {{ $rides->links() }}
        @endif
    </div>
</x-driver-layout>
