<x-ops-layout title="Audit log">
    <x-slot:head>@include('ops.partials.assets')</x-slot:head>
    <div class="ops">
        <header class="ops-head"><div class="ops-head__text"><h1>Audit log</h1><p class="ops-head__finding">{{ number_format($rows->total()) }} {{ \Illuminate\Support\Str::plural('entry', $rows->total()) }} match. Entries are append-only: the application never changes or removes one.</p></div></header>
        <form class="ops-filter" method="GET" action="{{ route('ops.audit') }}" aria-label="Filter the audit log">
            <x-eh.field label="From" name="from" type="date" :value="$filters['from'] ?? null" />
            <x-eh.field label="To" name="to" type="date" :value="$filters['to'] ?? null" />
            <x-eh.select label="Area" name="action" :options="$prefixes->mapWithKeys(fn ($p) => [$p => $p])->all()" placeholder="Every area" :value="$filters['action'] ?? ''" />
            <x-eh.select label="Record type" name="subject_type" :options="$types->mapWithKeys(fn ($t) => [$t => class_basename($t)])->all()" placeholder="Any" :value="$filters['subject_type'] ?? ''" />
            <x-eh.field label="Record number" name="subject_id" type="number" :value="$filters['subject_id'] ?? null" :optional="true" />
            <x-eh.field label="Person (user number)" name="actor" type="number" :value="$filters['actor'] ?? null" :optional="true" />
            <x-eh.button type="submit" size="sm" icon="filter">Apply</x-eh.button>
        </form>
        <section class="ops-panel ops-panel--flush" aria-label="Audit entries">
            @if ($rows->isEmpty())
                <p class="ops-none" style="padding:var(--eh-space-5)">Nothing recorded for these filters.</p>
            @else
                <div class="ops-tablewrap"><table class="ops-table ops-table--compact">
                    <thead><tr><th>When</th><th>Who</th><th>What</th><th>Record</th><th>Detail</th></tr></thead>
                    <tbody>
                        @foreach ($rows as $row)
                            @php($meta = collect(is_array($row->meta) ? $row->meta : [])->except(['terms', 'polygon', 'before', 'after'])->map(fn ($v) => is_scalar($v) || $v === null ? (string) $v : json_encode($v))->filter(fn ($v) => $v !== ''))
                            <tr>
                                <td class="num" style="text-align:left">{{ $row->created_at?->format('d M Y H:i:s') }}</td>
                                <td>{{ $row->actor?->name ?? 'System' }}@if($row->actor_user_id)<span class="sub">user {{ $row->actor_user_id }}</span>@endif</td>
                                <td>{{ $row->action }}</td>
                                <td>{{ class_basename($row->subject_type) }} {{ $row->subject_id }}</td>
                                <td><span class="sub" style="margin:0">{{ \Illuminate\Support\Str::limit($meta->map(fn ($v, $k) => $k.': '.$v)->implode('; '), 220) }}{{ isset($row->meta['before']) ? ' (before and after recorded)' : '' }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table></div>
                @include('ops.partials.pager', ['page' => $rows])
            @endif
        </section>
    </div>
</x-ops-layout>
