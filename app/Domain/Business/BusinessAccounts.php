<?php

namespace App\Domain\Business;

use App\Domain\Business\Models\BusinessAccount;
use App\Domain\Business\Models\BusinessDepartment;
use App\Domain\Business\Models\BusinessMember;
use App\Domain\Business\Models\BusinessPolicy;
use App\Domain\Business\Notifications\MemberInvitedNotification;
use App\Domain\Payments\Models\PaymentMethod;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Opening, reviewing and staffing business accounts.
 *
 * An application creates the account (pending_review), its first account admin (the applicant) and a
 * default ride policy with no restrictions. An operator approves it with a credit limit and terms, or
 * declines it. People are invited by email; the invite is accepted by a signed-in user with that email
 * (accounts here are created by InfoDot sign-in). Each active member who may ride gets a
 * business_account payment method: the "business profile" the passenger app offers.
 */
class BusinessAccounts
{
    /**
     * @param  array<string, mixed>  $data  validated application fields
     *
     * @throws BusinessRefusal
     */
    public function apply(User $user, array $data): BusinessAccount
    {
        $team = null;

        if (! empty($data['team_id'])) {
            $team = $this->linkableTeams($user)->firstWhere('id', (int) $data['team_id']);

            if ($team === null) {
                throw BusinessRefusal::one('team_not_linkable', 'You can link only a company team you own or administer.');
            }

            if (BusinessAccount::where('team_id', $team->id)->whereNotIn('status', [BusinessAccount::DECLINED, BusinessAccount::CLOSED])->exists()) {
                throw BusinessRefusal::one('team_taken', 'That company already has a business account. Ask its account admin to invite you.', 409);
            }
        }

        $country = strtoupper((string) $data['country']);
        $currency = (string) config("ehail_business.countries.{$country}.currency", 'ZAR');

        return DB::transaction(function () use ($user, $data, $team, $country, $currency) {
            $account = BusinessAccount::create([
                'team_id' => $team?->id,
                'legal_name' => $data['legal_name'],
                'trading_name' => $data['trading_name'] ?? null,
                'registration_number' => $data['registration_number'],
                'vat_number' => $data['vat_number'] ?? null,
                'billing_email' => strtolower((string) $data['billing_email']),
                'address_line1' => $data['address_line1'],
                'address_line2' => $data['address_line2'] ?? null,
                'city' => $data['city'],
                'postal_code' => $data['postal_code'] ?? null,
                'country' => $country,
                'currency' => $currency,
                'status' => BusinessAccount::PENDING,
                'billing_mode' => ($data['billing_mode'] ?? 'invoice') === BusinessAccount::CARD ? BusinessAccount::CARD : BusinessAccount::INVOICE,
                'created_by' => $user->id,
            ]);

            $policy = BusinessPolicy::create([
                'business_account_id' => $account->id,
                'name' => 'Standard',
                'is_default' => true,
            ]);

            $member = BusinessMember::create([
                'business_account_id' => $account->id,
                'user_id' => $user->id,
                'email' => strtolower((string) $user->email),
                'name' => $user->name,
                'role' => BusinessMember::ADMIN,
                'status' => BusinessMember::ACTIVE,
                'policy_id' => $policy->id,
                'joined_at' => now(),
            ]);

            $this->ensureProfile($member);

            return $account;
        });
    }

    /**
     * Non-personal teams this person owns or administers (InfoDot companies sync into teams).
     *
     * @return Collection<int, Team>
     */
    public function linkableTeams(User $user): Collection
    {
        return $user->allTeams()
            ->filter(fn (Team $team) => ! $team->personal_team && ($team->user_id === $user->id || $user->hasTeamRole($team, 'admin')))
            ->values();
    }

    public function approve(BusinessAccount $account, User $operator, int $creditLimitMinor, int $termsDays, int $graceDays, ?string $note): BusinessAccount
    {
        $account->forceFill([
            'status' => BusinessAccount::ACTIVE,
            'status_reason' => null,
            'status_note' => $note,
            'credit_limit_minor' => max(0, $creditLimitMinor),
            'terms_days' => max(0, min(90, $termsDays)),
            'grace_days' => max(0, min(60, $graceDays)),
            'reviewed_by' => $operator->id,
            'reviewed_at' => now(),
            'suspended_at' => null,
        ])->save();

        return $account;
    }

    public function decline(BusinessAccount $account, User $operator, string $note): BusinessAccount
    {
        $account->forceFill(['status' => BusinessAccount::DECLINED, 'status_note' => $note, 'reviewed_by' => $operator->id, 'reviewed_at' => now()])->save();

        return $account;
    }

