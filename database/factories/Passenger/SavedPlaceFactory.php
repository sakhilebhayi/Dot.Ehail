<?php

namespace Database\Factories\Passenger;

use App\Domain\Passenger\Models\SavedPlace;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavedPlace>
 */
class SavedPlaceFactory extends Factory
{
    protected $model = SavedPlace::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'kind' => 'home',
            'label' => 'Home',
            'address' => fake()->streetAddress().', Cape Town',
            'lat' => -33.94,
            'lng' => 18.41,
        ];
    }
}
