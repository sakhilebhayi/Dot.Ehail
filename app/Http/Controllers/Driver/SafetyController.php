<?php

namespace App\Http\Controllers\Driver;

use App\Domain\Safety\Http\IncidentController;
use App\Domain\Safety\Http\RatingController;
use App\Domain\Support\Http\SupportCaseController;
use App\Domain\Support\Models\SupportCase;
use App\Domain\Support\SupportCategory;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Driver\Support\DriverState;
use App\Models\Ride;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Help and safety for drivers: SOS on the current trip, emergency numbers, the driver's incidents, support cases,
 * document expiry alerts and rating appeals. Reads the safety and support domains' own answers (their JSON
 * controllers), so this page shows exactly what their APIs would.
 */
class SafetyController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $state = DriverState::for($user);
        $documents = $state->profile() ? $state->documents() : null;
        $alerts = $documents === null ? [] : collect([$documents['driver'], ...$documents['vehicles']])
            ->flatMap(fn (array $s) => collect($s['items'])->map(fn ($i) => $i + ['vehicle' => $s['vehicle'] ?? null]))
            ->filter(fn (array $i) => ($i['expiring_soon'] ?? false) || in_array($i['state'], ['expired', 'rejected', 'resubmission_requested', 'too_old'], true))
            ->values()->all();

        return view('driver.safety', [
            'activeRide' => Ride::query()->where('active_driver_id', $user->id)->first(['id', 'status', 'pickup_address', 'dropoff_address']),
            'incidents' => app(IncidentController::class)->index($request)->getData(true)['data'] ?? [],
            'cases' => app(SupportCaseController::class)->index($request)->getData(true)['data'] ?? [],
            'ratings' => $state->profile() ? app(RatingController::class)->received($request)->getData(true) : null,
            'alerts' => $alerts,
            'categories' => array_map(fn (SupportCategory $c) => ['value' => $c->value, 'label' => $c->label()], array_values(array_filter(SupportCategory::cases(), fn (SupportCategory $c) => $c !== SupportCategory::DriverConduct))),
            'recentRides' => Ride::query()->where('driver_id', $user->id)->orderByDesc('id')->limit(10)->get(['id', 'pickup_address', 'dropoff_address', 'created_at']),
        ]);
    }

    public function supportCase(Request $request, SupportCase $case): View
    {
        $data = app(SupportCaseController::class)->show($request, $case)->getData(true);

        return view('driver.support-case', ['case' => $data['data'], 'messages' => $data['messages']]);
    }
}
