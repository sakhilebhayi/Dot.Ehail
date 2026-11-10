<?php

namespace App\Domain\Business;

use App\Domain\Business\Data\PolicyDecision;
use App\Domain\Business\Data\TripRequest;
use App\Domain\Business\Models\BusinessAccount;
use App\Domain\Business\Models\BusinessApproval;
use App\Domain\Business\Models\BusinessMember;
use App\Domain\Business\Models\BusinessTrip;
use App\Domain\Dispatch\Contracts\FareQuoteGateway;
use App\Domain\Dispatch\Data\LockedQuote;
use App\Models\Ride;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Rides booked on a business account.
 *
 *  - profilesFor(): the business profiles a person may ride on (GET /business/profiles).
 *  - prepare(): check a quoted ride against the policy before requesting it, and keep the business
 *    details (purpose, guest, traveller, approval) against the quote id (POST /business/trips/prepare).
 *  - attach(): inside the ride-request transaction (dispatch -> PaymentMethodResolver::attach), re-check
 *    everything server side, reserve the fare against the limits and link the ride. Any refusal rolls
 *    the ride back.
 *  - rideEnded(): a ride that ended without a charge gives its reservation back.
 */
class BusinessBookings
{
    public function __construct(
        private PolicyEvaluator $policies,
        private SpendLimits $limits,
        private Approvals $approvals,
        private BusinessAccounts $accounts,
    ) {}

