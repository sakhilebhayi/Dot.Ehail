{{-- SLA timer for a support case: first response while none was sent, then resolution. --}}
@php
    $due = $case->first_responded_at === null ? $case->first_response_due_at : $case->resolution_due_at;
    $what = $case->first_responded_at === null ? 'first reply' : 'resolution';
@endphp
@if (in_array($case->status->value, ['resolved', 'closed'], true))
    <span class="ops-timer" data-tone="calm">{{ $case->status->value }} {{ ($case->resolved_at ?? $case->closed_at)?->format('d M H:i') }}</span>
@elseif ($case->status->value === 'waiting_on_user')
    <span class="ops-timer" data-tone="calm">waiting on the {{ $case->requester_role ?? 'user' }}</span>
@elseif ($due)
    <span class="ops-timer" data-ops-due="{{ $due->toIso8601String() }}" data-ops-warn="900" data-ops-label="{{ $what }}" data-tone="{{ $due->isPast() ? 'stop' : ($due->diffInSeconds(now(), true) < 900 ? 'caution' : 'calm') }}">{{ $what }} {{ $due->isPast() ? 'overdue' : 'due '.$due->format('d M H:i') }}</span>
@else
    <span class="ops-dash">no SLA</span>
@endif
