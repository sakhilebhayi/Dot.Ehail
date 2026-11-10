@php($docState = fn (string $s) => \App\Domain\Fleet\FleetAlerts::stateLabel($s))
<x-fleet.page :fleet="$fleet" :fleets="$fleets" title="Documents"
    :line="$counts['blocking'].' '.\Illuminate\Support\Str::plural('document', $counts['blocking']).' stop someone working, '.$counts['expiring'].' expire within '.config('ehail_fleet.alerts.document_expiry_days').' days, '.$counts['in_order'].' in order.'">
    <x-eh.tabs label="Which documents" :active="$filter" :items="[
        'attention' => ['label' => 'Needs attention', 'href' => route('fleet.documents')],
        'expiring' => ['label' => 'Expiring soon', 'href' => route('fleet.documents', ['show' => 'expiring'])],
        'all' => ['label' => 'All ('.$counts['all'].')', 'href' => route('fleet.documents', ['show' => 'all'])],
    ]" />
    <section class="fl-panel fl-panel--flush" aria-label="Documents">
        @if ($rows->isEmpty())
            <div style="padding: var(--eh-space-4) var(--eh-space-5)">
                <x-eh.empty :title="$filter === 'all' ? 'No drivers or vehicles yet' : 'Nothing needs attention'" :heading-level="2">
                    <p style="margin:0">{{ $filter === 'all' ? 'Documents appear here once the fleet has drivers or vehicles.' : 'Every required document is approved and none expires within '.config('ehail_fleet.alerts.document_expiry_days').' days.' }}</p>
                </x-eh.empty>
            </div>
        @else
            <div class="fl-table-wrap">
                <table class="fl-table fl-table--stack">
                    <thead><tr><th scope="col">For</th><th scope="col">Document</th><th scope="col">Status</th><th scope="col" class="num">Expires</th><th scope="col" class="num">Days left</th></tr></thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr>
                                <td>
                                    @if ($row['subject'] === 'vehicle')
                                        <a class="fl-plate" href="{{ route('fleet.vehicles.show', $row['subject_id']) }}">{{ $row['subject_label'] }}</a>
                                    @else
                                        <a class="fl-name" href="{{ route('fleet.drivers.show', $row['subject_id']) }}">{{ $row['subject_label'] }}</a>
                                    @endif
                                </td>
                                <td>{{ $row['label'] }}</td>
                                <td class="fl-span"><x-eh.badge :tone="! $row['satisfied'] ? 'stop' : ($row['expiring'] ? 'caution' : 'go')">{{ ucfirst($docState($row['state'])) }}</x-eh.badge>@if ($row['renewal_pending'])<span class="fl-sub">Renewal waiting for review</span>@endif</td>
                                <td class="num" data-label="Expires">{!! $row['expires_on'] ? e(\Illuminate\Support\Carbon::parse($row['expires_on'])->format('j M Y')) : '<span class="fl-dash">No expiry</span>' !!}</td>
                                <td class="num" data-label="Days left">{!! $row['days_left'] !== null ? ($row['days_left'] < 0 ? '<span class="fl-sev--stop">'.abs($row['days_left']).' ago</span>' : e($row['days_left'])) : '<span class="fl-dash">&#8212;</span>' !!}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
    <p class="fl-note">Status and dates come from verification. The files themselves stay with the driver and our operations team; drivers renew documents in the driver app.</p>
</x-fleet.page>
