<?php

namespace App\Domain\Business;

use App\Domain\Business\Data\TripRequest;
use App\Domain\Business\Models\BusinessApproval;
use App\Domain\Business\Models\BusinessMember;
use App\Domain\Business\Notifications\ApprovalDecidedNotification;
use App\Domain\Business\Notifications\ApprovalRequestedNotification;
use App\Support\Ui\MoneyFormatter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Approval workflow: a traveller asks (pending, decide_by = now + the policy's window), an approver of the
 * same account (account admin or travel manager, never the requester) approves or declines before
 * decide_by, after which it expires. An approval is good for one booking before use_by, and only for a
 * ride that matches it (same rider, category, stops within the tolerance, fare within the tolerance).
 */
class Approvals
{
    /**
     * @param  list<array{code: string, message: string}>  $reasons
     */
    public function request(TripRequest $trip, array $reasons): BusinessApproval
    {
        $window = max(5, (int) ($trip->policy?->approval_window_minutes ?? 30));

        $approval = BusinessApproval::create([
            'business_account_id' => $trip->account->id,
            'requester_member_id' => $trip->booker->id,
            'traveller_member_id' => $trip->isGuest ? null : $trip->traveller->id,
            'guest_name' => $trip->isGuest ? $trip->guestName : null,
            'status' => BusinessApproval::PENDING,
            'category_code' => $trip->category,
            'pickup_lat' => $trip->pickupLat,
            'pickup_lng' => $trip->pickupLng,
            'pickup_address' => $trip->pickupAddress,
            'dropoff_lat' => $trip->dropoffLat,
            'dropoff_lng' => $trip->dropoffLng,
            'dropoff_address' => $trip->dropoffAddress,
            'estimate_minor' => $trip->fareMinor,
            'currency' => $trip->currency,
            'purpose' => $trip->purpose,
            'trip_at' => $trip->at->isFuture() && $trip->at->diffInMinutes(now(), true) > 5 ? $trip->at : null,
            'reasons' => $reasons,
            'decide_by' => now()->addMinutes($window),
        ]);

        $approvers = $this->approversFor($approval);

        if ($approvers !== []) {
            Notification::route('mail', $approvers)->notify(new ApprovalRequestedNotification($approval));
        }

        return $approval;
    }

    /**
     * @throws BusinessRefusal
     */
    public function decide(BusinessApproval $approval, BusinessMember $approver, bool $approve, ?string $note): BusinessApproval
    {
        if (! Abilities::allows($approver, 'decide_approvals') || $approver->business_account_id !== $approval->business_account_id) {
            throw BusinessRefusal::one('not_an_approver', 'Only account admins and travel managers of this account can decide approvals.', 403);
        }

        if ($approver->id === $approval->requester_member_id) {
            throw BusinessRefusal::one('own_request', 'You cannot decide your own request.', 403);
        }

        $decided = DB::transaction(function () use ($approval, $approver, $approve, $note) {
            $locked = BusinessApproval::whereKey($approval->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === BusinessApproval::PENDING && $locked->decide_by->isPast()) {
                $locked->forceFill(['status' => BusinessApproval::EXPIRED])->save();
            }

            if ($locked->status !== BusinessApproval::PENDING) {
                throw BusinessRefusal::one('already_decided', 'This request is no longer waiting for a decision ('.strtolower($locked->statusLabel()).').', 409);
            }

            $locked->forceFill([
                'status' => $approve ? BusinessApproval::APPROVED : BusinessApproval::DECLINED,
                'decided_by' => $approver->user_id,
                'decided_at' => now(),
                'decision_note' => $note !== null ? mb_substr($note, 0, 300) : null,
                'use_by' => $approve ? now()->addMinutes(max(5, (int) config('ehail_business.approvals.use_within_minutes', 120))) : null,
            ])->save();

            return $locked;
        });

        $email = $decided->requester?->user?->email ?? $decided->requester?->email;

        if ($email) {
            Notification::route('mail', $email)->notify(new ApprovalDecidedNotification($decided));
        }

        return $decided;
    }

    public function withdraw(BusinessApproval $approval, BusinessMember $requester): void
    {
        if ($approval->requester_member_id !== $requester->id) {
            throw BusinessRefusal::one('not_yours', 'Only the person who asked can withdraw this request.', 403);
        }

        BusinessApproval::whereKey($approval->id)->whereIn('status', [BusinessApproval::PENDING, BusinessApproval::APPROVED])
            ->update(['status' => BusinessApproval::WITHDRAWN, 'updated_at' => now()]);
    }

    /** Pending requests past their decision time become expired; returns how many. */
    public function expireDue(): int
    {
        return BusinessApproval::where('status', BusinessApproval::PENDING)->where('decide_by', '<=', now())
            ->update(['status' => BusinessApproval::EXPIRED, 'updated_at' => now()]);
    }

    /**
     * Why this approval does not cover this ride, or null when it does.
     */
    public function mismatch(BusinessApproval $approval, TripRequest $trip): ?string
    {
        $tolerance = (int) config('ehail_business.approvals.fare_tolerance_bp', 1500);
        $stops = (int) config('ehail_business.approvals.stop_tolerance_m', 1000);

        return match (true) {
            $approval->status === BusinessApproval::PENDING => 'Your approval request is still waiting for a decision.',
            $approval->status !== BusinessApproval::APPROVED => 'That approval cannot be used ('.strtolower($approval->statusLabel()).').',
            $approval->use_by === null || $approval->use_by->isPast() => 'That approval has run out. Ask again.',
            $approval->business_account_id !== $trip->account->id || $approval->requester_member_id !== $trip->booker->id => 'That approval belongs to someone else.',
            $trip->isGuest !== ($approval->guest_name !== null) || (! $trip->isGuest && (int) $approval->traveller_member_id !== $trip->traveller->id) => 'That approval was for a different rider.',
            $approval->category_code !== null && $approval->category_code !== $trip->category => "That approval was for the {$approval->category_code} category.",
            $this->metres($approval->pickup_lat, $approval->pickup_lng, $trip->pickupLat, $trip->pickupLng) > $stops
                || $this->metres($approval->dropoff_lat, $approval->dropoff_lng, $trip->dropoffLat, $trip->dropoffLng) > $stops => 'That approval was for a different pickup or drop-off.',
            $trip->fareMinor > intdiv($approval->estimate_minor * (10_000 + $tolerance), 10_000) => 'The fare is now '.MoneyFormatter::format($trip->fareMinor, $trip->currency).', more than the approved '.MoneyFormatter::format($approval->estimate_minor, $approval->currency).' allows. Ask again.',
            default => null,
        };
    }

    public function findMatching(TripRequest $trip): ?BusinessApproval
    {
        return BusinessApproval::query()
            ->where('business_account_id', $trip->account->id)
            ->where('requester_member_id', $trip->booker->id)
            ->where('status', BusinessApproval::APPROVED)
            ->where('use_by', '>', now())
            ->latest('id')->get()
            ->first(fn (BusinessApproval $a) => $this->mismatch($a, $trip) === null);
    }

    public function markUsed(BusinessApproval $approval, int $rideId): void
    {
        $updated = BusinessApproval::whereKey($approval->id)->where('status', BusinessApproval::APPROVED)
            ->update(['status' => BusinessApproval::USED, 'ride_id' => $rideId, 'updated_at' => now()]);

        if ($updated !== 1) {
            throw BusinessRefusal::one('approval_used', 'That approval was already used for another ride.', 409);
        }
    }

    /**
     * Emails of the people who may decide this request: admins of the account, and travel managers of
     * the rider's department (or every travel manager when the rider has no department / a manager has none).
     *
     * @return list<string>
     */
    public function approversFor(BusinessApproval $approval): array
    {
        $riderDepartment = ($approval->traveller ?? $approval->requester)?->department_id;

        return BusinessMember::query()->with('user')
            ->where('business_account_id', $approval->business_account_id)
            ->where('status', BusinessMember::ACTIVE)
            ->whereIn('role', [BusinessMember::ADMIN, BusinessMember::MANAGER])
            ->whereKeyNot($approval->requester_member_id)
            ->get()
            ->filter(fn (BusinessMember $m) => $m->role === BusinessMember::ADMIN || $m->department_id === null || $riderDepartment === null || $m->department_id === $riderDepartment)
            ->map(fn (BusinessMember $m) => $m->user?->email ?? $m->email)
            ->unique()->values()->all();
    }

    private function metres(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6_371_000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(min(1, sqrt($a)));
    }
}
