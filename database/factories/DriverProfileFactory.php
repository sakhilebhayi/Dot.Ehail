<?php

namespace Database\Factories;

use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class DriverProfileFactory extends Factory
{
    protected $model = DriverProfile::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'fleet_id' => Fleet::factory(),
            'license_number' => 'LIC-'.$this->faker->unique()->numberBetween(10000, 99999),
            'id_number' => 'ID-'.$this->faker->unique()->numberBetween(10000, 99999),
            'status' => 'pending',
        ];
    }
}
