<?php

namespace Tests\Concerns;

use App\Domain\Business\BusinessAccounts;
use App\Domain\Business\Models\BusinessAccount;
use App\Domain\Business\Models\BusinessDepartment;
use App\Domain\Business\Models\BusinessMember;
use App\Domain\Business\Models\BusinessPolicy;
use App\Domain\Payments\Seams\ConfigCommissionSplitter;
use App\Domain\Payments\Seams\EarningsSplitSource;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Testing\TestResponse;

/**
 * Business accounts for tests: an approved account with its admin, members of any role, departments and
 * policies, quotes from the dispatch fake, ride requests through the real API, and completion that runs
 * money's real settlement (business_account gateway -> BusinessBilling -> ledger).
 */
trait BusinessFixtures
{
    use CreatesDispatchFixtures;

    protected function setUpBusiness(): void
    {
        $this->fakeQuotes();
        // A legacy (operator-entered) fare and the configured commission: settlement without pricing records.
        $this->app->bind(EarningsSplitSource::class, ConfigCommissionSplitter::class);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{0: BusinessAccount, 1: BusinessMember}
     */
    protected function businessAccount(array $attributes = [], int $creditLimitMinor = 1_000_000, ?User $admin = null): array
    {
        $admin ??= User::factory()->withPersonalTeam()->create();
        $accounts = app(BusinessAccounts::class);

        $account = $accounts->apply($admin, array_merge([
            'legal_name' => 'Thandeka Logistics (Pty) Ltd',
            'registration_number' => '2019/123456/07',
            'billing_email' => 'accounts@thandeka.example',
            'address_line1' => '1 Long Street',
            'city' => 'Cape Town',
            'country' => 'ZA',
            'billing_mode' => 'invoice',
        ], $attributes));

        $operator = User::factory()->create();
        $operator->forceFill(['is_platform_operator' => true])->save();
        $accounts->approve($account, $operator, $creditLimitMinor, 30, 7, null);

        return [$account->fresh(), BusinessMember::where('business_account_id', $account->id)->where('user_id', $admin->id)->sole()];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function member(BusinessAccount $account, string $role = BusinessMember::TRAVELLER, array $attributes = [], ?User $user = null): BusinessMember
    {
        $user ??= User::factory()->withPersonalTeam()->create();

        $member = BusinessMember::create(array_merge([
            'business_account_id' => $account->id,
            'user_id' => $user->id,
            'email' => strtolower($user->email),
            'name' => $user->name,
            'role' => $role,
            'status' => BusinessMember::ACTIVE,
            'joined_at' => now(),
        ], $attributes));

        app(BusinessAccounts::class)->ensureProfile($member);

        return $member->fresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function policy(BusinessAccount $account, array $attributes = []): BusinessPolicy
    {
        return BusinessPolicy::create(array_merge(['business_account_id' => $account->id, 'name' => 'Policy '.uniqid()], $attributes));
    }

    protected function department(BusinessAccount $account, string $name = 'Sales', ?int $budgetMinor = null): BusinessDepartment
    {
        return BusinessDepartment::create(['business_account_id' => $account->id, 'name' => $name, 'cost_centre' => 'CC-'.strtoupper(substr($name, 0, 3)), 'monthly_budget_minor' => $budgetMinor]);
    }

    /**
     * @param  array<string, mixed>  $quote  overrides for the fake quote (totalMinor, category, ...)
     * @param  array<string, mixed>  $business  the request's business object
     */
    protected function requestBusinessRide(BusinessMember $member, array $quote = [], array $business = [], array $extra = []): TestResponse
    {
        $user = $member->user;
        $issued = $this->quotes->issue($user, $quote);

        return $this->actingAs($user)->postJson(route('ehail.dispatch.rides.store'), array_merge([
            'quote_id' => $issued->id,
            'payment_method' => 'business_account',
            'payment_ref' => (string) $member->payment_method_id,
            'business' => $business,
        ], $extra));
    }

    /**
     * Finish a requested ride as if a driver drove it, with this final fare, and let money settle it.
     */
    protected function completeBusinessRide(int $rideId, string $finalFare = '85.00'): Ride
    {
        $driver = $this->onlineDriverAt(...self::PICKUP);
        $ride = Ride::findOrFail($rideId);

        $ride->forceFill([
            'driver_id' => $driver->id,
            'status' => 'completed',
            'final_fare' => $finalFare,
            'accepted_at' => now()->subMinutes(30),
            'started_at' => now()->subMinutes(20),
            'completed_at' => now(),
            'active_passenger_id' => null,
            'active_driver_id' => null,
        ])->save();

        return $ride->fresh();
    }
}
