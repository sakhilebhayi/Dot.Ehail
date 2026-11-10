<?php

namespace App\Http\Controllers\Passenger;

use App\Domain\Safety\IncidentNotifier;
use App\Domain\Safety\IncidentService;
use App\Domain\Safety\Models\SafetyIncident;
use App\Domain\Safety\Models\UserReport;
use App\Http\Controllers\Controller;
use App\Models\Ride;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The safety centre tab, and the "what we did" screen after an SOS or "I need help". Everything shown here is
 * read from the safety domain (its notification rows, its emergency numbers); this controller decides nothing.
 */
class SafetyCentreController extends Controller
{
    public function index(Request $request, IncidentNotifier $notifier): View
    {
        $user = $request->user();

        return view('passenger.safety', [
            'activeRide' => Ride::query()->where('active_passenger_id', $user->id)->first(),
            'recentRides' => Ride::query()->where('passenger_id', $user->id)->whereNotNull('driver_id')->orderByDesc('id')->limit(10)->get(),
            'incidents' => SafetyIncident::query()->where('reporter_id', $user->id)->orderByDesc('id')->limit(10)->get(),
            'emergencyNumbers' => $notifier->emergencyNumbers(),
            'reportCategories' => UserReport::CATEGORIES,
        ]);
    }

    public function incident(Request $request, SafetyIncident $incident, IncidentService $incidents): View
    {
        abort_unless($incident->reporter_id === $request->user()->id, 404);

        return view('passenger.incident', [
            'incident' => $incident,
            'whatWeDid' => $incidents->whatWeDid($incident),
        ]);
    }
}
