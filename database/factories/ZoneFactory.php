<?php

namespace Database\Factories;

use App\Domain\Geo\Models\Zone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Zone>
 */
class ZoneFactory extends Factory
{
    protected $model = Zone::class;

    public function definition(): array
    {
        return [
            'slug' => 'zone-'.$this->faker->unique()->numberBetween(1000, 99999),
            'name' => 'Test zone',
            'kind' => 'special',
            'polygon' => [[-26.21, 28.03], [-26.21, 28.05], [-26.19, 28.05], [-26.19, 28.03]],
            'is_active' => true,
            'is_approximate' => false,
        ];
    }
}
