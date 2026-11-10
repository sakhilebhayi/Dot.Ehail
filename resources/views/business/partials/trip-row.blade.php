{{-- One business trip as a table row: when, who, why, where, ride state, money. --}}
@php
    $ride = $trip->ride;
    $when = $ride?->scheduled_for ?? $trip->booked_at ?? $trip->created_at;
    $tz = config('ehail_business.timezone');
@endphp
<tr>
    <td>
        <time datetime="{{ $when?->toIso8601String() }}">{{ $when?->setTimezone($tz)->format('j M, H:i') }}</time>
        @if ($ride?->scheduled_for)<span class="bz-table__sub">Scheduled</span>@endif
    </td>
    <td>
        {{ $trip->riderLabel() }}
        @if ($trip->isOnBehalf())<span class="bz-table__sub">Booked by {{ $trip->booker?->displayName() }}</span>@endif
    </td>
    @if ($showDepartment ?? true)
        <td>{{ $trip->department_name ?? '' }}@if ($trip->cost_centre)<span class="bz-table__sub">{{ $trip->cost_centre }}</span>@endif @if (! $trip->department_name)<span class="eh-muted">None</span>@endif</td>
    @endif
    <td>{{ $trip->purpose ?? '' }}@if (! $trip->purpose)<span class="eh-muted">Not given</span>@endif</td>
    <td>
        <div class="bz-route">
            <span><i class="bz-stop bz-stop--pickup" aria-hidden="true"></i><span class="eh-sr-only">From </span>{{ \Illuminate\Support\Str::limit($ride?->pickup_address ?? 'Pickup', 48) }}</span>
            <span><i class="bz-stop bz-stop--dropoff" aria-hidden="true"></i><span class="eh-sr-only">To </span>{{ \Illuminate\Support\Str::limit($ride?->dropoff_address ?? 'Drop-off', 48) }}</span>
        </div>
    </td>
    <td>@if ($ride)<x-eh.status :status="$ride->status" />@endif</td>
    <td class="num">
        @if ($trip->status === 'charged')
            <x-eh.money :minor="$trip->charged_minor" :currency="$trip->currency" />
        @elseif ($trip->status === 'booked')
            <x-eh.money :minor="$trip->reserved_minor ?: $trip->estimate_minor" :currency="$trip->currency" muted /><span class="bz-table__sub">Held, not billed yet</span>
        @else
            <span class="eh-muted">Not billed</span>
        @endif
    </td>
</tr>
