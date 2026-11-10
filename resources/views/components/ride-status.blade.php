@props(['status'])
@php
    $colors = [
        'scheduled' => '#f59e0b', 'requested' => '#f59e0b', 'searching' => '#f59e0b',
        'driver_assigned' => '#38bdf8', 'driver_arriving' => '#38bdf8',
        'driver_arrived' => '#a78bfa', 'in_progress' => '#a78bfa', 'completed' => '#4ade80',
        'cancelled_by_passenger' => '#a1a1aa', 'cancelled_by_driver' => '#a1a1aa', 'cancelled_by_operator' => '#a1a1aa',
        'expired' => '#a1a1aa', 'no_show' => '#a1a1aa', 'failed' => '#fb7185', 'needs_review' => '#fb7185',
    ];
    $color = $colors[$status] ?? '#38bdf8';
    $label = \App\Enums\RideStatus::tryFrom(\App\Enums\RideStatus::LEGACY_ALIASES[$status] ?? $status)?->label() ?? ucfirst(str_replace('_', ' ', $status));
@endphp
<span {{ $attributes->merge(['style' => "font-size:11px;font-weight:600;padding:4px 12px;border-radius:100px;white-space:nowrap;background:{$color}1a;color:{$color};"]) }}>{{ $label }}</span>
