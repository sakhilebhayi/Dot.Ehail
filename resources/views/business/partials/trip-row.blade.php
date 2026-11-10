{{-- One business trip as a table row: when, who, why, where, ride state, money. --}}
@php
    $ride = $trip->ride;
    $when = $ride?->scheduled_for ?? $trip->booked_at ?? $trip->created_at;
    $tz = config('ehail_business.timezone');
@endphp
<tr>
    <td class="bz-when" data-label="When">
        <time datetime="{{ $when?->toIso8601String() }}">{{ $when?->setTimezone($tz)->format('j M, H:i') }}</time>
        @if ($ride?->scheduled_for)<span class="bz-table__sub">Scheduled</span>@endif
    </td>
    <td data-label="Rider">
        <span>{{ $trip->riderLabel() }}
        @if ($trip->isOnBehalf())<span class="bz-table__sub">Booked by {{ $trip->booker?->displayName() }}</span>@endif</span>
    </td>
    @if ($showDepartment ?? true)
        <td data-label="Department"><span>{{ $trip->department_name ?? '' }}@if ($trip->cost_centre)<span class="bz-table__sub">{{ $trip->cost_centre }}</span>@endif @if (! $trip->department_name)<span class="eh-muted">None</span>@endif</span></td>
    @endif
    <td data-label="Purpose"><span>@if ($trip->category_code)<span class="bz-table__sub" style="margin:0 0 2px">{{ \App\Domain\Business\CategoryNames::for($trip->category_code) }}</span>@endif{{ $trip->purpose ?? '' }}@if (! $trip->purpose)<span class="eh-muted">Not given</span>@endif</span></td>
    @if ($showRoute ?? true)
    <td data-label="Route">
        <div class="bz-route">
            <span><i class="bz-stop bz-stop--pickup" aria-hidden="true"></i><span class="eh-sr-only">From </span>{{ \Illuminate\Support\Str::limit(\App\Support\Ui\PlaceNames::for($ride?->pickup_address, $ride?->pickup_lat, $ride?->pickup_lng), 48) }}</span>
            <span><i class="bz-stop bz-stop--dropoff" aria-hidden="true"></i><span class="eh-sr-only">To </span>{{ \Illuminate\Support\Str::limit(\App\Support\Ui\PlaceNames::for($ride?->dropoff_address, $ride?->dropoff_lat, $ride?->dropoff_lng), 48) }}</span>
        </div>
    </td>
    @endif
    <td data-label="Ride">@if ($ride)<x-eh.status :status="$ride->status" />@endif</td>
    <td class="num" data-label="Amount"><span>
        @if ($trip->status === 'charged')
            <x-eh.money :minor="$trip->charged_minor" :currency="$trip->currency" />
        @elseif ($trip->status === 'booked')
            <x-eh.money :minor="$trip->reserved_minor ?: $trip->estimate_minor" :currency="$trip->currency" muted /><span class="bz-table__sub">Held, not billed yet</span>
        @else
            <span class="eh-muted">Not billed</span>
        @endif
    </span></td>
</tr>
