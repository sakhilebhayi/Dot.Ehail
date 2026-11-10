@php
    $items = [
        'incidents' => ['label' => 'Incidents', 'href' => route('ops.safety')],
        'check-ins' => ['label' => 'Check-ins', 'href' => route('ops.safety.check-ins')],
        'reports' => ['label' => 'Reports', 'href' => route('ops.safety.reports')],
        'patterns' => ['label' => 'Cancellation patterns', 'href' => route('ops.safety.patterns')],
    ];
    if (auth()->user()->can('ops.audit.view')) {
        $items['access'] = ['label' => 'Who viewed what', 'href' => route('ops.safety.access')];
    }
@endphp
<x-eh.tabs class="ops-subnav" label="Safety" :active="$active" :items="$items" />
