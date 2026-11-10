<?php

namespace App\Http\Controllers\Business;

use App\Domain\Business\Abilities;
use App\Domain\Business\BusinessClock;
use App\Domain\Business\Models\BusinessDepartment;
use App\Domain\Business\Models\BusinessMember;
use App\Domain\Business\Models\BusinessTrip;
use App\Domain\Business\Statements;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Trips billed (or held) on the account, filterable by person, department, date and state. Travellers see
 * only rides they took or booked; admins, travel managers and finance viewers see the whole account.
 */
class TripController extends BusinessController
{
    public function index(Request $request): View
    {
        $member = $this->member($request);
        $filters = $this->filters($request);
        $all = Abilities::allows($member, 'view_all_trips');

        return view('business.trips', [
            'member' => $member,
            'account' => $member->account,
            'can' => $this->can($member),
            'filters' => $filters,
            'seesAll' => $all,
            'trips' => $this->query($member, $filters)->with(['ride', 'traveller.user', 'booker.user', 'charges'])->latest('booked_at')->paginate(25)->withQueryString(),
            'totals' => [
                'charged' => (int) $this->query($member, $filters)->sum('charged_minor'),
                'count' => $this->query($member, $filters)->count(),
            ],
            'people' => $all ? BusinessMember::where('business_account_id', $member->business_account_id)->whereNot('role', BusinessMember::FINANCE)->orderBy('name')->get() : collect(),
            'departments' => $all ? BusinessDepartment::where('business_account_id', $member->business_account_id)->orderBy('name')->get() : collect(),
        ]);
    }

    public function csv(Request $request, Statements $statements): Response
    {
        $member = $this->member($request);
        $trips = $this->query($member, $this->filters($request))->with(['ride', 'traveller.user', 'booker.user', 'charges'])->latest('booked_at')->limit(5000)->get();

        $lines = $trips->flatMap(function (BusinessTrip $trip) {
            $charges = $trip->charges->isEmpty() ? collect([null]) : $trip->charges;

            return $charges->map(fn ($charge) => [
                'date' => ($charge?->recorded_at ?? $trip->booked_at ?? $trip->created_at)->toIso8601String(),
                'reference' => $charge?->reference ?? 'trip-'.$trip->id,
                'kind' => $charge?->kind ?? ($trip->status === BusinessTrip::BOOKED ? 'held' : 'not_billed'),
                'ride_id' => $trip->ride_id,
                'rider' => $trip->riderLabel(),
                'booked_by' => $trip->isOnBehalf() ? $trip->booker?->displayName() : null,
                'department' => $trip->department_name,
                'cost_centre' => $trip->cost_centre,
                'purpose' => $trip->purpose,
                'category' => $trip->category_code,
                'pickup' => $trip->ride?->pickup_address,
                'dropoff' => $trip->ride?->dropoff_address,
                'amount_minor' => (int) ($charge?->amount_minor ?? 0),
                'tax_minor' => (int) ($charge?->tax_minor ?? 0),
            ]);
        })->values()->all();

        return response($statements->csv($lines, $member->account->currency), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="business-trips-'.now()->format('Ymd').'.csv"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * @return array{person: ?int, department: ?int, from: ?string, to: ?string, state: ?string}
     */
    private function filters(Request $request): array
    {
        $date = fn ($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;

        return [
            'person' => ctype_digit((string) $request->query('person')) ? (int) $request->query('person') : null,
            'department' => ctype_digit((string) $request->query('department')) ? (int) $request->query('department') : null,
            'from' => $date($request->query('from')),
            'to' => $date($request->query('to')),
            'state' => in_array($request->query('state'), ['booked', 'charged', 'released'], true) ? $request->query('state') : null,
        ];
    }

    /**
     * @param  array{person: ?int, department: ?int, from: ?string, to: ?string, state: ?string}  $filters
     */
    private function query(BusinessMember $member, array $filters): Builder
    {
        $tz = BusinessClock::timezone();

        return BusinessTrip::query()
            ->where('business_account_id', $member->business_account_id)
            ->whereNotNull('ride_id')
            ->when(! Abilities::allows($member, 'view_all_trips'), fn ($q) => $q->where(fn ($w) => $w->where('traveller_member_id', $member->id)->orWhere('booker_member_id', $member->id)))
            ->when($filters['person'], fn ($q, $id) => $q->where(fn ($w) => $w->where('traveller_member_id', $id)->orWhere(fn ($g) => $g->whereNull('traveller_member_id')->where('booker_member_id', $id))))
            ->when($filters['department'], fn ($q, $id) => $q->where('department_id', $id))
            ->when($filters['from'], fn ($q, $d) => $q->where('booked_at', '>=', CarbonImmutable::parse($d, $tz)->startOfDay()->utc()))
            ->when($filters['to'], fn ($q, $d) => $q->where('booked_at', '<', CarbonImmutable::parse($d, $tz)->addDay()->startOfDay()->utc()))
            ->when($filters['state'], fn ($q, $s) => $q->where('status', $s), fn ($q) => $q->whereIn('status', [BusinessTrip::BOOKED, BusinessTrip::CHARGED, BusinessTrip::RELEASED]));
    }
}
