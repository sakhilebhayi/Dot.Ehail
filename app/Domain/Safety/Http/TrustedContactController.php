<?php

namespace App\Domain\Safety\Http;

use App\Domain\Safety\Models\TrustedContact;
use App\Domain\Safety\RideSafety;
use App\Domain\Safety\Sms\SmsGateway;
use App\Domain\Safety\TrustedContactService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Trusted contacts: the owner's JSON API (list, add, remove, resend confirmation) and the public
 * pages a contact reaches from an email (confirm, decline, stop alerts). Public pages ask for a
 * button press so that mail scanners following links cannot confirm or opt out on their own.
 */
class TrustedContactController extends Controller
{
    public function __construct(private readonly TrustedContactService $contacts) {}

    public function index(Request $request): JsonResponse
    {
        $contacts = TrustedContact::query()->where('user_id', $request->user()->id)->orderBy('id')->get();

        return response()->json([
            'data' => $contacts->map->toOwnerArray()->values(),
            'max' => (int) config('ehail_safety.trusted_contacts.max', 5),
            'sms_available' => app(SmsGateway::class)->isConfigured(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'phone' => ['nullable', 'string', 'max:30'],
            'relationship' => ['nullable', 'string', 'max:40'],
        ]);

        $result = $this->contacts->add($request->user(), $validated['name'], $validated['email'], $validated['phone'] ?? null, $validated['relationship'] ?? null);

        return response()->json([
            'data' => $result['contact']->toOwnerArray(),
            'confirmation_sent' => $result['confirmation_sent'],
            'message' => $result['confirmation_sent']
                ? 'We emailed them a link. They become a trusted contact once they confirm.'
                : 'Saved, but the confirmation email could not be sent. Try "resend" later.',
        ], 201);
    }

    public function resend(Request $request, TrustedContact $contact): JsonResponse
    {
        abort_unless($contact->user_id === $request->user()->id, 404);

        return response()->json(['confirmation_sent' => $this->contacts->resend($contact)]);
    }

    public function destroy(Request $request, TrustedContact $contact): JsonResponse
    {
        abort_unless($contact->user_id === $request->user()->id, 404);
        $contact->delete();

        return response()->json(['deleted' => true]);
    }

    public function confirmShow(string $token): View
    {
        $contact = $this->contacts->findByToken($token);

        return view('safety.contact-answer', [
            'mode' => 'confirm',
            'valid' => $contact !== null && $contact->opted_out_at === null,
            'done' => $contact?->confirmed_at !== null,
            'ownerFirstName' => RideSafety::firstName($contact?->user?->name, 'Someone'),
            'action' => route('safety.contacts.confirm', $token),
        ]);
    }

    public function confirm(string $token): View
    {
        $contact = $this->contacts->confirm($token);

        return view('safety.contact-answer', [
            'mode' => 'confirm',
            'valid' => $contact !== null,
            'done' => $contact !== null,
            'ownerFirstName' => RideSafety::firstName($contact?->user?->name, 'Someone'),
            'action' => null,
        ]);
    }

    public function optOutShow(string $token): View
    {
        $contact = $this->contacts->findByToken($token);

        return view('safety.contact-answer', [
            'mode' => 'opt_out',
            'valid' => $contact !== null,
            'done' => $contact?->opted_out_at !== null,
            'ownerFirstName' => RideSafety::firstName($contact?->user?->name, 'Someone'),
            'action' => route('safety.contacts.opt-out', $token),
        ]);
    }

    public function optOut(string $token): View
    {
        $contact = $this->contacts->optOut($token);

        return view('safety.contact-answer', [
            'mode' => 'opt_out',
            'valid' => $contact !== null,
            'done' => $contact !== null,
            'ownerFirstName' => RideSafety::firstName($contact?->user?->name, 'Someone'),
            'action' => null,
        ]);
    }

    public function unsubscribeShow(Request $request, TrustedContact $contact): View
    {
        return view('safety.contact-answer', [
            'mode' => 'opt_out',
            'valid' => true,
            'done' => $contact->opted_out_at !== null,
            'ownerFirstName' => RideSafety::firstName($contact->user?->name, 'Someone'),
            'action' => $request->fullUrl(),
        ]);
    }

    public function unsubscribe(TrustedContact $contact): View
    {
        $this->contacts->unsubscribe($contact);

        return view('safety.contact-answer', [
            'mode' => 'opt_out',
            'valid' => true,
            'done' => true,
            'ownerFirstName' => RideSafety::firstName($contact->user?->name, 'Someone'),
            'action' => null,
        ]);
    }
}
