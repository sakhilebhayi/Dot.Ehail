@php
    // $documents (optional): the verification summary status. An approved account whose documents are not all
    // approved and current is not shown as plainly "Approved" (D-1910).
    $documents ??= null;
    [$tone, $label] = match (true) {
        $status === 'approved' && $documents === 'pending_review' => ['caution', 'Approved, documents waiting for review'],
        $status === 'approved' && $documents === 'action_required' => ['caution', 'Approved, documents missing'],
        default => match ($status) {
        'approved' => ['go', 'Approved'],
        'pending' => ['caution', 'Application pending'],
        'suspended' => ['stop', 'Suspended'],
        'rejected' => ['neutral', 'Rejected'],
        default => ['neutral', ucfirst((string) $status)],
        },
    };
@endphp
<x-eh.badge :tone="$tone">{{ $label }}</x-eh.badge>
