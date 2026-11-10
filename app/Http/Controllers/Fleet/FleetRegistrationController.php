<?php

namespace App\Http\Controllers\Fleet;

use App\Domain\Fleet\FleetRegistration;
use App\Models\Fleet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Register a fleet: a company with cars and drivers, or an independent driver's "fleet of one". It starts pending
 * until a platform operator approves it.
 */
class FleetRegistrationController extends FleetController
{
    public function create(Request $request): View
    {
        return view('fleet.register', ['fleets' => $this->access->fleetsFor($request->user())]);
    }

    public function store(Request $request, FleetRegistration $registration): RedirectResponse
    {
        $data = $request->validate(self::rules());
        $fleet = $registration->register($request->user(), $data);

        return redirect()->route('fleet.home', ['fleet' => $fleet->id])
            ->with('status', 'Fleet registered. Our operations team reviews it; you can add vehicles in the meantime.');
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(bool $updating = false): array
    {
        return [
            'name' => [$updating ? 'sometimes' : 'required', 'string', 'max:120'],
            'kind' => [$updating ? 'sometimes' : 'required', Rule::in([Fleet::KIND_INDEPENDENT, Fleet::KIND_COMPANY])],
            'trading_name' => ['nullable', 'string', 'max:120'],
            'registration_number' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9\/ -]+$/'],
            'contact_email' => ['nullable', 'email', 'max:190'],
            'contact_phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9 +()-]+$/'],
            'base_city' => ['nullable', 'string', 'max:80'],
        ];
    }
}
