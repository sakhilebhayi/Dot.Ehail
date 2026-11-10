{{-- Help and safety for drivers: SOS on the current trip, emergency numbers, incidents, support, document alerts, ratings. --}}
@php
    $incidentStatus = ['open' => 'Open', 'acknowledged' => 'Being handled', 'escalated' => 'Escalated', 'resolved' => 'Resolved', 'closed' => 'Closed'];
    $caseStatus = ['new' => 'New', 'triaged' => 'Seen', 'in_progress' => 'Being handled', 'waiting_on_user' => 'Waiting for you', 'resolved' => 'Resolved', 'closed' => 'Closed'];
@endphp
<x-driver-layout title="Help and safety">
    <x-slot:head>
        @vite(['resources/css/driver.css', 'resources/js/driver/pages.js'])
    </x-slot:head>
    <x-slot:header>
        <div class="dr-head">
            <h1>Help and safety</h1>
            <p>Dot.Ehail does not dispatch police or ambulances. In danger, call first; then tell us.</p>
        </div>
    </x-slot:header>

    <div class="dr-page">
        <x-eh.banner tone="safety" title="Emergency numbers" emergency>Police 10111, ambulance 10177, any emergency from a mobile 112.</x-eh.banner>

        @if ($activeRide)
            <section class="dr-panel" aria-labelledby="sos-h">
                <h2 id="sos-h">On your current trip</h2>
                <p class="dr-note">SOS records an emergency on this trip with your location, alerts the operations team and your confirmed trusted contacts, then shows exactly what was sent. You get 3 seconds to cancel.</p>
                <button type="button" class="dr-sos-btn" style="min-height:56px;font-size:var(--eh-text-xl)" data-dr-page-sos data-url="{{ route('safety.rides.sos', $activeRide) }}"><x-eh.icon name="sos" />SOS</button>
            </section>
        @endif

        @if (count($alerts))
            <section class="dr-section" aria-labelledby="alerts-h">
                <h2 id="alerts-h">Documents that need you</h2>
                <ul class="dr-docs">
                    @foreach ($alerts as $item)
                        <li class="dr-doc">
                            <div class="dr-doc__head">
                                <p class="dr-doc__name">{{ $item['label'] }}@if($item['vehicle']) <span class="dr-plate">{{ $item['vehicle']->plate_number }}</span>@endif</p>
                                <x-eh.badge :tone="$item['chip']['tone']">{{ $item['chip']['label'] }}</x-eh.badge>
                            </div>
                            @if ($item['expires_on'])<p class="dr-note">{{ $item['satisfied'] ? 'Expires' : 'Expired' }} {{ \App\Http\Controllers\Driver\Support\Local::at($item['expires_on'])->isoFormat('D MMMM YYYY') }}. Upload the renewal before then so you stay online.</p>@endif
                            <a class="dr-textlink" href="{{ route('drive.documents') }}#doc-{{ $item['type'] }}{{ $item['vehicle'] ? '-v'.$item['vehicle']->id : '' }}">Open documents</a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        <section class="dr-section" aria-labelledby="cases-h">
            <h2 id="cases-h">Support</h2>
            @if (count($cases))
                <ul class="eh-list">
                    @foreach ($cases as $case)
                        <li>
                            <a class="dr-trip-row" href="{{ route('drive.safety.case', $case['id']) }}">
                                <span class="dr-trip-row__title">{{ $case['subject'] }}</span>
                                <span class="dr-trip-row__meta">{{ $case['reference'] }}, {{ $case['category_label'] }}, opened {{ \App\Http\Controllers\Driver\Support\Local::at($case['opened_at'])->isoFormat('D MMM') }}</span>
                                <span class="dr-trip-row__amount"><x-eh.badge :tone="$case['status'] === 'waiting_on_user' ? 'caution' : (in_array($case['status'], ['resolved', 'closed'], true) ? 'go' : 'info')">{{ $caseStatus[$case['status']] ?? $case['status'] }}</x-eh.badge></span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="dr-note">No support cases. Questions about a trip are best asked from that trip, so support sees it.</p>
            @endif
            <details class="dr-more" data-dr-typing>
                <summary>Open a support case</summary>
                <form class="dr-form dr-more__body" method="POST" action="{{ route('support.cases.store') }}" data-dr-json data-reload="1" data-done="Your case is open.">
                    @csrf
                    <x-eh.select label="Topic" name="category" :options="collect($categories)->mapWithKeys(fn ($c) => [$c['value'] => $c['label']])->all()" />
                    <x-eh.select label="Trip (if it is about one)" name="ride_id" :options="['' => 'Not about a trip'] + $recentRides->mapWithKeys(fn ($r) => [$r->id => '#'.$r->id.' '.\Illuminate\Support\Str::limit($r->dropoff_address, 40)])->all()" />
                    <x-eh.field label="Subject" name="subject" maxlength="160" required />
                    <x-eh.field label="Message" name="body" type="textarea" maxlength="5000" required />
                    <x-eh.button type="submit" size="driver" variant="secondary">Open case</x-eh.button>
                </form>
            </details>
            <p class="dr-hold dr-alert dr-alert--caution">Messages are paused while you drive.</p>
        </section>

        <section class="dr-section" aria-labelledby="inc-h">
            <h2 id="inc-h">Safety reports you raised</h2>
            @forelse ($incidents as $incident)
                <p class="dr-note"><strong>{{ $incident['reference'] }}</strong> {{ $incident['kind_label'] }}, {{ \App\Http\Controllers\Driver\Support\Local::at($incident['raised_at'])->isoFormat('D MMM HH:mm') }}: {{ $incidentStatus[$incident['status']] ?? $incident['status'] }}.</p>
            @empty
                <p class="dr-note">None.</p>
            @endforelse
        </section>

        @if ($ratings)
            <section class="dr-section" aria-labelledby="rat-h">
                <h2 id="rat-h">Ratings from passengers</h2>
                <p class="dr-note">Your band: {{ $ratings['band']['label'] ?? 'New' }}@if($ratings['average'] !== null) (average {{ number_format($ratings['average'], 2) }}, only you see this number)@endif. You may appeal a rating once, within 30 days.</p>
                @foreach (collect($ratings['data'])->take(10) as $r)
                    <div class="dr-panel">
                        <div class="dr-doc__head">
                            <p class="dr-doc__name">{{ $r['rating'] }} {{ \Illuminate\Support\Str::plural('star', $r['rating']) }}, trip #{{ $r['ride_id'] }}</p>
                            @if ($r['excluded'])<x-eh.badge tone="go">Removed from your average</x-eh.badge>@elseif($r['appeal'])<x-eh.badge tone="info">Appeal {{ $r['appeal']['status'] }}</x-eh.badge>@endif
                        </div>
                        @if ($r['comment'])<p class="dr-note">&ldquo;{{ $r['comment'] }}&rdquo;</p>@endif
                        @if ($r['appeal'] && $r['appeal']['decision_note'])<p class="dr-note">{{ $r['appeal']['decision_note'] }}</p>@endif
                        @if ($r['can_appeal'] && $r['rating'] <= 3)
                            <details data-dr-typing>
                                <summary class="dr-textlink">Appeal this rating</summary>
                                <form class="dr-form" method="POST" action="{{ route('safety.ratings.appeal', $r['id']) }}" data-dr-json data-done="Appeal sent. Operations decides and the result shows here.">
                                    @csrf
                                    <x-eh.field label="Why is this rating unfair?" name="reason" type="textarea" :id="'appeal-'.$r['id']" minlength="10" maxlength="2000" required />
                                    <x-eh.button type="submit" size="driver" variant="secondary">Send appeal</x-eh.button>
                                </form>
                            </details>
                        @endif
                    </div>
                @endforeach
            </section>
        @endif
    </div>

    @if ($activeRide)
        <div class="dr-sos" data-dr-sos hidden role="alertdialog" aria-modal="true" aria-labelledby="dr-sos-title">
            <div data-dr-sos-arming>
                <h2 class="dr-sos__title" id="dr-sos-title">Sending SOS in <span data-dr-sos-count>3</span></h2>
                <p>Dot.Ehail will record an emergency, alert the operations team and your confirmed trusted contacts.</p>
                <button type="button" class="dr-sos__cancel" data-dr-sos-cancel>Cancel</button>
            </div>
            <div data-dr-sos-result hidden>
                <h2 class="dr-sos__title">What we did</h2>
                <ul class="dr-sos__done" data-dr-sos-done></ul>
                <p class="dr-sos__not" data-dr-sos-not></p>
                <x-eh.banner tone="safety" title="Call for help now if you are in danger" emergency />
                <button type="button" class="eh-btn eh-btn--secondary eh-btn--driver eh-btn--block" data-dr-sos-close>Close</button>
            </div>
        </div>
    @endif
</x-driver-layout>
