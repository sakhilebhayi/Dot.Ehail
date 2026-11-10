<?php

namespace App\Domain\Geo\Geocoding;

use App\Domain\Geo\LatLng;

/**
 * One geocoding answer. label is the full display line; name the short first line (may equal label).
 */
final readonly class Place
{
    public function __construct(
        public string $label,
        public string $name,
        public LatLng $position,
        public string $provider,
        public ?string $providerRef = null,
    ) {}

    /**
     * @return array{label: string, name: string, lat: float, lng: float, provider: string, ref: ?string}
     */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'name' => $this->name,
            'lat' => $this->position->lat,
            'lng' => $this->position->lng,
            'provider' => $this->provider,
            'ref' => $this->providerRef,
        ];
    }

    /**
     * @param  array{label: string, name: string, lat: float, lng: float, provider: string, ref: ?string}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['label'], $data['name'], LatLng::from($data['lat'], $data['lng']), $data['provider'], $data['ref'] ?? null);
    }
}
