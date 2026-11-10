{{-- A ride policy in plain words. --}}
@php
    $days = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
@endphp
@if (! $policy)
    <p class="bz-hint">No policy: rides are limited only by the account's credit.</p>
@else
    <dl class="bz-facts">
        <div><dt>Categories</dt><dd>{{ $policy->allowed_categories ? implode(', ', array_map([\App\Domain\Business\CategoryNames::class, 'for'], $policy->allowed_categories)) : 'Any' }}</dd></div>
        <div><dt>Days</dt><dd>{{ $policy->allowed_days ? implode(', ', array_map(fn ($d) => $days[(int) $d] ?? $d, $policy->allowed_days)) : 'Every day' }}</dd></div>
        <div><dt>Hours</dt><dd>{{ $policy->hasHours() ? $policy::minutesLabel($policy->hours_start).' to '.$policy::minutesLabel($policy->hours_end) : 'Any time' }}</dd></div>
        <div><dt>Most per ride</dt><dd>@if ($policy->max_fare_minor !== null)<x-eh.money :minor="$policy->max_fare_minor" :currency="$cur" />@else No limit @endif</dd></div>
        <div><dt>Approval needed above</dt><dd>@if ($policy->approval_above_minor !== null)<x-eh.money :minor="$policy->approval_above_minor" :currency="$cur" />@else Never @endif</dd></div>
        <div><dt>Purpose</dt><dd>{{ $policy->purpose_required ? 'Required' : 'Optional' }}</dd></div>
        <div><dt>Areas</dt><dd>{{ $policy->service_area_ids ? count($policy->service_area_ids).' chosen' : 'Anywhere Dot.Ehail runs' }}</dd></div>
        <div><dt>Guests</dt><dd>{{ $policy->guest_bookings ? 'Can be booked' : 'Approvers only' }}</dd></div>
    </dl>
@endif
