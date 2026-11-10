<?php

namespace App\Domain\Business;

use App\Domain\Business\Data\PolicyDecision;
use App\Domain\Business\Data\TripRequest;
use App\Domain\Business\Models\BusinessAccount;
use App\Domain\Business\Models\BusinessApproval;
use App\Domain\Business\Models\BusinessMember;
use App\Domain\Geo\LatLng;
use App\Domain\Geo\Zones\ZoneResolver;
use App\Support\Ui\MoneyFormatter;

/**
 * Checks one ride against the account, the booker's rights, the traveller's policy and the limits.
 * Every failed rule is reported (not only the first), each with a plain reason. The limits read here
 * are a preview; SpendLimits::reserve is the atomic check at booking time.
 */
class PolicyEvaluator
{
    private const DAYS = [1 => 'Mondays', 2 => 'Tuesdays', 3 => 'Wednesdays', 4 => 'Thursdays', 5 => 'Fridays', 6 => 'Saturdays', 7 => 'Sundays'];

    public function __construct(private ZoneResolver $zones, private SpendLimits $limits, private Approvals $approvals) {}

    public function evaluate(TripRequest $trip): PolicyDecision
    {
        $refusals = [];
        $money = fn (int $minor) => MoneyFormatter::format($minor, $trip->currency);

        foreach ($this->accountRefusals($trip->account) as $reason) {
            $refusals[] = $reason;
        }

        if (! Abilities::allows($trip->booker, 'ride')) {
            $refusals[] = ['code' => 'member_cannot_ride', 'message' => 'Your role on this business account cannot book rides on it.'];
        }

        if ($trip->traveller->id !== $trip->booker->id && ! Abilities::allows($trip->booker, 'book_for_others')) {
            $refusals[] = ['code' => 'cannot_book_for_others', 'message' => 'Only account admins and travel managers can book rides for other people.'];
        }

        if ($trip->traveller->id !== $trip->booker->id && (! $trip->traveller->isActive() || ! $trip->traveller->mayRide() || $trip->traveller->business_account_id !== $trip->account->id)) {
            $refusals[] = ['code' => 'traveller_not_active', 'message' => 'That person is not an active traveller on this account.'];
        }

        $policy = $trip->policy;

        if ($trip->isGuest) {
            if (! $trip->booker->isApprover() && ! ($policy?->guest_bookings ?? false)) {
                $refusals[] = ['code' => 'guest_not_allowed', 'message' => 'Your ride policy does not allow booking rides for guests.'];
            }

            if (blank($trip->guestName) || blank($trip->guestPhone)) {
                $refusals[] = ['code' => 'guest_details_missing', 'message' => "A guest ride needs the guest's name and phone number so the driver can find them."];
            }
        }

        if ($trip->currency !== $trip->account->currency) {
            $refusals[] = ['code' => 'currency_mismatch', 'message' => "This ride is priced in {$trip->currency}; the business account is billed in {$trip->account->currency}."];
        }

        if ($policy !== null) {
            if (is_array($policy->allowed_categories) && $policy->allowed_categories !== [] && ! in_array($trip->category, $policy->allowed_categories, true)) {
                $refusals[] = ['code' => 'category_not_allowed', 'message' => "Your ride policy does not allow the {$trip->category} category. Allowed: ".implode(', ', $policy->allowed_categories).'.'];
            }

            $local = BusinessClock::local($trip->at);

            if (is_array($policy->allowed_days) && $policy->allowed_days !== [] && ! in_array((int) $local->isoWeekday(), array_map('intval', $policy->allowed_days), true)) {
                $days = implode(', ', array_map(fn ($d) => self::DAYS[(int) $d] ?? $d, $policy->allowed_days));
                $refusals[] = ['code' => 'day_not_allowed', 'message' => "Your ride policy allows business rides only on {$days}."];
            }

            if ($policy->hasHours() && ! $this->withinHours($local->hour * 60 + $local->minute, $policy->hours_start, $policy->hours_end)) {
                $refusals[] = ['code' => 'time_not_allowed', 'message' => 'Your ride policy allows business rides only between '.$policy::minutesLabel($policy->hours_start).' and '.$policy::minutesLabel($policy->hours_end).'.'];
            }

            if (is_array($policy->service_area_ids) && $policy->service_area_ids !== []) {
                $allowed = array_map('intval', $policy->service_area_ids);

                foreach (['pickup' => [$trip->pickupLat, $trip->pickupLng], 'drop-off' => [$trip->dropoffLat, $trip->dropoffLng]] as $label => [$lat, $lng]) {
                    $area = $this->zones->serviceAreaFor(new LatLng($lat, $lng));

                    if ($area === null || ! in_array((int) $area->id, $allowed, true)) {
                        $refusals[] = ['code' => 'area_not_allowed', 'message' => "The {$label} is outside the areas your ride policy allows."];
                    }
                }
            }

            if ($policy->max_fare_minor !== null && $trip->fareMinor > $policy->max_fare_minor) {
                $refusals[] = ['code' => 'fare_above_maximum', 'message' => 'This fare ('.$money($trip->fareMinor).') is above the most your ride policy allows per ride ('.$money($policy->max_fare_minor).').'];
            }

            if ($policy->purpose_required && blank($trip->purpose)) {
                $refusals[] = ['code' => 'purpose_required', 'message' => 'Your ride policy asks for the purpose of each ride. Add a purpose and try again.'];
            }
        }

        foreach ($this->limits->preview($trip) as $reason) {
            $refusals[] = $reason;
        }

        if ($refusals !== []) {
            return new PolicyDecision($refusals);
        }

        $approvalReasons = [];

        if ($policy?->approval_above_minor !== null && $trip->fareMinor > $policy->approval_above_minor && ! $trip->booker->isApprover()) {
            $approvalReasons[] = ['code' => 'approval_required', 'message' => 'Rides above '.$money($policy->approval_above_minor).' need an approver\'s yes first. This fare is '.$money($trip->fareMinor).'.'];
        }

        if ($approvalReasons === []) {
            return new PolicyDecision([]);
        }

        if ($trip->approval !== null) {
            $mismatch = $this->approvals->mismatch($trip->approval, $trip);

            return $mismatch === null ? new PolicyDecision([], [], $trip->approval) : new PolicyDecision([['code' => 'approval_not_matching', 'message' => $mismatch]]);
        }

        // No approval named: an approved, unused request of this booker that matches the ride covers it.
        $matching = $this->approvals->findMatching($trip);

        if ($matching !== null) {
            return new PolicyDecision([], [], $matching);
        }

        return new PolicyDecision([], $approvalReasons);
    }

