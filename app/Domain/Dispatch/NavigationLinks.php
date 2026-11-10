<?php

namespace App\Domain\Dispatch;

/**
 * Navigation hand-off to the driver's own maps app (architecture section 7, D-5): Dot.Ehail shows the route
 * and progress, the phone's Google Maps, Apple Maps or Waze does turn-by-turn. These are each provider's
 * documented universal links; they carry only the next stop (the pickup point or the drop-off), never the
 * passenger's live position (which is not collected).
 */
final class NavigationLinks
{
    /**
     * @return array{target: string, google_maps: string, apple_maps: string, waze: string}|null
     */
    public static function to(float|string|null $lat, float|string|null $lng, string $target): ?array
    {
        if ($lat === null || $lng === null) {
            return null;
        }

        $point = sprintf('%.6F,%.6F', (float) $lat, (float) $lng);

        return [
            'target' => $target,
            'google_maps' => 'https://www.google.com/maps/dir/?api=1&destination='.$point.'&travelmode=driving',
            'apple_maps' => 'https://maps.apple.com/?daddr='.$point.'&dirflg=d',
            'waze' => 'https://waze.com/ul?ll='.$point.'&navigate=yes',
        ];
    }
}
