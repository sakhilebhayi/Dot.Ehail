<?php

namespace App\Domain\Safety\Http;

use App\Domain\Safety\IncidentNotifier;
use App\Domain\Safety\Models\SafetyCheckIn;
use App\Domain\Safety\Models\SafetyIncident;
use App\Domain\Safety\Models\TripShare;
use App\Domain\Safety\Models\TrustedContact;
use App\Domain\Safety\Models\UserReport;
use App\Domain\Safety\RideSafety;
use App\Domain\Safety\Seams\RidePinSource;
use App\Domain\Safety\TripShareService;
use App\Domain\Safety\TrustSignals;
use App\Domain\Safety\UserReportService;
use App\Domain\Verification\Files\PrivateFileResponse;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Ride;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-ride safety API for the wave-2 passenger and driver apps: the safety card (who you are
 * riding with, emergency numbers, open incident, pending check-ins, share links), the driver's
 * approved photo, trip sharing, and private reports about the other party.
 */
class RideSafetyController extends Controller
{
    public function __construct(
        private readonly TrustSignals $trust,
        private readonly TripShareService $shares,
        private readonly IncidentNotifier $notifier,
    ) {}

    public function show(Request $request, Ride $ride): JsonResponse
    {
        $user = $request->user();
        $role = RideSafety::roleOf($ride, $user);
        abort_if($role === null, 404);

        $active = RideSafety::isActive($ride);

        return response()->json([
            'ride' => ['id' => $ride->id, 'status' => $ride->status, 'active' => $active, 'role' => $role],
            // During an active ride only: who is in the car with you.
            'driver' => $role === 'passenger' && $active ? $this->trust->driverCardFor($ride) : null,
            'passenger' => $role === 'driver' && $active ? $this->trust->passengerCardFor($ride) : null,
            'pin' => $role === 'passenger' && $active ? app(RidePinSource::class)->forPassenger($ride) : null,
            'emergency_numbers' => $this->notifier->emergencyNumbers(),
            'emergency_note' => 'Dot.Ehail does not dispatch emergency services. The SOS button alerts our operations team and your confirmed trusted contacts.',
            'open_incident' => SafetyIncident::query()
                ->where('ride_id', $ride->id)->where('reporter_id', $user->id)
                ->whereIn('status', ['open', 'acknowledged', 'escalated'])
                ->latest('id')->first(['id', 'reference', 'kind', 'status']),
            'check_ins' => SafetyCheckIn::query()->where('ride_id', $ride->id)->where('user_id', $user->id)->where('status', 'pending')->get()->map->toPublicArray()->values(),
            'shares' => TripShare::query()->where('ride_id', $ride->id)->where('created_by', $user->id)->orderByDesc('id')->get()
                ->map(fn (TripShare $s) => $this->shareArray($s))->values(),
            'trusted_contacts_confirmed' => TrustedContact::query()->where('user_id', $user->id)->usable()->count(),
        ]);
    }

    public function driverPhoto(Request $request, Ride $ride): Response
    {
        abort_unless(RideSafety::roleOf($ride, $request->user()) === 'passenger' && RideSafety::isActive($ride), 404);

        $photo = $this->trust->driverPhotoPath($ride);
        abort_if($photo === null, 404);

        AuditLog::record('safety.driver_photo_viewed', $request->user(), $ride, []);

        return PrivateFileResponse::make($photo['disk'], $photo['path'], 'driver.jpg', $photo['mime']);
    }

    public function storeShare(Request $request, Ride $ride): JsonResponse
    {
        abort_if(RideSafety::roleOf($ride, $request->user()) === null, 404);

        $validated = $request->validate(['trusted_contact_id' => ['nullable', 'integer']]);
        $contact = isset($validated['trusted_contact_id'])
            ? TrustedContact::query()->where('user_id', $request->user()->id)->find($validated['trusted_contact_id'])
            : null;

        if (isset($validated['trusted_contact_id']) && $contact === null) {
            abort(422, 'Choose one of your confirmed trusted contacts.');
        }

        $result = $this->shares->create($ride, $request->user(), $contact);

        return response()->json([
            'data' => $this->shareArray($result['share']),
            // The only time the link is shown: only its hash is stored.
            'url' => $result['url'],
            'delivery' => $result['delivery'],
        ], 201);
    }

    public function revokeShare(Request $request, TripShare $share): JsonResponse
    {
        abort_unless($share->created_by === $request->user()->id, 404);

        return response()->json(['data' => $this->shareArray($this->shares->revoke($share))]);
    }

    public function report(Request $request, Ride $ride, UserReportService $reports): JsonResponse
    {
        abort_if(RideSafety::roleOf($ride, $request->user()) === null, 404);

        $validated = $request->validate([
            'category' => ['required', Rule::in(UserReport::CATEGORIES)],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $report = $reports->report($ride, $request->user(), $validated['category'], $validated['description'] ?? null);

        return response()->json([
            'data' => ['id' => $report->id, 'category' => $report->category, 'status' => $report->status],
            'message' => 'Thank you. This report is private: the other person is not told who reported them. Operations will review it.',
        ], 201);
    }

    /** @return array<string, mixed> */
    private function shareArray(TripShare $share): array
    {
        $share->loadMissing('ride');
        $expires = $this->shares->effectiveExpiry($share);

        return [
            'id' => $share->id,
            'state' => $share->revoked_at ? 'revoked' : ($expires->isPast() ? 'expired' : 'active'),
            'expires_at' => $expires->toIso8601String(),
            'trusted_contact_id' => $share->trusted_contact_id,
            'view_count' => $share->view_count,
            'last_viewed_at' => $share->last_viewed_at?->toIso8601String(),
        ];
    }
}
