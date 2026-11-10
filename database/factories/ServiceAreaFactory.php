<?php

namespace Database\Factories;

use App\Domain\Geo\Models\ServiceArea;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Test shapes only: a square of about 20 km around central Johannesburg unless a polygon is given.
 *
 * @extends Factory<ServiceArea>
 */
class ServiceAreaFactory extends Factory
{
    protected $model = ServiceArea::class;

    public function definition(): array
    {
        return [
            'slug' => 'area-'.$this->faker->unique()->numberBetween(1000, 99999),
            'name' => 'Test area',
            'country_code' => 'ZA',
            'currency' => 'ZAR',
            'timezone' => 'Africa/Johannesburg',
            'polygon' => [[-26.30, 27.95], [-26.30, 28.15], [-26.10, 28.15], [-26.10, 27.95]],
            'is_active' => true,
            'is_approximate' => false,
        ];
    }
}
