@php
    $c = $board['counts'];
    $attention = ($c['needs_review'] ?? 0) + ($c['stuck'] ?? 0);
    $finding = match (true) {
        ($c['incidents'] ?? 0) > 0 => [($c['incidents']).' safety '.\Illuminate\Support\Str::plural('incident', $c['incidents']).' open. Safety comes first.', 'stop'],
        $attention > 0 => [$attention.' '.\Illuminate\Support\Str::plural('ride', $attention).' '.($attention === 1 ? 'needs' : 'need').' a decision: '.($c['needs_review'] ?? 0).' flagged for review, '.($c['stuck'] ?? 0).' stuck.', 'stop'],
        ($c['searching'] ?? 0) > 0 => [($c['searching']).' '.\Illuminate\Support\Str::plural('passenger', $c['searching']).' waiting for a driver.', 'caution'],
        default => ['Nothing needs a decision right now.', null],
    };
@endphp
<x-ops-layout title="Live">
    <x-slot:head>@include('ops.partials.assets')</x-slot:head>
    <div class="ops" data-ops-live data-feed="{{ route('ops.live.feed') }}">
        <script type="application/json" data-live-initial>@json($board)</script>
        <header class="ops-head">
            <div class="ops-head__text">
                <h1>Live</h1>
                <p class="ops-head__finding" @if($finding[1]) data-tone="{{ $finding[1] }}" @endif>{{ $finding[0] }}</p>
            </div>
            <div class="ops-head__actions">
                <span class="ops-stamp" data-live-stamp>Updated {{ \Illuminate\Support\Carbon::parse($board['generated_at'])->copy()->setTimezone(\App\Support\Ui\LocalTime::timezone())->format('H:i:s') }}</span>
                @can('ops.rides.view')<x-eh.button size="sm" variant="secondary" :href="route('ops.rides')" icon="list">All rides</x-eh.button>@endcan
            </div>
        </header>

        @include('ops.partials.takeover', ['items' => $board['takeover'] ?? []])

        <dl class="ops-figures" aria-label="Right now">
            <div data-tone="{{ ($c['searching'] ?? 0) > 0 ? 'caution' : '' }}"><dt>Waiting for a driver</dt><dd data-live-count="searching">{{ $c['searching'] }}</dd></div>
            <div><dt>Rides with a driver</dt><dd data-live-count="on_trip">{{ $c['on_trip'] }}</dd></div>
            <div data-tone="{{ ($c['needs_review'] ?? 0) > 0 ? 'stop' : '' }}"><dt>Needs review</dt><dd data-live-count="needs_review">{{ $c['needs_review'] }}</dd></div>
            <div data-tone="{{ ($c['stuck'] ?? 0) > 0 ? 'stop' : '' }}"><dt>Stuck</dt><dd data-live-count="stuck">{{ $c['stuck'] }}</dd></div>
            <div><dt>Drivers online, free</dt><dd data-live-count="drivers_online">{{ $c['drivers_online'] }}</dd></div>
            <div><dt>Drivers on a trip</dt><dd data-live-count="drivers_on_trip">{{ $c['drivers_on_trip'] }}</dd></div>
            <div><dt title="No heartbeat within {{ config('ehail_dispatch.heartbeat_stale_seconds', 45) }} seconds: not offered rides">No signal</dt><dd data-live-count="drivers_stale">{{ $c['drivers_stale'] }}</dd></div>
            @isset($c['incidents'])
                <div data-tone="{{ $c['incidents'] > 0 ? 'stop' : '' }}"><dt><a href="{{ route('ops.safety') }}">Open incidents</a></dt><dd data-live-count="incidents">{{ $c['incidents'] }}</dd></div>
            @endisset
        </dl>

        <div class="ops-live">
            <section class="ops-panel ops-panel--flush" aria-labelledby="live-map-h">
                <div class="ops-panel__head" style="padding-bottom:var(--eh-space-3)">
                    <h2 id="live-map-h">Map</h2>
                    <ul class="ops-legend" aria-label="Map key">
                        @if ($board['positions_allowed'])
                            <li><span class="ops-dot ops-dot--driver"></span>Driver, free</li>
                            <li><span class="ops-dot ops-dot--busy"></span>Driver with a ride</li>
                            <li><span class="ops-dot ops-dot--stale"></span>No signal</li>
                        @endif
                        <li><span class="ops-dot ops-dot--pickup"></span>Pickup of an open ride</li>
                        <li><span class="ops-dot ops-dot--review"></span>Needs review</li>
                        <li data-sos-legend @if (empty($board['takeover'])) hidden @endif><span class="ops-dot ops-dot--sos"></span>Emergency</li>
                    </ul>
                </div>
                <div class="ops-map" data-eh-map data-map-state="pending">
                    <div data-eh-map-canvas aria-label="Map of open rides{{ $board['positions_allowed'] ? ' and online drivers' : '' }}"></div>
                    <p class="ops-map__status" data-ops-map-status role="status">Loading the map.</p>
                </div>
            </section>

            <div class="ops-stack">
                <section class="ops-panel ops-panel--flush" aria-labelledby="q-review-h">
                    <div class="ops-panel__head"><h2 id="q-review-h">Needs review</h2><span class="ops-panel__meta">oldest first</span></div>
                    @include('ops.partials.queue', ['key' => 'needs_review', 'items' => $board['queues']['needs_review'], 'empty' => 'Nothing is flagged for review.'])
                </section>
                <section class="ops-panel ops-panel--flush" aria-labelledby="q-stuck-h">
                    <div class="ops-panel__head"><h2 id="q-stuck-h">Stuck</h2><span class="ops-panel__meta" title="Assigned or arriving 20 min, at pickup 15 min, on trip 3 h, searching past the window">no change past the limit</span></div>
                    @include('ops.partials.queue', ['key' => 'stuck', 'items' => $board['queues']['stuck'], 'empty' => 'No ride is stuck.'])
                </section>
                <section class="ops-panel ops-panel--flush" aria-labelledby="q-search-h">
                    <div class="ops-panel__head"><h2 id="q-search-h">Waiting for a driver</h2><span class="ops-panel__meta">window {{ config('ehail.ride_expiry_minutes', 10) }} min</span></div>
                    @include('ops.partials.queue', ['key' => 'searching', 'items' => $board['queues']['searching'], 'empty' => 'No passenger is waiting.'])
                </section>
            </div>
        </div>
    </div>
</x-ops-layout>
