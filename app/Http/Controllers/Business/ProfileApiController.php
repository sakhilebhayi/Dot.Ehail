<?php

namespace App\Http\Controllers\Business;

use App\Domain\Business\BusinessBookings;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * JSON for the passenger app and the portal's booking page (docs/R46_BUSINESS.md section 5).
 *
 *  GET  /business/profiles          the business profiles this person may ride on (empty list = none)
 *  POST /business/trips/prepare     check a quoted ride: {allowed, needs_approval, reasons[]}; keeps the details
 *  POST /business/approvals         ask an approver for a ride that needs it
 * Refusals: {error: "business_<code>", message, reasons: [{code, message}]}.
 */
class ProfileApiController extends Controller
{
    public function index(Request $request, BusinessBookings $bookings): JsonResponse
    {
        return response()->json([
            'profiles' => $bookings->profilesFor($request->user())->map(fn ($m) => $bookings->profileArray($m))->values(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function prepare(Request $request, BusinessBookings $bookings): JsonResponse
    {
        $data = $this->validated($request);
        $booker = $bookings->memberForProfile($request->user(), $data['profile_id']);
        $result = $bookings->prepare($request->user(), $booker, $data);

        return response()->json($result['decision']->toArray() + [
            'profile_id' => (int) $data['profile_id'],
            'quote_id' => $data['quote_id'],
            'request' => [
                'payment_method' => 'business_account',
                'payment_ref' => (string) $data['profile_id'],
            ],
        ]);
    }

    public function requestApproval(Request $request, BusinessBookings $bookings): JsonResponse
    {
        $data = $this->validated($request);
        $booker = $bookings->memberForProfile($request->user(), $data['profile_id']);
        $approval = $bookings->requestApproval($request->user(), $booker, $data);

        return response()->json(['approval' => $approval->toPublicArray()], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'profile_id' => ['required', 'integer'],
            'quote_id' => ['required', 'string', 'max:64'],
            'purpose' => ['nullable', 'string', 'max:200'],
            'traveller_member_id' => ['nullable', 'integer'],
            'guest_name' => ['nullable', 'string', 'max:120'],
            'guest_phone' => ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9 ()-]{7,30}$/'],
            'rider_phone' => ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9 ()-]{7,30}$/'],
            'approval_id' => ['nullable', 'integer'],
            'scheduled_for' => ['nullable', 'date'],
            'pickup_address' => ['nullable', 'string', 'max:255'],
            'dropoff_address' => ['nullable', 'string', 'max:255'],
        ], ['guest_phone.regex' => 'Enter a phone number the driver can call, for example +27 82 123 4567.', 'rider_phone.regex' => 'Enter a phone number the driver can call.']);
    }
}
