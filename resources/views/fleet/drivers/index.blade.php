@php
    $rows = collect($performance['rows']);
    $periodLabel = ['day' => 'today', 'week' => 'this week', 'month' => 'this month'][$period];
    $pct = fn (?int $bp) => $bp === null ? null : number_format($bp / 100, 0).'%';
    $hours = fn (?int $s) => $s === null ? null : (intdiv($s, 3600).' h '.str_pad((string) intdiv($s % 3600, 60), 2, '0', STR_PAD_LEFT).' m');
    $stateLabel = ['online' => 'Online', 'on_trip' => 'On a trip', 'paused' => 'Paused', 'offline' => 'Offline'];
@endphp
<x-fleet.page :fleet="$fleet" :fleets="$fleets" title="Drivers"
    :line="$pending->isNotEmpty() ? $pending->count().' '.\Illuminate\Support\Str::plural('application', $pending->count()).' waiting for a decision.' : $rows->where('status', 'approved')->count().' approved '.\Illuminate\Support\Str::plural('driver', $rows->where('status', 'approved')->count()).', '.$performance['fleet']['trips'].' trips '.$periodLabel.'.'">

    @if ($pending->isNotEmpty())
        <section class="fl-panel" aria-labelledby="pending-title">
            <div class="fl-panel__head">
                <h2 id="pending-title">Applications waiting</h2>
                <span class="fl-panel__meta">{{ $masked ? 'Identity numbers are masked.' : 'You see each applicant\'s name and verification status; operations check identity documents.' }}</span>
            </div>
            <ul class="fl-items">
                @foreach ($pending as $application)
                    <li style="grid-template-columns: auto minmax(0,1fr)">
                        <x-eh.avatar :name="$application->user?->name ?? 'Driver'" :size="36" />
                        <div class="fl-stack fl-stack--tight">
                            <div>
                                <p class="fl-items__title">{{ $application->user?->name }}</p>
                                <p class="fl-items__who">Applied {{ $application->created_at->format('j M Y') }}@if ($masked) · Licence {{ $mask($application->license_number) }} · ID {{ $mask($application->id_number) }}@endif</p>
                            </div>
                            @if ($canDecide($application))
                                <div class="fl-inline">
                                    <form method="POST" action="{{ route('fleet.applications.approve', $application->id) }}" class="fl-inline">
                                        @csrf
                                        <x-eh.button type="submit" size="sm" icon="check">Approve</x-eh.button>
                                    </form>
                                    <details class="fl-disclose">
                                        <summary>Decline</summary>
                                        <form method="POST" action="{{ route('fleet.applications.reject', $application->id) }}" class="fl-inline">
                                            @csrf
                                            <label class="eh-sr-only" for="reason-{{ $application->id }}">Reason for declining</label>
                                            <input id="reason-{{ $application->id }}" class="eh-input" name="reason" required maxlength="500" placeholder="Reason, recorded with the decision">
                                            <x-eh.button type="submit" size="sm" variant="danger">Decline</x-eh.button>
                                        </form>
                                    </details>
                                </div>
                            @else
                                <p class="fl-items__detail">{{ $fleet->isApproved() ? 'You cannot decide this application (you may be the applicant, or your role does not include drivers).' : 'Operations decide applications while the fleet is in review.' }}</p>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <section class="fl-panel fl-panel--flush" aria-labelledby="drivers-title">
        <div class="fl-panel__head">
            <h2 id="drivers-title">Fleet drivers</h2>
            <nav class="fl-seg" aria-label="Period">
                @foreach (['day' => 'Today', 'week' => 'This week', 'month' => 'This month'] as $key => $label)
                    <a href="{{ route('fleet.drivers', ['period' => $key]) }}" @if($period === $key) aria-current="page" @endif>{{ $label }}</a>
                @endforeach
            </nav>
        </div>
        @if ($rows->isEmpty())
            <div style="padding: 0 var(--eh-space-5) var(--eh-space-4)">
                <x-eh.empty title="No drivers yet" :heading-level="3">
                    <p style="margin:0">{{ $fleet->isApproved() ? 'Drivers join by applying to this fleet in the driver app; you approve them here.' : 'Once operations approve the fleet, drivers can apply to join it.' }}</p>
                </x-eh.empty>
            </div>
        @else
            <div class="fl-table-wrap">
                <table class="fl-table fl-table--stack">
                    <thead>
                        <tr>
                            <th scope="col">Driver</th><th scope="col">Now</th><th scope="col">Vehicle</th>
                            <th scope="col" class="num">Trips</th><th scope="col" class="num">Cancelled</th><th scope="col" class="num">Completion</th>
                            <th scope="col" class="num">Acceptance</th><th scope="col">Rating</th><th scope="col" class="num">Time online</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            @php($state = $states->get($row['driver_id'])?->state ?? 'offline')
                            <tr>
                                <td><a class="fl-name" href="{{ route('fleet.drivers.show', $row['profile_id']) }}">{{ $row['name'] }}</a>@if ($row['status'] === 'suspended')<span class="fl-sub">Suspended by operations</span>@endif</td>
                                <td><x-eh.badge :tone="$state === 'offline' ? 'neutral' : 'go'">{{ $stateLabel[$state] ?? ucfirst($state) }}</x-eh.badge></td>
                                <td data-label="Has"><span class="fl-val">
                                    @forelse ($holding->get($row['profile_id'], collect()) as $assignment)
                                        <span class="fl-plate">{{ $assignment->vehicle?->plate_number }}</span>
                                    @empty
                                        <span class="fl-dash">Own vehicle or none</span>
                                    @endforelse
                                </span></td>
                                <td class="num" data-label="Trips">{{ $row['trips'] }}</td>
                                <td class="num" data-label="Cancelled">{{ $row['cancellations'] }}</td>
                                <td class="num" data-label="Completion">{!! $pct($row['completion_bp']) ?? '<span class="fl-dash">&#8212;</span>' !!}</td>
                                <td class="num" data-label="Acceptance"><span class="fl-val">{!! $pct($row['acceptance_bp']) ? e($pct($row['acceptance_bp'])).'<span class="fl-sub">'.$row['offers'].' '.\Illuminate\Support\Str::plural('offer', (int) $row['offers']).'</span>' : '<span class="fl-dash">&#8212;</span>' !!}</span></td>
                                <td data-label="Rating"><span class="fl-val">{{ $row['rating']['label'] }}<span class="fl-sub">{{ $row['rating']['count'] }} {{ \Illuminate\Support\Str::plural('rating', $row['rating']['count']) }}</span></span></td>
                                <td class="num" data-label="Online">{!! $hours($row['online_seconds']) ? e($hours($row['online_seconds'])) : '<span class="fl-dash">Not measured</span>' !!}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <td>Fleet</td><td></td><td></td>
                            <td class="num" data-label="Trips">{{ $performance['fleet']['trips'] }}</td>
                            <td class="num" data-label="Cancelled">{{ $performance['fleet']['cancellations'] }}</td>
                            <td class="num" data-label="Completion">{!! $pct($performance['fleet']['completion_bp']) ?? '<span class="fl-dash">&#8212;</span>' !!}</td>
                            <td></td><td></td>
                            <td class="num" data-label="Online">{!! $hours($performance['fleet']['online_seconds']) ? e($hours($performance['fleet']['online_seconds'])) : '<span class="fl-dash">Not measured</span>' !!}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif
    </section>

    <p class="fl-note">
        Trips are rides completed {{ $periodLabel }}. Cancelled counts rides a driver cancelled or handed back after taking them. Completion is trips out of trips plus cancellations; acceptance is offers accepted out of offers answered or left to lapse. Ratings show a band, never a score, once a driver has {{ config('ehail_safety.ratings.min_count_for_band', 5) }} ratings.
        Time online is time out of the offline state, measured {{ $performance['measured_since'] ? 'since '.$performance['measured_since']->format('j M Y') : 'from the first time a driver goes online after this release' }}; a driver whose app has closed counts as online until they go offline.
    </p>

    @if ($rejected->isNotEmpty())
        <section class="fl-panel" aria-labelledby="declined-title">
            <div class="fl-panel__head"><h2 id="declined-title">Recently declined</h2></div>
            <ul class="fl-items">
                @foreach ($rejected as $profile)
                    <li style="grid-template-columns: minmax(0,1fr) auto">
                        <div><p class="fl-items__title">{{ $profile->user?->name }}</p><p class="fl-items__detail">{{ $profile->rejected_reason }}</p></div>
                        <p class="fl-items__who">{{ $profile->reviewed_at?->format('j M Y') }}</p>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</x-fleet.page>
