<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\RideCategory;

/**
 * A category offered at a place and time. supply: 'available' (the counter saw at least one eligible online
 * driver in range) or 'unknown' (no counter is wired yet). Categories with a counted supply of zero are not offered.
 */
final readonly class CategoryOffer
{
    public function __construct(
        public RideCategory $category,
        public string $supply,
        public ?int $eligibleDrivers,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->category->toPublicArray() + ['supply' => $this->supply, 'eligible_drivers' => $this->eligibleDrivers];
    }
}