    /**
     * @return list<array{code: string, message: string}>
     */
    public function accountRefusals(BusinessAccount $account): array
    {
        return match ($account->status) {
            BusinessAccount::ACTIVE => [],
            BusinessAccount::PENDING => [['code' => 'account_pending', 'message' => 'This business account is waiting for review by Dot.Ehail. Rides cannot be billed to it yet.']],
            BusinessAccount::SUSPENDED => [['code' => 'account_suspended', 'message' => match ($account->status_reason) {
                'overdue' => 'This business account is paused for new rides because a statement is overdue. An account admin can settle it.',
                'card_failed' => 'This business account is paused for new rides because the company card was declined. An account admin can update it.',
                default => 'This business account is paused for new rides. An account admin can contact Dot.Ehail.',
            }]],
            default => [['code' => 'account_closed', 'message' => 'This business account is closed.']],
        };
    }

    private function withinHours(int $minute, int $start, int $end): bool
    {
        if ($start === $end) {
            return true;
        }

        // An overnight window (22:00 to 06:00) wraps past midnight.
        return $start < $end ? ($minute >= $start && $minute < $end) : ($minute >= $start || $minute < $end);
    }

    /**
     * The approval a booking may use: approved, unused, not past use_by, the booker's own.
     */
    public function usableApproval(?int $approvalId, BusinessMember $booker): ?BusinessApproval
    {
        if ($approvalId === null) {
            return null;
        }

        return BusinessApproval::query()->whereKey($approvalId)
            ->where('business_account_id', $booker->business_account_id)
            ->where('requester_member_id', $booker->id)
            ->first();
    }
}
