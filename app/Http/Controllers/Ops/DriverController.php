<?php

namespace App\Http\Controllers\Ops;

use App\Domain\Dispatch\Models\DriverAvailability;
use App\Domain\Earnings\Models\DriverEarning;
use App\Domain\Earnings\Models\Payout;
use App\Domain\Ops\Access\OpsAccess;
use App\Domain\Ops\Access\OpsPermission;
use App\Domain\Ops\Audit\OpsAudit;
use App\Domain\Ops\Drivers\DriverFacts;
use App\Domain\Ops\Drivers\DriverSuspension;
use App\Domain\Ops\Drivers\SuspensionService;
use App\Domain\Verification\Models\VerificationDocument;
use App\Http\Controllers\Controller;
use App\Models\DriverProfile;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Drivers: the list, one driver's record (trips, factual stats, rating band, earnings and payouts for roles that may
 * see money, documents for reviewers), and suspensions with reasons and the appeal path. Applications are decided
 * by the driver's fleet owner (every driver profile belongs to a fleet); operations approves the fleets.
 */
class DriverController extends Controller
{
    public function index(Request $request): View
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'approved', 'suspended', 'rejected'])],
            'state' => ['nullable', Rule::in(['online', 'on_trip', 'paused', 'offline'])],
            'q' => ['nullable', 'string', 'max:80'],
        ]);

        $q = trim((string) ($validated['q'] ?? ''));
        $profiles = DriverProfile::withoutGlobalScope('user')
            ->with(['user:id,name,email', 'fleet:id,name'])
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($q !== '', fn ($query) => $query->whereHas('user', fn ($u) => $u->where('name', 'like', '%'.$q.'%')->orWhere('email', 'like', '%'.$q.'%')))
            ->when($validated['state'] ?? null, function ($query, $state) {
                $ids = DriverAvailability::query()->where('state', $state)->pluck('user_id');

                return $state === 'offline'
                    ? $query->where(fn ($q2) => $q2->whereIn('user_id', $ids)->orWhereNotIn('user_id', DriverAvailability::query()->pluck('user_id')))
                    : $query->whereIn('user_id', $ids);
            })
            ->orderByRaw("case status when 'pending' then 0 when 'suspended' then 1 else 2 end")
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        $ids = $profiles->getCollection()->pluck('user_id');
        $availability = DriverAvailability::query()->whereIn('user_id', $ids)->get()->keyBy('user_id');
        $completed = Ride::query()->whereIn('driver_id', $ids)->where('status', 'completed')->selectRaw('driver_id, count(*) as n')->groupBy('driver_id')->pluck('n', 'driver_id');

        $counts = DriverProfile::withoutGlobalScope('user')->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return view('ops.drivers.index', compact('profiles', 'availability', 'completed', 'counts') + ['filters' => $validated]);
    }

    public function show(Request $request, int $driver, OpsAccess $access, OpsAudit $audit, DriverFacts $facts, SuspensionService $suspensions): View
    {
        $user = User::query()->findOrFail($driver);
        $profile = DriverProfile::withoutGlobalScope('user')->with(['fleet', 'vehicles'])->where('user_id', $user->id)->firstOrFail();
        $viewer = $request->user();
        $audit->viewed('driver', $viewer, $profile);

        $canMoney = $access->can($viewer, OpsPermission::DRIVERS_EARNINGS) || $access->can($viewer, OpsPermission::FINANCE_VIEW);

        $earnings = null;
        $payouts = null;
        if ($canMoney) {
            $earnings = DriverEarning::query()->where('driver_id', $user->id)
                ->selectRaw('status, currency, count(*) as n, sum(net_minor) as net_minor')
                ->groupBy('status', 'currency')->get();
            $payouts = Payout::query()->where('payee_user_id', $user->id)->orderByDesc('id')->limit(10)->get();
        }

        return view('ops.drivers.show', [
            'driver' => $user,
            'profile' => $profile,
            'availability' => DriverAvailability::query()->where('user_id', $user->id)->first(),
            'facts30' => $facts->for($user->id, now()->subDays(30)),
            'factsAll' => $facts->for($user->id, Carbon::create(2000)),
            'trips' => Ride::query()->where('driver_id', $user->id)->with('passenger:id,name')->orderByDesc('id')->limit(15)->get(),
            'suspension' => $suspensions->activeFor($user),
            'history' => DriverSuspension::query()->where('user_id', $user->id)->with(['suspender:id,name', 'lifter:id,name'])->orderByDesc('id')->get(),
            'reasons' => SuspensionService::REASONS,
            'canSuspend' => $access->can($viewer, OpsPermission::DRIVERS_SUSPEND),
            'documents' => $access->can($viewer, OpsPermission::VERIFICATION_REVIEW)
                ? VerificationDocument::query()->where('user_id', $user->id)->orderByDesc('id')->get() : null,
            'earnings' => $earnings,
            'payouts' => $payouts,
        ]);
    }

    public function suspend(Request $request, int $driver, SuspensionService $suspensions): RedirectResponse
    {
        $validated = $request->validate([
            'reason_code' => ['required', Rule::in(array_keys(SuspensionService::REASONS))],
            'note' => ['nullable', 'string', 'max:1000', 'required_if:reason_code,other'],
        ]);

        $suspensions->suspend($request->user(), User::query()->findOrFail($driver), $validated['reason_code'], $validated['note'] ?? null);

        return redirect()->route('ops.drivers.show', $driver)->with('status', 'Driver suspended. They cannot go online until this is lifted.');
    }

    public function lift(Request $request, DriverSuspension $suspension, SuspensionService $suspensions): RedirectResponse
    {
        $validated = $request->validate(['note' => ['required', 'string', 'min:3', 'max:1000']]);
        $suspensions->lift($request->user(), $suspension, $validated['note']);

        return redirect()->route('ops.drivers.show', $suspension->user_id)->with('status', 'Suspension lifted.');
    }

    public function decideAppeal(Request $request, DriverSuspension $suspension, SuspensionService $suspensions): RedirectResponse
    {
        $validated = $request->validate([
            'decision' => ['required', Rule::in(['upheld', 'rejected'])],
            'note' => ['required', 'string', 'min:3', 'max:1000'],
        ]);
        $suspensions->decideAppeal($request->user(), $suspension, $validated['decision'], $validated['note']);

        return redirect()->route('ops.drivers.show', $suspension->user_id)->with('status', $validated['decision'] === 'upheld' ? 'Appeal upheld: suspension lifted.' : 'Appeal rejected: suspension stays.');
    }
}
