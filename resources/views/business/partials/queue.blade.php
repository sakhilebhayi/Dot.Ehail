{{-- Approval requests to decide: rider, amount, route, purpose, time left, approve or decline with an optional note. --}}
@php
    $tz = config('ehail_business.timezone');
@endphp
<ul class="bz-queue">
    @foreach ($items as $approval)
        <li>
            <div class="bz-queue__top">
                <x-eh.avatar :name="$approval->riderLabel()" :size="36" />
                <div class="bz-queue__who">
                    <p class="bz-queue__name">{{ $approval->riderLabel() }}</p>
                    <p class="bz-queue__meta">{{ $approval->purpose ?: 'No purpose given' }}@if ($approval->category_code) · {{ \App\Domain\Business\CategoryNames::for($approval->category_code) }}@endif @if ($approval->trip_at) · for {{ $approval->trip_at->setTimezone($tz)->format('j M H:i') }}@endif</p>
                </div>
                <div class="bz-queue__amount">
                    <x-eh.money :minor="$approval->estimate_minor" :currency="$approval->currency" />
                    <span class="bz-queue__lapse">Lapses at {{ $approval->decide_by->setTimezone($tz)->format('H:i') }}</span>
                </div>
            </div>
            @php
                $from = \App\Support\Ui\PlaceNames::for($approval->pickup_address, $approval->pickup_lat, $approval->pickup_lng);
                $to = \App\Support\Ui\PlaceNames::for($approval->dropoff_address, $approval->dropoff_lat, $approval->dropoff_lng);
                $stops = ['pickup' => ['lat' => (float) $approval->pickup_lat, 'lng' => (float) $approval->pickup_lng], 'dropoff' => ['lat' => (float) $approval->dropoff_lat, 'lng' => (float) $approval->dropoff_lng]];
            @endphp
            <div class="bz-queue__place">
                <p class="bz-queue__route">
                    <span><i class="bz-stop bz-stop--pickup" aria-hidden="true"></i><span class="eh-sr-only">From </span>{{ $from }}</span>
                    <span><i class="bz-stop bz-stop--dropoff" aria-hidden="true"></i><span class="eh-sr-only">To </span>{{ $to }}</span>
                </p>
                {{-- A small map of the two stops (R-46 polish, D-2203), mounted when it scrolls into view. --}}
                <div class="eh-stopmap bz-queue__map" data-eh-stopmap data-map='@json($stops)'>
                    <div data-eh-map-canvas role="img" aria-label="Map of the trip from {{ $from }} to {{ $to }}"></div>
                    <p class="eh-stopmap__status" data-eh-stopmap-status role="status"></p>
                </div>
            </div>
            <div class="bz-queue__actions">
                <form method="POST" action="{{ route('business.approvals.approve', $approval->id) }}">
                    @csrf
                    <label class="eh-sr-only" for="note-{{ $approval->id }}">Note for {{ $approval->riderLabel() }}</label>
                    <input class="bz-queue__note" id="note-{{ $approval->id }}" name="note" maxlength="300" placeholder="Note (optional)">
                    <x-eh.button type="submit" size="sm" icon="check">Approve</x-eh.button>
                    <x-eh.button type="submit" size="sm" variant="secondary" formaction="{{ route('business.approvals.decline', $approval->id) }}">Decline</x-eh.button>
                </form>
            </div>
        </li>
    @endforeach
</ul>
