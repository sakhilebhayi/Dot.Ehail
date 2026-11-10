{{-- Ride status badge, mapped from the lifecycle in docs/R46_ARCHITECTURE.md section 2 (old names map to new). --}}
@props(['status'])
@php
    $value = $status instanceof \BackedEnum ? $status->value : (string) $status;
    [$tone, $label, $icon] = match ($value) {
        'scheduled' => ['info', 'Scheduled', 'calendar-clock'],
        'requested' => ['neutral', 'Requested', 'clock'],
        'searching' => ['caution', 'Finding a driver', 'search'],
        'driver_assigned', 'accepted' => ['accent', 'Driver assigned', 'steering-wheel'],
        'driver_arriving', 'en_route' => ['accent', 'Driver on the way', 'navigation'],
        'driver_arrived', 'arrived' => ['signal', 'Driver at pickup', 'pickup'],
        'in_progress' => ['go', 'On the trip', 'route'],
        'completed' => ['go', 'Completed', 'success'],
        'cancelled', 'cancelled_by_passenger' => ['neutral', 'Cancelled by passenger', 'close'],
        'cancelled_by_driver' => ['neutral', 'Cancelled by driver', 'close'],
        'cancelled_by_operator' => ['neutral', 'Cancelled by operations', 'close'],
        'expired' => ['neutral', 'No driver found', 'timer'],
        'no_show' => ['caution', 'Passenger no-show', 'flag'],
        'failed' => ['stop', 'Failed', 'alert'],
        'needs_review' => ['stop', 'Needs review', 'flag'],
        default => ['neutral', ucfirst(str_replace('_', ' ', $value)), 'info'],
    };
@endphp
<x-eh.badge :tone="$tone" :icon="$icon" {{ $attributes }} data-status="{{ $value }}">{{ $label }}</x-eh.badge>
