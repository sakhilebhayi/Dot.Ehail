<?php

namespace App\Domain\Fleet;

use App\Domain\Fleet\Exceptions\FleetActionRefused;
use App\Domain\Fleet\Models\FleetMember;
use App\Models\AuditLog;
use App\Models\Fleet;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Registering a fleet and keeping its business profile and members.
 *
 * A new fleet is pending until a platform operator approves it (the existing vetting rule: its owner cannot review
 * anyone until then). Its owner becomes a member with role owner. Managers are existing Dot accounts (sign-up is
 * through InfoDot), added by email by the owner, with a chosen set of areas.
 */
final class FleetRegistration
{
    /**
     * @param  array{name: string, kind: string, trading_name?: ?string, registration_number?: ?string, contact_email?: ?string, contact_phone?: ?string, base_city?: ?string}  $data
     */
    public function register(User $owner, array $data): Fleet
    {
        return DB::transaction(function () use ($owner, $data) {
            $fleet = Fleet::query()->create([
                'name' => $data['name'],
                'owner_user_id' => $owner->id,
                'status' => Fleet::STATUS_PENDING,
                'kind' => $data['kind'],
                'trading_name' => $data['trading_name'] ?? null,
                'registration_number' => $data['registration_number'] ?? null,
                'contact_email' => $data['contact_email'] ?? null,
                'contact_phone' => $data['contact_phone'] ?? null,
                'base_city' => $data['base_city'] ?? null,
            ]);

            $this->ensureOwnerMember($fleet);
            AuditLog::record('fleet.registered', $owner, $fleet, ['kind' => $fleet->kind]);

            return $fleet;
        });
    }

    public function ensureOwnerMember(Fleet $fleet): void
    {
        FleetMember::query()->updateOrCreate(
            ['fleet_id' => $fleet->id, 'user_id' => $fleet->owner_user_id],
            ['role' => FleetMember::ROLE_OWNER, 'revoked_at' => null, 'scopes' => null],
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateProfile(Fleet $fleet, array $data, User $actor): Fleet
    {
        $fleet->fill(array_intersect_key($data, array_flip(['name', 'kind', 'trading_name', 'registration_number', 'contact_email', 'contact_phone', 'base_city', 'payee_mode'])))->save();
        AuditLog::record('fleet.profile_updated', $actor, $fleet, ['fields' => array_keys($fleet->getChanges())]);

        return $fleet;
    }

    /**
     * @param  list<string>  $scopes
     */
    public function addManager(Fleet $fleet, string $email, array $scopes, User $actor): FleetMember
    {
        $user = User::query()->where('email', mb_strtolower(trim($email)))->first()
            ?? User::query()->whereRaw('lower(email) = ?', [mb_strtolower(trim($email))])->first();

        if ($user === null) {
            throw new FleetActionRefused('no_account', 'There is no Dot account with that email. Ask them to sign in to Dot.Ehail once with InfoDot, then add them.');
        }

        if ((int) $user->id === (int) $fleet->owner_user_id) {
            throw new FleetActionRefused('is_owner', 'That is the fleet owner.');
        }

        $member = FleetMember::query()->updateOrCreate(
            ['fleet_id' => $fleet->id, 'user_id' => $user->id],
            ['role' => FleetMember::ROLE_MANAGER, 'scopes' => $this->cleanScopes($scopes), 'added_by' => $actor->id, 'revoked_at' => null],
        );

        AuditLog::record('fleet.manager_added', $actor, $fleet, ['user_id' => $user->id, 'scopes' => $member->scopes]);

        return $member;
    }

    /**
     * @param  list<string>  $scopes
     */
    public function updateManager(Fleet $fleet, FleetMember $member, array $scopes, User $actor): FleetMember
    {
        $this->assertManagerOf($fleet, $member);
        $member->forceFill(['scopes' => $this->cleanScopes($scopes)])->save();
        AuditLog::record('fleet.manager_scopes_changed', $actor, $fleet, ['user_id' => $member->user_id, 'scopes' => $member->scopes]);

        return $member;
    }

    public function revokeManager(Fleet $fleet, FleetMember $member, User $actor): void
    {
        $this->assertManagerOf($fleet, $member);
        $member->forceFill(['revoked_at' => now()])->save();
        AuditLog::record('fleet.manager_revoked', $actor, $fleet, ['user_id' => $member->user_id]);
    }

    /**
     * Platform operators only: let this fleet's owner see masked identity numbers on applications (default off).
     */
    public function setIdentityReview(Fleet $fleet, bool $granted, User $operator): Fleet
    {
        if (! $operator->isPlatformOperator()) {
            throw new FleetActionRefused('not_allowed', 'Only platform operators can change identity-review rights.', 403);
        }

        $fleet->forceFill([
            'identity_review_granted_at' => $granted ? now() : null,
            'identity_review_granted_by' => $granted ? $operator->id : null,
        ])->save();

        AuditLog::record($granted ? 'fleet.identity_review_granted' : 'fleet.identity_review_withdrawn', $operator, $fleet);

        return $fleet;
    }

    /**
     * @param  list<string>  $scopes
     * @return list<string>
     */
    private function cleanScopes(array $scopes): array
    {
        return array_values(array_intersect(array_keys(FleetMember::SCOPES), $scopes));
    }

    private function assertManagerOf(Fleet $fleet, FleetMember $member): void
    {
        if ((int) $member->fleet_id !== (int) $fleet->id || $member->isOwner()) {
            throw new FleetActionRefused('not_this_fleet', 'That person is not a manager of this fleet.', 404);
        }
    }
}
