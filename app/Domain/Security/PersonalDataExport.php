<?php

namespace App\Domain\Security;

use App\Domain\Earnings\Models\PayoutAccount;
use App\Domain\Payments\Models\PaymentMethod;
use App\Domain\Safety\Models\TrustedContact;
use App\Domain\Safety\RideSafety;
use App\Models\DriverProfile;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Download my data" (POPIA s23 access request, docs/R46_PRIVACY.md section 7). Everything Dot.Ehail holds about
 * the signed-in person, as one JSON document. Other people appear only as far as the person already saw them:
 * a driver's or rider's first name, never their contact details, ratings they left or positions. Secrets are
 * never included (password hash, 2FA secrets, card authorisation tokens, the trip PIN); bank and card numbers are
 * shown as the last four digits. Location trails are summarised per ride, not dumped point by point.
 */
class PersonalDataExport
{
    /**
     * @return array<string, mixed>
     */
    public function for(User $user): array
    {
        $id = $user->id;

        return [
            'about' => 'Personal information Dot.Ehail holds about you, exported '.now()->toIso8601String().'. Questions or corrections: contact support, or the information officer named in the privacy policy.',
            'account' => [
                'name' => $user->name,
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'created_at' => $user->created_at?->toIso8601String(),
                'two_factor_enabled' => $user->two_factor_confirmed_at !== null,
                'is_platform_operator' => (bool) $user->is_platform_operator,
            ],
            'rides_as_passenger' => Ride::query()->where('passenger_id', $id)->orderBy('id')->with('driver:id,name')->get()->map(fn (Ride $r) => [
                'id' => $r->id,
                'status' => $r->status,
                'requested_at' => $r->requested_at?->toIso8601String(),
                'completed_at' => $r->completed_at?->toIso8601String(),
                'pickup' => ['address' => $r->pickup_address, 'lat' => $r->pickup_lat, 'lng' => $r->pickup_lng],
                'dropoff' => ['address' => $r->dropoff_address, 'lat' => $r->dropoff_lat, 'lng' => $r->dropoff_lng],
                'category' => $r->vehicle_type,
                'estimated_fare' => $r->estimated_fare,
                'final_fare' => $r->final_fare,
                'payment_status' => $r->payment_status,
                'driver_first_name' => $r->driver ? RideSafety::firstName($r->driver->name) : null,
            ])->all(),
            'rides_as_driver' => Ride::query()->where('driver_id', $id)->orderBy('id')->get()->map(fn (Ride $r) => [
                'id' => $r->id,
                'status' => $r->status,
                'accepted_at' => $r->accepted_at?->toIso8601String(),
                'completed_at' => $r->completed_at?->toIso8601String(),
                'final_fare' => $r->final_fare,
                'recorded_trail_points' => Schema::hasTable('trip_points') ? DB::table('trip_points')->where('ride_id', $r->id)->where('driver_id', $id)->count() : 0,
            ])->all(),
            'driver_profile' => $this->driverProfile($id),
            'identity_documents' => $this->rows('verification_documents', 'user_id', $id, ['id', 'subject', 'type', 'status', 'original_filename', 'expires_on', 'submitted_at', 'reviewed_at', 'reject_reason']),
            'ratings_given' => $this->rows('ride_ratings', 'rated_by', $id, ['ride_id', 'rating', 'comment', 'created_at']),
            'vehicles' => $this->vehicles($id),
            'saved_cards' => PaymentMethod::query()->where('user_id', $id)->get()->map(fn (PaymentMethod $m) => [
                'type' => $m->type, 'brand' => $m->brand, 'last4' => $m->last4, 'status' => $m->status, 'added_at' => $m->created_at?->toIso8601String(),
            ])->all(),
            'payments' => $this->rows('ride_payments', 'user_id', $id, ['id', 'ride_id', 'purpose', 'provider', 'amount_cents', 'currency', 'status', 'paid_at', 'created_at']),
            'receipts' => $this->rows('receipts', 'passenger_id', $id, ['id', 'ride_id', 'number', 'total_minor', 'currency', 'issued_at']),
            'earnings' => $this->rows('driver_earnings', 'driver_id', $id, ['id', 'kind', 'ride_id', 'currency', 'fare_minor', 'commission_minor', 'deductions_minor', 'net_minor', 'status', 'earned_at']),
            'payouts' => $this->rows('payouts', 'payee_user_id', $id, ['id', 'reference', 'amount_minor', 'currency', 'status', 'created_at']),
            'payout_accounts' => PayoutAccount::query()->where('user_id', $id)->get()->map(fn (PayoutAccount $a) => [
                'bank' => $a->bank_name, 'account' => '•••• '.$a->account_last4, 'verification_status' => $a->verification_status, 'active' => $a->is_active, 'added_at' => $a->created_at?->toIso8601String(),
            ])->all(),
            'trusted_contacts' => TrustedContact::query()->where('user_id', $id)->get()->map(fn (TrustedContact $c) => [
                'name' => $c->name, 'email' => $c->email, 'phone' => $c->phone, 'relationship' => $c->relationship, 'confirmed_at' => $c->confirmed_at, 'opted_out_at' => $c->opted_out_at, 'added_at' => $c->created_at?->toIso8601String(),
            ])->all(),
            'safety_incidents_reported' => $this->rows('safety_incidents', 'reporter_id', $id, ['id', 'reference', 'ride_id', 'kind', 'severity', 'status', 'description', 'location_lat', 'location_lng', 'created_at', 'resolved_at']),
            'reports_made' => $this->rows('user_reports', 'reporter_id', $id, ['id', 'ride_id', 'category', 'description', 'status', 'created_at']),
            'support_cases' => $this->supportCases($id),
            'ratings_received' => $this->rows('ride_ratings', 'rated_user_id', $id, ['ride_id', 'rating', 'rated_role', 'created_at']),
            'saved_places' => $this->rows('passenger_saved_places', 'user_id', $id, ['label', 'address', 'lat', 'lng', 'created_at']),
            'business_memberships' => $this->rows('business_members', 'user_id', $id, ['business_account_id', 'role', 'status', 'joined_at']),
            'notification_preferences' => $this->rows('notification_preferences', 'user_id', $id, ['*']),
            'notifications' => DB::table('notifications')->where('notifiable_type', $user->getMorphClass())->where('notifiable_id', $id)
                ->orderBy('created_at')->get(['type', 'data', 'read_at', 'created_at'])
                ->map(fn ($n) => ['type' => class_basename((string) $n->type), 'data' => json_decode((string) $n->data, true), 'read_at' => $n->read_at, 'created_at' => $n->created_at])->all(),
            'sign_ins' => [
                'known_browsers' => Schema::hasTable('security_known_devices') ? DB::table('security_known_devices')->where('user_id', $id)->count() : 0,
                'active_sessions' => DB::table('sessions')->where('user_id', $id)->count(),
            ],
            'actions_you_took' => DB::table('audit_logs')->where('actor_user_id', $id)->orderBy('id')->limit(1000)->get(['action', 'created_at'])->all(),
        ];
    }

