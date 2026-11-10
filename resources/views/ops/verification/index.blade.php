@php
    $total = ($pendingBySubject['driver'] ?? 0) + ($pendingBySubject['vehicle'] ?? 0);
@endphp
<x-ops-layout title="Verification">
    <x-slot:head>@include('ops.partials.assets')</x-slot:head>
    <div class="ops">
        <header class="ops-head">
            <div class="ops-head__text">
                <h1>Verification</h1>
                <p class="ops-head__finding" @if($total > 0) data-tone="caution" @endif>{{ $total }} {{ \Illuminate\Support\Str::plural('document', $total) }} waiting for review, {{ $applications }} {{ \Illuminate\Support\Str::plural('application', $applications) }} pending. Approved drivers stay offline until every required document is approved.</p>
            </div>
        </header>
        @include('ops.verification.tabs', ['active' => $subject])

        @if ($subject === 'applications')
            <section class="ops-panel ops-panel--flush" aria-label="Pending applications">
                @if ($profiles->isEmpty())
                    <p class="ops-none" style="padding:var(--eh-space-5)">No application is pending.</p>
                @else
                    <div class="ops-tablewrap"><table class="ops-table">
                        <thead><tr><th>Applicant</th><th>Applied as</th><th>Who decides</th><th class="num">Waiting</th></tr></thead>
                        <tbody>
                            @foreach ($profiles as $profile)
                                <tr>
                                    <td><a href="{{ route('ops.drivers.show', $profile->user_id) }}">{{ $profile->user?->name }}</a><span class="sub">{{ $profile->user?->email }}</span></td>
                                    <td>{{ $profile->fleet ? 'Fleet driver, '.$profile->fleet->name : 'Independent driver' }}</td>
                                    <td>{{ $profile->fleet ? 'The fleet owner' : 'Operations' }}</td>
                                    <td class="num"><span class="ops-timer" data-ops-since="{{ $profile->created_at?->toIso8601String() }}" data-ops-warn="172800">{{ \App\Domain\Ops\Support\Durations::short((int) $profile->created_at?->diffInSeconds(now(), true)) }}</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table></div>
                    @include('ops.partials.pager', ['page' => $profiles])
                @endif
            </section>
        @else
            <form class="ops-filter" method="GET" action="{{ route('ops.verification') }}" aria-label="Filter the queue">
                <input type="hidden" name="subject" value="{{ $subject }}">
                <x-eh.select label="Assigned" name="assigned" :options="['any' => 'Anyone', 'me' => 'To me', 'unassigned' => 'Nobody yet']" :value="$assigned" />
                <x-eh.button type="submit" size="sm" icon="filter">Apply</x-eh.button>
            </form>
            <section class="ops-panel ops-panel--flush" aria-label="Documents waiting for review">
                @if ($documents->isEmpty())
                    <p class="ops-none" style="padding:var(--eh-space-5)">No {{ $subject }} document is waiting{{ $assigned !== 'any' ? ' with this filter' : '' }}.</p>
                @else
                    <div class="ops-tablewrap"><table class="ops-table">
                        <thead><tr><th>Document</th><th>Driver</th>@if($subject === 'vehicle')<th>Vehicle</th>@endif<th>Assigned</th><th class="num">Expires</th><th class="num">Waiting</th></tr></thead>
                        <tbody>
                            @foreach ($documents as $doc)
                                <tr>
                                    <td><a href="{{ route('ops.verification.show', $doc->id) }}">{{ $types[$doc->type]['label'] ?? str_replace('_', ' ', $doc->type) }}</a></td>
                                    <td>{{ $doc->owner?->name }}</td>
                                    @if($subject === 'vehicle')<td>{{ $doc->vehicle ? $doc->vehicle->plate_number.' · '.trim($doc->vehicle->make.' '.$doc->vehicle->model) : '-' }}</td>@endif
                                    <td>{{ $doc->assignedReviewer?->name ?? 'Nobody' }}</td>
                                    <td class="num">{{ $doc->expires_on?->format('d M Y') ?? '-' }}</td>
                                    <td class="num"><span class="ops-timer" data-ops-since="{{ ($doc->submitted_at ?? $doc->created_at)?->toIso8601String() }}" data-ops-warn="86400">{{ \App\Domain\Ops\Support\Durations::short((int) ($doc->submitted_at ?? $doc->created_at)?->diffInSeconds(now(), true)) }}</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table></div>
                    @include('ops.partials.pager', ['page' => $documents])
                @endif
            </section>
        @endif
    </div>
</x-ops-layout>
