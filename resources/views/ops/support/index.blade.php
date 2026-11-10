@php
    $queues = [
        'open' => 'Open ('.$counts['open'].')',
        'unassigned' => 'Nobody yet ('.$counts['unassigned'].')',
        'mine' => 'Mine ('.$counts['mine'].')',
        'breached' => 'SLA missed ('.$counts['breached'].')',
        'lost_property' => 'Lost property ('.$counts['lost_property'].')',
        'closed' => 'Resolved and closed',
    ];
@endphp
<x-ops-layout title="Support">
    <x-slot:head>@include('ops.partials.assets')</x-slot:head>
    <div class="ops">
        <header class="ops-head">
            <div class="ops-head__text">
                <h1>Support</h1>
                <p class="ops-head__finding" @if($counts['breached'] > 0) data-tone="stop" @elseif($counts['awaiting_first'] > 0) data-tone="caution" @endif>{{ $counts['open'] }} open {{ \Illuminate\Support\Str::plural('case', $counts['open']) }}: {{ $counts['awaiting_first'] }} still without a first reply, {{ $counts['breached'] }} past an SLA.</p>
            </div>
        </header>
        <x-eh.tabs class="ops-subnav" label="Support queues" :active="$queue" :items="collect($queues)->map(fn ($label, $key) => ['label' => $label, 'href' => route('ops.support', ['queue' => $key])])->all()" />
        <form class="ops-filter" method="GET" action="{{ route('ops.support') }}">
            <input type="hidden" name="queue" value="{{ $queue }}">
            <x-eh.select label="Category" name="category" :options="collect(\App\Domain\Support\SupportCategory::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all()" placeholder="Any category" :value="$category ?? ''" />
            <x-eh.button type="submit" size="sm" icon="filter">Apply</x-eh.button>
        </form>
        <section class="ops-panel ops-panel--flush" aria-label="Cases">
            @if ($cases->isEmpty())
                <p class="ops-none" style="padding:var(--eh-space-5)">No case in this queue.</p>
            @else
                <div class="ops-tablewrap"><table class="ops-table">
                    <thead><tr><th>Case</th><th>From</th><th>Priority</th><th>Status</th><th>Owner</th><th class="num">Timer</th></tr></thead>
                    <tbody>
                        @foreach ($cases as $case)
                            <tr>
                                <td><a href="{{ route('ops.support.show', $case->id) }}">{{ $case->reference }}</a><span class="sub">{{ $case->category->label() }} · {{ \Illuminate\Support\Str::limit((string) $case->subject, 60) }}{{ $case->ride_id ? ' · ride '.$case->ride_id : '' }}</span></td>
                                <td>{{ $case->requester?->name }}<span class="sub">{{ $case->requester_role }}</span></td>
                                <td><x-eh.badge :tone="match($case->priority->value){'urgent'=>'stop','high'=>'caution',default=>'neutral'}">{{ ucfirst($case->priority->value) }}</x-eh.badge></td>
                                <td>{{ ucfirst(str_replace('_', ' ', $case->status->value)) }}{{ $case->escalation_level ? ' · escalated' : '' }}</td>
                                <td>{{ $case->owner?->name ?? 'Nobody' }}</td>
                                <td class="num">@include('ops.partials.sla')</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table></div>
                @include('ops.partials.pager', ['page' => $cases])
            @endif
        </section>
    </div>
</x-ops-layout>
