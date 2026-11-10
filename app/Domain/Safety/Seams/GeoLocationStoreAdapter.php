<?php

namespace App\Domain\Safety\Seams;

use App\Models\Ride;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Forwards to the geo domain's LocationStore (App\Domain\Geo\LocationStore, contract section 3:
 * latest(array $driverIds), trail(Ride)) when the container has it, and normalises whatever shape
 * it returns (arrays or objects with lat/lng). Without it, or on any error, returns nothing.
 */
class GeoLocationStoreAdapter implements SafetyLocationSource
{
    public const STORE = 'App\\Domain\\Geo\\LocationStore';

    public function latestDriverPosition(Ride $ride): ?array
    {
        if ($ride->driver_id === null || ! $this->available()) {
            return null;
        }

        try {
            $latest = app(self::STORE)->latest([(int) $ride->driver_id]);
            $row = is_iterable($latest) ? (collect($latest)->get($ride->driver_id) ?? collect($latest)->first()) : $latest;

            return $this->point($row);
        } catch (Throwable $e) {
            Log::warning('Safety could not read the latest driver position.', ['ride_id' => $ride->id, 'error' => class_basename($e)]);

            return null;
        }
    }

    public function recentTrail(Ride $ride, int $limit = 20): array
    {
        if (! $this->available()) {
            return [];
        }

        try {
            $trail = app(self::STORE)->trail($ride);

            return collect(is_iterable($trail) ? $trail : [])
                ->map(fn ($p) => $this->point($p))
                ->filter()
                ->take(-1 * $limit)
                ->values()
                ->all();
        } catch (Throwable $e) {
            Log::warning('Safety could not read the trip trail.', ['ride_id' => $ride->id, 'error' => class_basename($e)]);

            return [];
        }
    }

    public function available(): bool
    {
        return (interface_exists(self::STORE) && app()->bound(self::STORE)) || class_exists(self::STORE);
    }

    /** @return array{lat: float, lng: float, recorded_at: ?string}|null */
    private function point(mixed $row): ?array
    {
        if ($row === null) {
            return null;
        }

        $get = fn (string ...$keys) => collect($keys)->map(fn ($k) => is_array($row) ? ($row[$k] ?? null) : ($row->{$k} ?? null))->first(fn ($v) => $v !== null);

        $lat = $get('lat', 'latitude');
        $lng = $get('lng', 'lon', 'longitude');

        if (! is_numeric($lat) || ! is_numeric($lng)) {
            return null;
        }

        $at = $get('recorded_at', 'at', 'created_at');

        return ['lat' => (float) $lat, 'lng' => (float) $lng, 'recorded_at' => $at instanceof \DateTimeInterface ? $at->format(DATE_ATOM) : ($at !== null ? (string) $at : null)];
    }
}
