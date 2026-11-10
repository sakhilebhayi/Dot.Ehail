<?php

namespace Database\Seeders;

use App\Domain\Business\BusinessAccounts;
use App\Domain\Business\Models\BusinessAccount;
use App\Domain\Business\Models\BusinessDepartment;
use App\Domain\Business\Models\BusinessMember;
use App\Domain\Business\Models\BusinessPolicy;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * DEVELOPMENT ONLY: a demo business account for looking at the /business portal. Refuses in production.
 * Everything it makes is labelled "Demo" and uses example.test addresses; it creates no rides, charges or
 * statements (those come only from real bookings through the ride-request API).
 *
 *   php artisan db:seed --class=DemoBusinessSeeder
 * Admin: the seeded dev user (dev@example.test). Other demo people sign in with dev-pass-123.
 */
class DemoBusinessSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('DemoBusinessSeeder refused: demo data never goes to production.');

            return;
        }

        $admin = User::where('email', 'dev@example.test')->first() ?? User::factory()->withPersonalTeam()->create(['name' => 'Dev Admin', 'email' => 'dev@example.test', 'password' => Hash::make('dev-pass-123')]);

        if (BusinessMember::where('user_id', $admin->id)->exists()) {
            $this->command?->info('Demo business account already exists.');

            return;
        }

        $accounts = app(BusinessAccounts::class);
        $account = $accounts->apply($admin, [
            'legal_name' => 'Demo Ubuntu Freight (Pty) Ltd',
            'trading_name' => 'Demo Ubuntu Freight',
            'registration_number' => '2018/000000/07',
            'billing_email' => 'accounts@ubuntu-freight.example.test',
            'address_line1' => '12 Bree Street',
            'city' => 'Cape Town',
            'postal_code' => '8001',
            'country' => 'ZA',
            'billing_mode' => 'invoice',
        ]);
        $operator = User::where('is_platform_operator', true)->first();

        if ($operator === null) {
            $operator = User::factory()->withPersonalTeam()->create(['name' => 'Demo Operator', 'email' => 'ops-demo@example.test', 'password' => Hash::make('dev-pass-123')]);
            $operator->forceFill(['is_platform_operator' => true])->save();
        }

        $accounts->approve($account, $operator, 5_000_000, 30, 7, 'Demo account (development only).');

        $sales = BusinessDepartment::create(['business_account_id' => $account->id, 'name' => 'Sales', 'cost_centre' => 'CC-100', 'monthly_budget_minor' => 400_000]);
        $ops = BusinessDepartment::create(['business_account_id' => $account->id, 'name' => 'Operations', 'cost_centre' => 'CC-200', 'monthly_budget_minor' => 600_000]);
        BusinessDepartment::create(['business_account_id' => $account->id, 'name' => 'Finance', 'cost_centre' => 'CC-300']);

        $field = BusinessPolicy::create([
            'business_account_id' => $account->id, 'name' => 'Field staff', 'allowed_days' => [1, 2, 3, 4, 5], 'hours_start' => 6 * 60, 'hours_end' => 20 * 60,
            'max_fare_minor' => 60_000, 'purpose_required' => true, 'approval_above_minor' => 25_000, 'approval_window_minutes' => 30, 'guest_bookings' => true,
        ]);

        $people = [
            ['Demo Lerato Khumalo', 'lerato@ubuntu-freight.example.test', BusinessMember::MANAGER, $ops, null, null],
            ['Demo Pieter van Wyk', 'pieter@ubuntu-freight.example.test', BusinessMember::FINANCE, null, null, null],
            ['Demo Sipho Ndlovu', 'sipho@ubuntu-freight.example.test', BusinessMember::TRAVELLER, $sales, $field, 150_000],
            ['Demo Ayesha Patel', 'ayesha@ubuntu-freight.example.test', BusinessMember::TRAVELLER, $sales, $field, 120_000],
        ];

        foreach ($people as [$name, $email, $role, $department, $policy, $allowance]) {
            $user = User::where('email', $email)->first() ?? User::factory()->withPersonalTeam()->create(['name' => $name, 'email' => $email, 'password' => Hash::make('dev-pass-123')]);
            $member = BusinessMember::create([
                'business_account_id' => $account->id, 'user_id' => $user->id, 'email' => $email, 'name' => $name, 'role' => $role, 'status' => BusinessMember::ACTIVE,
                'department_id' => $department?->id, 'policy_id' => $policy?->id, 'monthly_allowance_minor' => $allowance, 'joined_at' => now(),
            ]);
            $accounts->ensureProfile($member);
        }

        BusinessMember::where('business_account_id', $account->id)->where('user_id', $admin->id)->update(['department_id' => $ops->id]);
        $this->command?->info('Demo business account created: '.BusinessAccount::find($account->id)?->displayName());
    }
}
