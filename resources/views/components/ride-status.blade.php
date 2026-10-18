@props(['status'])
@php
    $colors = [
        'requested' => '#f59e0b', 'accepted' => '#38bdf8', 'en_route' => '#38bdf8',
        'arrived' => '#a78bfa', 'in_progress' => '#a78bfa', 'completed' => '#4ade80',
        'cancelled' => '#a1a1aa', 'expired' => '#a1a1aa', 'needs_review' => '#fb7185',
    ];
    $color = $colors[$status] ?? '#38bdf8';
    $label = \App\Enums\RideStatus::tryFrom($status)?->label() ?? ucfirst(str_replace('_', ' ', $status));
@endphp
<span {{ $attributes->merge(['style' => "font-size:11px;font-weight:600;padding:4px 12px;border-radius:100px;white-space:nowrap;background:{$color}1a;color:{$color};"]) }}>{{ $label }}</span>
