<?php

namespace App\Http\Controllers\Passenger;

use App\Domain\Passenger\Models\SavedPlace;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

/**
 * Account: payment methods and receipts (money API), saved places, and links to the pages other owners provide
 * (profile and password, notification preferences, data export). A link appears only when its route exists.
 */
class AccountController extends Controller
{
    /** Route names other owners may register; the first that exists is linked (D-808). */
    public const NOTIFICATION_ROUTES = ['comms.preferences', 'notifications.preferences', 'account.notifications'];

    public const DATA_EXPORT_ROUTES = ['privacy.export', 'account.data-export', 'account.export'];

    public function index(Request $request): View
    {
        return view('passenger.account', [
            'places' => SavedPlace::query()->where('user_id', $request->user()->id)
                ->orderByRaw("case kind when 'home' then 0 when 'work' then 1 else 2 end")->orderBy('label')->get(),
            'notificationsRoute' => $this->firstRoute(self::NOTIFICATION_ROUTES),
            'dataExportRoute' => $this->firstRoute(self::DATA_EXPORT_ROUTES),
        ]);
    }

    /**
     * @param  list<string>  $names
     */
    private function firstRoute(array $names): ?string
    {
        foreach ($names as $name) {
            if (Route::has($name)) {
                return $name;
            }
        }

        return null;
    }
}
