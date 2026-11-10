<?php

namespace App\Http\Controllers\Fleet;

use App\Domain\Fleet\FleetRegistration;
use App\Domain\Fleet\Models\FleetMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Fleet settings (owner only): business profile, who is paid the drivers' earnings, and managers with their areas.
 */
class FleetSettingsController extends FleetController
{
    public function edit(Request $request): View
    {
        $fleet = $this->fleet($request, 'settings');

        return view('fleet.settings', [
            'fleet' => $fleet,
            'fleets' => $this->access->fleetsFor($request->user()),
            'managers' => FleetMember::query()->where('fleet_id', $fleet->id)->where('role', FleetMember::ROLE_MANAGER)->active()->with('user:id,name,email')->get(),
            'scopes' => FleetMember::SCOPES,
            'platformPayee' => config('money.payouts.payee_for_fleet_drivers'),
            'canWrite' => $this->access->can($request->user(), $fleet, 'settings', true),
        ]);
    }

    public function update(Request $request, FleetRegistration $registration): RedirectResponse|JsonResponse
    {
        $fleet = $this->fleet($request, 'settings', true);
        $data = $request->validate(FleetRegistrationController::rules(true) + [
            'payee_mode' => ['nullable', Rule::in(['driver', 'fleet_owner'])],
        ]);

        return $this->attempt($request, fn () => $registration->updateProfile($fleet, $data, $request->user()), 'Fleet details saved.');
    }

    public function addManager(Request $request, FleetRegistration $registration): RedirectResponse|JsonResponse
    {
        $fleet = $this->fleet($request, 'members', true);
        $data = $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => [Rule::in(array_keys(FleetMember::SCOPES))],
        ]);

        return $this->attempt($request, fn () => $registration->addManager($fleet, $data['email'], $data['scopes'], $request->user()), 'Manager added.');
    }

    public function updateManager(Request $request, int $member, FleetRegistration $registration): RedirectResponse|JsonResponse
    {
        $fleet = $this->fleet($request, 'members', true);
        $model = FleetMember::query()->where('fleet_id', $fleet->id)->findOrFail($member);
        $data = $request->validate(['scopes' => ['required', 'array', 'min:1'], 'scopes.*' => [Rule::in(array_keys(FleetMember::SCOPES))]]);

        return $this->attempt($request, fn () => $registration->updateManager($fleet, $model, $data['scopes'], $request->user()), 'Manager areas saved.');
    }

    public function revokeManager(Request $request, int $member, FleetRegistration $registration): RedirectResponse|JsonResponse
    {
        $fleet = $this->fleet($request, 'members', true);
        $model = FleetMember::query()->where('fleet_id', $fleet->id)->findOrFail($member);

        return $this->attempt($request, fn () => $registration->revokeManager($fleet, $model, $request->user()), 'Manager removed. They no longer see this fleet.');
    }
}