    /**
     * @param  list<string>  $columns
     * @return list<array<string, mixed>>
     */
    private function rows(string $table, string $column, int $id, array $columns): array
    {
        if (! Schema::hasTable($table)) {
            return [];
        }

        $available = $columns === ['*'] ? ['*'] : array_values(array_intersect($columns, Schema::getColumnListing($table)));

        return DB::table($table)->where($column, $id)->orderBy(in_array('id', Schema::getColumnListing($table), true) ? 'id' : $column)
            ->get($available)->map(fn ($row) => (array) $row)->all();
    }

    /** @return array<string, mixed>|null */
    private function driverProfile(int $id): ?array
    {
        $profile = DriverProfile::withoutGlobalScope('user')->where('user_id', $id)->first();

        return $profile === null ? null : [
            'status' => $profile->status,
            'licence_number' => $profile->license_number,
            'id_number' => $profile->id_number,
            'rating' => $profile->rating,
            'fleet_id' => $profile->fleet_id,
            'created_at' => $profile->created_at?->toIso8601String(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function vehicles(int $id): array
    {
        $profileIds = DB::table('driver_profiles')->where('user_id', $id)->pluck('id');

        return DB::table('vehicles')->whereIn('driver_profile_id', $profileIds)->get(['make', 'model', 'year', 'color', 'plate_number', 'type', 'created_at'])->map(fn ($v) => (array) $v)->all();
    }

    /** @return list<array<string, mixed>> */
    private function supportCases(int $id): array
    {
        if (! Schema::hasTable('support_cases')) {
            return [];
        }

        return DB::table('support_cases')->where('user_id', $id)->orderBy('id')->get(['id', 'reference', 'category', 'status', 'subject', 'created_at'])
            ->map(function ($case) use ($id) {
                $case = (array) $case;
                $case['your_messages'] = DB::table('support_messages')->where('support_case_id', $case['id'])->where('author_id', $id)
                    ->orderBy('id')->get(['body', 'created_at'])->map(fn ($m) => (array) $m)->all();

                return $case;
            })->all();
    }
}