    /**
     * @return Collection<int, BusinessMember>
     */
    public function profilesFor(User $user): Collection
    {
        return BusinessMember::query()->with(['account', 'department', 'policy'])
            ->where('user_id', $user->id)->where('status', BusinessMember::ACTIVE)
            ->whereHas('account', fn ($q) => $q->whereIn('status', [BusinessAccount::PENDING, BusinessAccount::ACTIVE, BusinessAccount::SUSPENDED]))
            ->get()
            ->filter(fn (BusinessMember $m) => $m->mayRide())
            ->each(fn (BusinessMember $m) => $this->accounts->ensureProfile($m))
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    public function profileArray(BusinessMember $member): array
    {
        $member->loadMissing(['account', 'department']);
        $account = $member->account;
        $policy = $member->effectivePolicy();
        $month = BusinessClock::monthKey();
        $blocked = $this->policies->accountRefusals($account);
        $canBookForOthers = Abilities::allows($member, 'book_for_others');

        return [
            'id' => (int) $member->payment_method_id,
            'payment_method' => 'business_account',
            'payment_ref' => (string) $member->payment_method_id,
            'member_id' => $member->id,
            'account' => [
                'id' => $account->id,
                'name' => $account->displayName(),
                'status' => $account->status,
                'currency' => $account->currency,
                'bookable' => $blocked === [],
                'blocked_reason' => $blocked[0]['message'] ?? null,
            ],
            'role' => $member->role,
            'role_label' => $member->roleLabel(),
            'department' => $member->department ? ['id' => $member->department->id, 'name' => $member->department->name, 'cost_centre' => $member->department->cost_centre] : null,
            'policy' => $policy?->toPublicArray(),
            'allowance' => $member->monthly_allowance_minor === null ? null : [
                'limit_minor' => $member->monthly_allowance_minor,
                'used_minor' => $this->limits->used('member', $member->id, $month),
                'left_minor' => max(0, $member->monthly_allowance_minor - $this->limits->used('member', $member->id, $month)),
                'month' => $month,
            ],
            'needs_purpose' => (bool) ($policy?->purpose_required),
            'approval_above_minor' => $member->isApprover() ? null : $policy?->approval_above_minor,
            'can_book_for_others' => $canBookForOthers,
            'can_book_for_guests' => $member->isApprover() || (bool) ($policy?->guest_bookings),
            'travellers' => $canBookForOthers ? BusinessMember::query()->with(['department', 'user'])
                ->where('business_account_id', $account->id)->where('status', BusinessMember::ACTIVE)
                ->whereIn('role', [BusinessMember::ADMIN, BusinessMember::MANAGER, BusinessMember::TRAVELLER])
                ->orderBy('name')->get()
                ->map(fn (BusinessMember $m) => ['member_id' => $m->id, 'name' => $m->displayName(), 'department' => $m->department?->name])->values()->all() : [],
            'approvals' => BusinessApproval::where('requester_member_id', $member->id)
                ->whereIn('status', [BusinessApproval::PENDING, BusinessApproval::APPROVED])
                ->where(fn ($q) => $q->where('use_by', '>', now())->orWhere('decide_by', '>', now()))
                ->latest('id')->limit(10)->get()->map->toPublicArray()->values()->all(),
            'request_fields' => ['business.purpose', 'business.traveller_member_id', 'business.guest_name', 'business.guest_phone', 'business.approval_id'],
        ];
    }

    /**
     * The booker's active membership behind a business payment method id.
     *
     * @throws BusinessRefusal
     */
    public function memberForProfile(User $user, int|string|null $profileId): BusinessMember
    {
        $member = $profileId === null || ! ctype_digit((string) $profileId) ? null : BusinessMember::query()->with('account')
            ->where('payment_method_id', (int) $profileId)->where('user_id', $user->id)->first();

        if ($member === null || ! $member->isActive()) {
            throw BusinessRefusal::one('profile_unknown', 'That business profile is not yours or is no longer active.', 404);
        }

        return $member;
    }

    /**
     * Check a quoted ride and keep its business details for the request.
     *
     * @param  array<string, mixed>  $input  quote_id, purpose, traveller_member_id, guest_name, guest_phone, approval_id, scheduled_for
     * @return array{decision: PolicyDecision, trip: TripRequest, prepared: ?BusinessTrip}
     *
     * @throws BusinessRefusal
     */
    public function prepare(User $user, BusinessMember $booker, array $input): array
    {
        $quote = $this->quoteFor($user, (string) $input['quote_id']);
        $trip = $this->tripFromQuote($booker, $quote, $input);
        $decision = $this->policies->evaluate($trip);

        $prepared = BusinessTrip::query()->where('fare_quote_id', $quote->id)->first();

        if ($prepared !== null && ($prepared->status !== BusinessTrip::PREPARED || $prepared->booker_member_id !== $booker->id)) {
            throw BusinessRefusal::one('quote_used', 'That fare quote was already used. Get a new quote.', 409);
        }

        $prepared = BusinessTrip::updateOrCreate(['fare_quote_id' => $quote->id], $this->tripAttributes($trip) + ['status' => BusinessTrip::PREPARED]);

        return ['decision' => $decision, 'trip' => $trip, 'prepared' => $prepared];
    }

    /**
     * Ask for approval of a quoted ride that needs it.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws BusinessRefusal
     */
    public function requestApproval(User $user, BusinessMember $booker, array $input): BusinessApproval
    {
        $quote = $this->quoteFor($user, (string) $input['quote_id']);
        $trip = $this->tripFromQuote($booker, $quote, array_merge($input, ['approval_id' => null]));
        $decision = $this->policies->evaluate($trip);

        if ($decision->refused()) {
            throw BusinessRefusal::because($decision->refusals);
        }

        if (! $decision->needsApproval()) {
            throw BusinessRefusal::one('approval_not_needed', 'This ride does not need approval. You can book it now.', 409);
        }

        return $this->approvals->request($trip, $decision->approvalReasons);
    }

    /**
     * Called by the ride-request transaction. Re-checks everything; reserves the fare; links the ride.
     *
     * @param  array<string, mixed>  $input  business details sent with the request (override the prepared ones)
     *
     * @throws BusinessRefusal
     */
    public function attach(Ride $ride, User $passenger, int|string|null $profileId, array $input = []): BusinessTrip
    {
        $booker = $this->memberForProfile($passenger, $profileId);
        $prepared = $ride->fare_quote_id ? BusinessTrip::where('fare_quote_id', $ride->fare_quote_id)->first() : null;

        if ($prepared !== null && ($prepared->status !== BusinessTrip::PREPARED || $prepared->booker_member_id !== $booker->id)) {
            $prepared = null;
        }

        $details = array_merge($prepared !== null ? [
            'purpose' => $prepared->purpose,
            'traveller_member_id' => $prepared->traveller_member_id,
            'guest_name' => $prepared->is_guest ? $prepared->rider_name : null,
            'guest_phone' => $prepared->is_guest ? $prepared->rider_phone : null,
            'rider_phone' => $prepared->is_guest ? null : $prepared->rider_phone,
            'approval_id' => $prepared->approval_id,
        ] : [], array_filter($input, fn ($v) => $v !== null && $v !== ''));

        $at = $ride->scheduled_for ? Carbon::parse($ride->scheduled_for) : now();
        $trip = $this->buildTrip($booker, $details, (string) ($ride->category_code ?? $ride->vehicle_type ?? ''), (float) $ride->pickup_lat, (float) $ride->pickup_lng,
            (float) $ride->dropoff_lat, (float) $ride->dropoff_lng, (int) $ride->quoted_total_minor, (string) ($ride->currency ?? 'ZAR'), $at, $ride->pickup_address, $ride->dropoff_address);

        $decision = $this->policies->evaluate($trip);

        if ($decision->refused()) {
            throw BusinessRefusal::because($decision->refusals);
        }

        if ($decision->needsApproval()) {
            throw new BusinessRefusal('approval_required', $decision->approvalReasons[0]['message'].' Ask for approval from your business profile, then book again.', $decision->approvalReasons);
        }

        $attributes = $this->tripAttributes($trip) + [
            'ride_id' => $ride->id,
            'fare_quote_id' => $ride->fare_quote_id,
            'status' => BusinessTrip::BOOKED,
            'booked_at' => now(),
        ];

        if ($prepared !== null) {
            $prepared->forceFill($attributes)->save();
            $businessTrip = $prepared;
        } else {
            $businessTrip = BusinessTrip::create($attributes);
        }

        $this->limits->reserve($businessTrip, $trip->account, $trip->traveller, $trip->fareMinor);

        if ($decision->approval !== null) {
            $this->approvals->markUsed($decision->approval, $ride->id);
            $businessTrip->forceFill(['approval_id' => $decision->approval->id])->save();
        }

        return $businessTrip;
    }

    /**
     * The ride ended (cancelled, expired, failed, no-show, or completed with nothing collected).
     * A trip with no charge gives its reservation back; a charged one is already settled.
     */
    public function rideEnded(int $rideId): void
    {
        $trip = BusinessTrip::where('ride_id', $rideId)->first();

        if ($trip === null || $trip->status !== BusinessTrip::BOOKED) {
            return;
        }

        $this->limits->release($trip);
        BusinessTrip::whereKey($trip->id)->where('status', BusinessTrip::BOOKED)->update(['status' => BusinessTrip::RELEASED, 'released_at' => now(), 'updated_at' => now()]);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function tripFromQuote(BusinessMember $booker, LockedQuote $quote, array $input): TripRequest
    {
        $at = ! empty($input['scheduled_for']) ? Carbon::parse($input['scheduled_for']) : now();

        return $this->buildTrip($booker, $input, $quote->category, $quote->pickupLat, $quote->pickupLng, $quote->dropoffLat, $quote->dropoffLng,
            $quote->totalMinor, $quote->currency, $at, $input['pickup_address'] ?? $quote->pickupAddress, $input['dropoff_address'] ?? $quote->dropoffAddress);
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws BusinessRefusal
     */
    private function buildTrip(BusinessMember $booker, array $input, string $category, float $pLat, float $pLng, float $dLat, float $dLng, int $fare, string $currency, CarbonInterface $at, ?string $pickupAddress, ?string $dropoffAddress): TripRequest
    {
        $account = $booker->account;
        $isGuest = filled($input['guest_name'] ?? null) || filled($input['guest_phone'] ?? null);
        $traveller = $booker;

        if (! $isGuest && ! empty($input['traveller_member_id']) && (int) $input['traveller_member_id'] !== $booker->id) {
            $traveller = BusinessMember::query()->with(['department', 'policy'])->where('business_account_id', $account->id)->find((int) $input['traveller_member_id']);

            if ($traveller === null) {
                throw BusinessRefusal::one('traveller_unknown', 'Choose a traveller from this business account.', 422);
            }
        }

        $approval = $this->policies->usableApproval(isset($input['approval_id']) ? (int) $input['approval_id'] : null, $booker);

        return new TripRequest(
            account: $account,
            booker: $booker,
            traveller: $traveller,
            policy: $traveller->effectivePolicy(),
            department: $traveller->department,
            category: $category,
            pickupLat: $pLat, pickupLng: $pLng, dropoffLat: $dLat, dropoffLng: $dLng,
            fareMinor: $fare,
            currency: $currency,
            at: $at,
            purpose: filled($input['purpose'] ?? null) ? mb_substr(trim((string) $input['purpose']), 0, 200) : null,
            isGuest: $isGuest,
            guestName: $isGuest ? mb_substr(trim((string) ($input['guest_name'] ?? '')), 0, 120) : null,
            guestPhone: $isGuest ? trim((string) ($input['guest_phone'] ?? '')) : (filled($input['rider_phone'] ?? null) ? trim((string) $input['rider_phone']) : null),
            approval: $approval,
            pickupAddress: $pickupAddress,
            dropoffAddress: $dropoffAddress,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function tripAttributes(TripRequest $trip): array
    {
        return [
            'business_account_id' => $trip->account->id,
            'booker_member_id' => $trip->booker->id,
            'traveller_member_id' => $trip->isGuest ? null : $trip->traveller->id,
            'department_id' => $trip->department?->id,
            'policy_id' => $trip->policy?->id,
            'approval_id' => $trip->approval?->id,
            'purpose' => $trip->purpose,
            'rider_name' => $trip->isGuest ? $trip->guestName : ($trip->onBehalf() ? $trip->traveller->displayName() : null),
            'rider_phone' => $trip->guestPhone,
            'is_guest' => $trip->isGuest,
            'category_code' => $trip->category,
            'cost_centre' => $trip->department?->cost_centre,
            'department_name' => $trip->department?->name,
            'estimate_minor' => $trip->fareMinor,
            'currency' => $trip->currency,
            'spend_month' => BusinessClock::monthKey($trip->at),
            'policy_snapshot' => $trip->policy?->toPublicArray(),
        ];
    }

    /**
     * @throws BusinessRefusal
     */
    private function quoteFor(User $user, string $quoteId): LockedQuote
    {
        $quotes = app(FareQuoteGateway::class);

        if (! $quotes->available()) {
            throw BusinessRefusal::one('pricing_unavailable', 'Fares are not available right now, so business rides cannot be checked. Please try again later.', 503);
        }

        $quote = $quotes->findForPassenger($quoteId, $user);

        if ($quote === null) {
            throw BusinessRefusal::one('quote_invalid', 'That fare quote has expired or is not yours. Get a new quote and try again.', 422);
        }

        return $quote;
    }
}
