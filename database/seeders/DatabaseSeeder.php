<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Development seeding only: a test user with the factory's well-known password and the PLACEHOLDER example tariff.
     * Refuses in production, where a known password would be an open door (reference data is installed by the deploy:
     * docs/R46_OPERATIONS.md).
     */
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->error('DatabaseSeeder refuses to run in production: it creates test@example.com with a known password. Nothing was seeded.');

            return;
        }

        User::factory()->withPersonalTeam()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $this->call(EhailPricingExampleSeeder::class);
    }
}