    public function suspend(BusinessAccount $account, string $reason, ?string $note = null): BusinessAccount
    {
        if ($account->status === BusinessAccount::ACTIVE) {
            $account->forceFill(['status' => BusinessAccount::SUSPENDED, 'status_reason' => $reason, 'status_note' => $note, 'suspended_at' => now()])->save();
        }

        return $account;
    }

    public function reactivate(BusinessAccount $account, ?string $note = null): BusinessAccount
    {
        if ($account->status === BusinessAccount::SUSPENDED) {
            $account->forceFill(['status' => BusinessAccount::ACTIVE, 'status_reason' => null, 'status_note' => $note, 'suspended_at' => null])->save();
        }

        return $account;
    }

    /**
     * @param  array{email: string, name?: ?string, role: string, department_id?: ?int, policy_id?: ?int, monthly_allowance_minor?: ?int, employee_ref?: ?string}  $data
     *
     * @throws BusinessRefusal
     */
    public function invite(BusinessMember $inviter, array $data): BusinessMember
    {
        if (! Abilities::allows($inviter, 'manage_people') || ! in_array($data['role'], Abilities::assignableRoles($inviter), true)) {
            throw BusinessRefusal::one('role_not_allowed', 'You cannot invite someone with that role.', 403);
        }

        $email = strtolower(trim($data['email']));
        $account = $inviter->account;
        $existing = BusinessMember::where('business_account_id', $account->id)->where('email', $email)->first();

        if ($existing !== null && $existing->status !== BusinessMember::REMOVED) {
            throw BusinessRefusal::one('already_member', $existing->status === BusinessMember::ACTIVE ? 'That person is already on this account.' : 'That person already has an invitation.', 409);
        }

        $this->assertOwnRecords($account->id, $data);
        $token = Str::random(48);

        $attributes = [
            'business_account_id' => $account->id,
            'user_id' => null,
            'email' => $email,
            'name' => $data['name'] ?? null,
            'role' => $data['role'],
            'status' => BusinessMember::INVITED,
            'department_id' => $data['department_id'] ?? null,
            'policy_id' => $data['policy_id'] ?? null,
            'monthly_allowance_minor' => $data['monthly_allowance_minor'] ?? null,
            'employee_ref' => $data['employee_ref'] ?? null,
            'invite_token_hash' => hash('sha256', $token),
            'invite_expires_at' => now()->addDays(max(1, (int) config('ehail_business.invitations.valid_days', 14))),
            'invited_by' => $inviter->user_id,
            'joined_at' => null,
            'removed_at' => null,
        ];

        if ($existing !== null) {
            $existing->forceFill($attributes)->save();
            $member = $existing;
        } else {
            $member = BusinessMember::create($attributes);
        }

        Notification::route('mail', $email)->notify(new MemberInvitedNotification($member, $token));

        return $member;
    }

    public function findInvitation(string $token): ?BusinessMember
    {
        return BusinessMember::where('invite_token_hash', hash('sha256', $token))->where('status', BusinessMember::INVITED)->first();
    }

    /**
     * Pending invitations addressed to this person's email (they can accept from the portal too).
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, BusinessMember>
     */
    public function invitationsFor(User $user): \Illuminate\Database\Eloquent\Collection
    {
        // Matching by email is only safe for a proven address (R-46 security S-01); otherwise the emailed link is the proof.
        if ($user->email_verified_at === null) {
            return new \Illuminate\Database\Eloquent\Collection;
        }

        return BusinessMember::with('account')->where('email', strtolower((string) $user->email))
            ->where('status', BusinessMember::INVITED)->where('invite_expires_at', '>', now())->get();
    }

    /**
     * @throws BusinessRefusal
     */
    public function accept(BusinessMember $member, User $user): BusinessMember
    {
        if ($member->status !== BusinessMember::INVITED) {
            throw BusinessRefusal::one('invitation_used', 'This invitation was already used or withdrawn.', 410);
        }

        if ($member->invite_expires_at === null || $member->invite_expires_at->isPast()) {
            throw BusinessRefusal::one('invitation_expired', 'This invitation has expired. Ask the account admin to send a new one.', 410);
        }

        if (strtolower((string) $user->email) !== strtolower($member->email)) {
            throw BusinessRefusal::one('invitation_other_email', 'This invitation is for a different email address. Sign in with '.$member->email.'.', 409);
        }

        if (BusinessMember::where('business_account_id', $member->business_account_id)->where('user_id', $user->id)->where('status', BusinessMember::ACTIVE)->exists()) {
            throw BusinessRefusal::one('already_member', 'You are already on this account.', 409);
        }

        DB::transaction(function () use ($member, $user) {
            $member->forceFill([
                'user_id' => $user->id,
                'name' => $member->name ?? $user->name,
                'status' => BusinessMember::ACTIVE,
                'invite_token_hash' => null,
                'joined_at' => now(),
            ])->save();

            $this->ensureProfile($member);
        });

        return $member->fresh();
    }

