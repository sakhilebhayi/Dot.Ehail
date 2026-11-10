<?php

namespace App\Http\Controllers\Driver;

use App\Domain\Payments\Models\DriverMoneySetting;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Driver\Support\DriverState;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Driver account: vehicle, documents, payouts, cash opt-in (money domain setting) and this phone's app settings
 * (navigation app, offer sound, vibration), which are kept on the phone because they belong to the device.
 */
class AccountController extends Controller
{
    public function index(Request $request): View
    {
        $state = DriverState::for($request->user());
        $setting = DriverMoneySetting::query()->where('user_id', $request->user()->id)->first();

        return view('driver.account', [
            'state' => $state,
            'documents' => $state->profile() ? $state->documents() : null,
            'acceptsCash' => (bool) $setting?->accepts_cash,
            'cashOnPlatform' => (bool) config('money.cash.enabled'),
        ]);
    }

    public function cash(Request $request): RedirectResponse
    {
        abort_if(DriverState::for($request->user())->profile() === null, 404);
        $data = $request->validate(['accepts_cash' => ['required', 'boolean']]);

        $setting = DriverMoneySetting::firstOrNew(['user_id' => $request->user()->id]);
        $setting->forceFill(['accepts_cash' => (bool) $data['accepts_cash']])->save();

        return redirect()->route('drive.account')->with('status', $data['accepts_cash'] ? 'You now accept cash trips.' : 'You no longer accept cash trips.');
    }
}
