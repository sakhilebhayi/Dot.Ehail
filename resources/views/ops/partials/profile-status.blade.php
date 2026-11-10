@php
    [$tone, $label] = match ($status) {
        'approved' => ['go', 'Approved'],
        'pending' => ['caution', 'Application pending'],
        'suspended' => ['stop', 'Suspended'],
        'rejected' => ['neutral', 'Rejected'],
        default => ['neutral', ucfirst((string) $status)],
    };
@endphp
<x-eh.badge :tone="$tone">{{ $label }}</x-eh.badge>
