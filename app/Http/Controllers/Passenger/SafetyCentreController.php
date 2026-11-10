<?php

namespace App\Http\Controllers\Passenger;

use App\Domain\Safety\IncidentNotifier;
use App\Domain\Safety\IncidentService;
use App\Domain\Safety\Models\SafetyIncident;
use App\Domain\Safety\Models\UserReport;
use App\Domain\Safety\Sms\SmsGateway;
use App\Models\Vehicle;
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
    public function index(Request $request, IncidentNotifier $notifier, SmsGateway $sms): View
    {
        $user = $request->user();

        return view('passenger.safety', [
            'smsConfigured' => $sms->isConfigured(),
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

        $whatWeDid = $incidents->whatWeDid($incident);

        return view('passenger.incident', [
            'incident' => $incident,
            'whatWeDid' => $whatWeDid,
            'whatsapp' => $this->whatsappMessage($incident, $whatWeDid['incident']['reference'] ?? null),
        ]);
    }

    /**
     * The words for "Also message someone yourself" (WhatsApp, sent by the person, not by us): what happened, the
     * reference, and the car to look for while the ride holds a driver. The trip link is added in the browser only
     * when the person taps the button (a share link is created then, never on page view).
     *
     * @return array{text: string, ride_active: bool}
     */
    private function whatsappMessage(SafetyIncident $incident, ?string $reference): array
    {
        $ride = $incident->ride_id ? Ride::query()->find($incident->ride_id) : null;
        $what = $incident->kind->value === 'sos' ? 'I pressed SOS on my Dot.Ehail trip' : 'I asked Dot.Ehail for help on my trip';
        $parts = [$what.($reference ? ' (reference '.$reference.').' : '.')];

        if ($ride !== null && $ride->driver_id !== null) {
            $vehicle = $ride->vehicle_id ? Vehicle::query()->find($ride->vehicle_id) : null;
            $driver = trim(\Illuminate\Support\Str::before((string) $ride->driver?->name, ' '));
            $car = $vehicle ? trim("{$vehicle->color} {$vehicle->make} {$vehicle->model}").($vehicle->plate_number ? ', '.$vehicle->plate_number : '') : null;
            if ($car || $driver !== '') {
                $parts[] = 'I am in '.($car ? 'a '.$car : 'the car').($driver !== '' ? ' with '.$driver : '').'.';
            }
        }

        $parts[] = 'Please call me now.';

        return [
            'text' => implode(' ', $parts),
            'ride_active' => $ride !== null && $ride->active_passenger_id === $incident->reporter_id,
        ];
    }
}
