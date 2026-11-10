<?php

namespace App\Http\Controllers\Ops;

use App\Domain\Ops\Access\OpsAccess;
use App\Domain\Ops\Access\OpsPermission;
use App\Domain\Ops\Audit\OpsAudit;
use App\Domain\Ops\Live\RideDesk;
use App\Domain\Ops\Live\StuckRides;
use App\Domain\Payments\Models\Payment;
use App\Domain\Pricing\Finalisation\FareAdjuster;
use App\Domain\Pricing\Models\RideFare;
use App\Domain\Safety\Models\SafetyIncident;
use App\Domain\Support\Models\SupportCase;
use App\Enums\RideActor;
use App\Enums\RideStatus;
use App\Http\Controllers\Controller;
use App\Models\Ride;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Rides: the desk (queues and payment attention, moved from the pre-R-46 operator page), a searchable list of every
 * ride, and one ride's full record with its timeline, fare snapshot, payments, incidents, cases and the
 * interventions the operator's role allows (each posted to the dispatch or pricing API).
 */
class RideController extends Controller
{
    public function index(Request $request, RideDesk $desk, StuckRides $stuck): View
    {
        $validated = $request->validate([
            'view' => ['nullable', Rule::in(['desk', 'all'])],
            'status' => ['nullable', Rule::in(array_map(fn (RideStatus $s) => $s->value, RideStatus::cases()))],
            'q' => ['nullable', 'string', 'max:80'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $view = $validated['view'] ?? 'desk';

        if ($view === 'desk') {
            return view('ops.rides.desk', $desk->summary() + ['stuck' => $stuck->find(), 'stuckRides' => $stuck]);
        }

        $q = trim((string) ($validated['q'] ?? ''));
        $rides = Ride::query()
            ->with(['passenger:id,name', 'driver:id,name'])
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($validated['from'] ?? null, fn ($query, $from) => $query->where('created_at', '>=', $from.' 00:00:00'))
            ->when($validated['to'] ?? null, fn ($query, $to) => $query->where('created_at', '<=', $to.' 23:59:59'))
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($inner) use ($q) {
                    if (ctype_digit($q)) {
                        $inner->orWhere('id', (int) $q);
                    }
                    $inner->orWhereHas('passenger', fn ($u) => $u->where('name', 'like', '%'.$q.'%'))
                        ->orWhereHas('driver', fn ($u) => $u->where('name', 'like', '%'.$q.'%'))
                        ->orWhere('pickup_address', 'like', '%'.$q.'%')
                        ->orWhere('dropoff_address', 'like', '%'.$q.'%');
                });
            })
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return view('ops.rides.index', ['rides' => $rides, 'filters' => $validated]);
    }

    public function show(Request $request, int $ride, OpsAccess $access, OpsAudit $audit, StuckRides $stuck): View
    {
        $ride = Ride::query()->with(['passenger:id,name,email', 'driver:id,name', 'vehicle', 'events', 'offers.driver:id,name', 'ratings'])->findOrFail($ride);
        $user = $request->user();
        $audit->viewed('ride', $user, $ride);

        $status = RideStatus::fromStored($ride->status);
        $operatorEdges = collect($status->allowedTransitions())
            ->filter(fn (array $actors) => in_array(RideActor::Operator, $actors, true))
            ->keys()
            ->all();

        $fare = RideFare::query()->where('ride_id', $ride->id)->first();

        return view('ops.rides.show', [
            'ride' => $ride,
            'status' => $status,
            'fare' => $fare,
            'stuck' => $stuck->isStuck($ride),
            'stateSince' => $stuck->enteredStateAt($ride),
            'canIntervene' => $access->can($user, OpsPermission::RIDES_INTERVENE),
            'canAdjustFare' => $access->can($user, OpsPermission::RIDES_FARE_ADJUST) && $fare?->finalised_at !== null,
            'operatorEdges' => $operatorEdges,
            'adjustReasons' => FareAdjuster::REASON_CODES,
            'payments' => Payment::query()->where('ride_id', $ride->id)->with('refunds')->orderBy('id')->get(),
            'showPaymentDetail' => $access->can($user, OpsPermission::FINANCE_VIEW),
            'incidents' => $access->can($user, OpsPermission::SAFETY_VIEW) ? SafetyIncident::query()->where('ride_id', $ride->id)->orderByDesc('id')->get() : null,
            'cases' => $access->can($user, OpsPermission::SUPPORT_VIEW) ? SupportCase::query()->where('ride_id', $ride->id)->orderByDesc('id')->get() : null,
            'positionsAllowed' => $access->can($user, OpsPermission::DRIVERS_POSITIONS),
        ]);
    }
}
