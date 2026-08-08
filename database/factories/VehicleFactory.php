<?php

namespace Database\Factories;

use App\Models\DriverProfile;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

class VehicleFactory extends Factory
{
    protected $model = Vehicle::class;

    public function definition(): array
    {
        return [
            'driver_profile_id' => DriverProfile::factory(),
            'make' => 'Toyota',
            'model' => 'Corolla',
            'year' => 2022,
            'color' => 'White',
            'plate_number' => 'CA '.$this->faker->unique()->numberBetween(100000, 999999),
            'type' => 'standard',
            'is_active' => true,
        ];
    }
}
