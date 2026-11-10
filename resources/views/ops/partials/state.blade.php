{{-- A driver's dispatch state as a word with a tone (never colour alone). --}}
@php
    $stale = $row && $row->state !== 'offline' && ($row->last_heartbeat_at === null || $row->last_heartbeat_at->lt(now()->subSeconds((int) config('ehail_dispatch.heartbeat_stale_seconds', 45))));
    [$tone, $label, $icon] = match ($row?->state) {
        'online' => $stale ? ['caution', 'Online, no signal', 'wifi-off'] : ['go', 'Online', 'online'],
        'on_trip' => ['accent', 'On a trip', 'route'],
        'paused' => ['neutral', 'Paused', 'clock'],
        default => ['neutral', 'Offline', 'offline'],
    };
@endphp
<x-eh.badge :tone="$tone" :icon="$icon">{{ $label }}</x-eh.badge>
