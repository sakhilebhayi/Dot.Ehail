<?php

namespace App\Domain\Geo;

use Carbon\CarbonImmutable;

/**
 * One position fix from a driver's device. accuracy is the device's reported 68% horizontal radius in metres;
 * speed (m/s) and heading (degrees from north) are what the device reported, null when it did not.
 */
final readonly class LocationSample
{
    public function __construct(
        public LatLng $position,
        public CarbonImmutable $recordedAt,
        public ?float $accuracyM = null,
        public ?float $speedMps = null,
        public ?float $headingDeg = null,
    ) {}

    /**
     * recorded_at is epoch milliseconds or an ISO-8601 string.
     *
     * @param  array{lat: float|string, lng: float|string, recorded_at: int|string, accuracy?: float|string|null, speed?: float|string|null, heading?: float|string|null}  $input
     */
    public static function fromInput(array $input): self
    {
        $recorded = $input['recorded_at'];
        $recordedAt = is_numeric($recorded)
            ? CarbonImmutable::createFromTimestampMs((int) $recorded)
            : CarbonImmutable::parse((string) $recorded);

        return new self(
            LatLng::from($input['lat'], $input['lng']),
            $recordedAt->utc(),
            isset($input['accuracy']) ? (float) $input['accuracy'] : null,
            isset($input['speed']) && (float) $input['speed'] >= 0 ? (float) $input['speed'] : null,
            isset($input['heading']) ? fmod((float) $input['heading'] + 360, 360) : null,
        );
    }
}
