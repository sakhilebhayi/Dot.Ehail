<?php

namespace App\Domain\Security;

use App\Domain\Business\Models\BusinessMember;
use App\Domain\Verification\Models\VerificationDocument;
use App\Models\AuditLog;
use App\Models\DriverDocument;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Closing an account (POPIA s24, docs/R46_PRIVACY.md section 6). A person with no ride, money or safety history
 * is deleted outright. Anyone else is anonymised: the records the law or safety needs (rides, payments, ledger,
 * receipts, payouts, incidents, reports, suspensions, support cases, audit) stay, linked to a tombstone account
 * that names nobody and can never sign in, while everything only the person needed goes: contact details,
 * saved cards' tokens, trusted contacts, saved places, push and SMS channels, notifications, sessions, devices,
 * identity numbers in clear, and identity document files. The keyed ID blind index stays, so a suspended driver
 * cannot re-register with the same identity number.
 *
 * The ledger and receipts are immutable (database triggers), so a hard delete of a person with history would fail
 * on PostgreSQL or silently cascade away reports and suspensions on SQLite (R-46 security S-21).
 */
class AccountClosure
{
    /** Tables whose rows make a person part of the platform's records. */
    private const HISTORY = [
        'rides' => ['passenger_id', 'driver_id'],
        'ride_payments' => ['user_id'],
        'driver_earnings' => ['driver_id', 'payee_user_id'],
        'payouts' => ['payee_user_id'],
        'receipts' => ['passenger_id'],
        'safety_incidents' => ['reporter_id', 'subject_user_id'],
        'user_reports' => ['reporter_id', 'reported_user_id'],
        'support_cases' => ['user_id', 'counterparty_user_id'],
        'driver_suspensions' => ['user_id'],
        'business_members' => ['user_id'],
        'fleets' => ['owner_user_id'],
    ];

    public function hasHistory(User $user): bool
    {
        foreach (self::HISTORY as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $query = DB::table($table)->where(function ($q) use ($columns, $user) {
                foreach ($columns as $column) {
                    $q->orWhere($column, $user->id);
                }
            });

            if ($query->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @throws ValidationException when a ride is still under way
     */
    public function assertClosable(User $user): void
    {
        $active = DB::table('rides')->where('active_passenger_id', $user->id)->orWhere('active_driver_id', $user->id)->exists();

        if ($active) {
            throw ValidationException::withMessages(['password' => 'Finish or cancel your current ride before closing your account.'])->errorBag('deleteUser');
        }
    }

    public function anonymise(User $user): void
    {
        $files = $this->identityFiles($user);

        DB::transaction(function () use ($user) {
            $id = $user->id;

            foreach (['trusted_contacts', 'push_subscriptions', 'notification_sms_numbers', 'notification_preferences', 'passenger_saved_places', 'security_known_devices', 'sessions', 'idempotency_keys', 'driver_availability', 'driver_locations'] as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->where(in_array($table, ['driver_locations'], true) ? 'driver_id' : 'user_id', $id)->delete();
                }
            }

            DB::table('notifications')->where('notifiable_type', $user->getMorphClass())->where('notifiable_id', $id)->delete();

            // Saved cards: the reusable Paystack authorisation goes; the row stays for the payments that used it.
            DB::table('payment_methods')->where('user_id', $id)->update(['authorization_code' => null, 'signature' => null, 'status' => 'removed', 'is_default' => false, 'updated_at' => now()]);
            DB::table('payout_accounts')->where('user_id', $id)->update(['is_active' => false, 'updated_at' => now()]);
            DB::table('business_members')->where('user_id', $id)->where('status', '!=', BusinessMember::REMOVED)->update(['status' => BusinessMember::REMOVED, 'updated_at' => now()]);

            // Identity numbers in clear go; the keyed blind index (id_number_hash) stays to stop re-registration.
            DB::table('driver_profiles')->where('user_id', $id)->update([
                'id_number' => encrypt('[removed at account closure]', false),
                'license_number' => encrypt('[removed at account closure]', false),
                'is_online' => false,
                'updated_at' => now(),
            ]);

            $user->tokens()->delete();
            $user->teams()->detach();

            $user->forceFill([
                'name' => 'Closed account',
                'email' => 'closed-'.$id.'-'.Str::lower(Str::random(10)).'@closed.invalid',
                'password' => Hash::make(Str::random(64)),
                'remember_token' => null,
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
                'email_verified_at' => null,
                'profile_photo_path' => null,
                'closed_at' => now(),
            ])->save();

            AuditLog::record('account.closed', null, $user, ['mode' => 'anonymised']);
        });

        foreach ($files as [$disk, $path]) {
            Storage::disk($disk)->delete($path);
        }
    }

    /**
     * @return list<array{0: string, 1: string}> [disk, path] of this person's identity document files
     */
    private function identityFiles(User $user): array
    {
        $files = VerificationDocument::query()->where('user_id', $user->id)->whereNotNull('file_path')
            ->get(['disk', 'file_path'])->map(fn ($d) => [(string) ($d->disk ?: 'driver-documents'), (string) $d->file_path])->all();

        $legacy = DriverDocument::withoutGlobalScopes()
            ->whereIn('driver_profile_id', DB::table('driver_profiles')->where('user_id', $user->id)->select('id'))
            ->whereNotNull('file_path')->get(['file_path'])->map(fn ($d) => ['driver-documents', (string) $d->file_path])->all();

        return [...$files, ...$legacy];
    }
}