    /**
     * @param  array<string, mixed>  $data  role, department_id, policy_id, monthly_allowance_minor, employee_ref
     *
     * @throws BusinessRefusal
     */
    public function updateMember(BusinessMember $actor, BusinessMember $member, array $data): BusinessMember
    {
        $this->assertCanManage($actor, $member);

        if (isset($data['role']) && $data['role'] !== $member->role) {
            if (! in_array($data['role'], Abilities::assignableRoles($actor), true)) {
                throw BusinessRefusal::one('role_not_allowed', 'You cannot give that role.', 403);
            }

            $this->assertNotLastAdmin($member, $data['role']);
        }

        $this->assertOwnRecords($member->business_account_id, $data);

        DB::transaction(function () use ($member, $data) {
            $member->forceFill(array_intersect_key($data, array_flip(['role', 'department_id', 'policy_id', 'monthly_allowance_minor', 'employee_ref', 'name'])))->save();
            $this->ensureProfile($member);
        });

        return $member->fresh();
    }

    /**
     * @throws BusinessRefusal
     */
    public function remove(BusinessMember $actor, BusinessMember $member): void
    {
        $this->assertCanManage($actor, $member);
        $this->assertNotLastAdmin($member, null);

        DB::transaction(function () use ($member) {
            $member->forceFill(['status' => BusinessMember::REMOVED, 'removed_at' => now(), 'invite_token_hash' => null])->save();
            $this->ensureProfile($member);
        });
    }

    /**
     * Keep the member's business payment method in step with their state: active riders have one,
     * everyone else has it removed (money's PaymentMethod row, type business_account).
     */
    public function ensureProfile(BusinessMember $member): void
    {
        $shouldHave = $member->isActive() && $member->mayRide() && $member->user_id !== null;
        $method = $member->payment_method_id ? PaymentMethod::find($member->payment_method_id) : null;

        if ($shouldHave) {
            if ($method === null || $method->status !== 'active' || (int) $method->user_id !== (int) $member->user_id) {
                $method = PaymentMethod::create([
                    'user_id' => $member->user_id,
                    'type' => PaymentMethod::BUSINESS_ACCOUNT,
                    'business_account_id' => $member->business_account_id,
                    'status' => 'active',
                    'is_default' => false,
                    'reusable' => false,
                ]);
                $member->forceFill(['payment_method_id' => $method->id])->save();
            }

            return;
        }

        if ($method !== null && $method->status === 'active') {
            $method->forceFill(['status' => 'removed', 'removed_at' => now(), 'is_default' => false])->save();
        }
    }

    private function assertCanManage(BusinessMember $actor, BusinessMember $member): void
    {
        if (! Abilities::allows($actor, 'manage_people') || $actor->business_account_id !== $member->business_account_id) {
            throw BusinessRefusal::one('not_allowed', 'You cannot change people on this account.', 403);
        }

        if ($actor->role === BusinessMember::MANAGER && $member->role !== BusinessMember::TRAVELLER) {
            throw BusinessRefusal::one('not_allowed', 'Travel managers can change travellers only.', 403);
        }
    }

    private function assertNotLastAdmin(BusinessMember $member, ?string $newRole): void
    {
        if ($member->role !== BusinessMember::ADMIN || $newRole === BusinessMember::ADMIN || $member->status !== BusinessMember::ACTIVE) {
            return;
        }

        $admins = BusinessMember::where('business_account_id', $member->business_account_id)->where('role', BusinessMember::ADMIN)->where('status', BusinessMember::ACTIVE)->count();

        if ($admins <= 1) {
            throw BusinessRefusal::one('last_admin', 'An account needs at least one account admin. Make someone else admin first.', 409);
        }
    }

    /**
     * Department and policy ids must belong to the same account (no cross-account references).
     *
     * @param  array<string, mixed>  $data
     */
    private function assertOwnRecords(int $accountId, array $data): void
    {
        if (! empty($data['department_id']) && ! BusinessDepartment::where('business_account_id', $accountId)->whereKey($data['department_id'])->exists()) {
            throw BusinessRefusal::one('department_unknown', 'Choose one of this account\'s departments.');
        }

        if (! empty($data['policy_id']) && ! BusinessPolicy::where('business_account_id', $accountId)->whereKey($data['policy_id'])->exists()) {
            throw BusinessRefusal::one('policy_unknown', 'Choose one of this account\'s ride policies.');
        }
    }
}
