<?php

namespace Database\Factories;

use App\Models\Fleet;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class FleetFactory extends Factory
{
    protected $model = Fleet::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->company(),
            'owner_user_id' => User::factory(),
        ];
    }
}
