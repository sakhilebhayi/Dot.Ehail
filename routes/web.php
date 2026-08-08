<?php

use App\Http\Controllers\Auth\EcosystemAuthController;
use App\Http\Controllers\Ehail\DriverApplicationController;
use App\Http\Controllers\Ehail\DriverController;
use App\Http\Controllers\Ehail\RideController;
use App\Models\DriverProfile;
use App\Models\Ride;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Jetstream\Jetstream;

Route::get('/auth/ecosystem', [EcosystemAuthController::class, 'handle'])->name('ecosystem.auth');
Route::get('/', function () {
    return view('welcome');
});

// Cookie Policy — Jetstream's termsAndPrivacyPolicy feature covers terms.show/policy.show
// natively. There's no Jetstream equivalent for a Cookie Policy, so this one is wired by hand,
// following the exact same Markdown-source convention.
Route::get('/cookies', function () {
    return view('cookies', [
        'cookies' => Str::markdown(file_get_contents(Jetstream::localizedMarkdownPath('cookies.md'))),
    ]);
})->name('cookies');

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    Route::get('/dashboard', function () {
        $totalRides = Ride::count();
        $activeRides = Ride::whereIn('status', ['accepted', 'en_route', 'arrived', 'in_progress'])->count();
        $completedRides = Ride::where('status', 'completed')->count();
        $cancelledRides = Ride::where('status', 'cancelled')->count();
        $requestedRides = Ride::where('status', 'requested')->count();

        // DriverProfile now carries HasUserScope (a driver's own profile is
        // single-owner tenant data — see app/Models/Concerns/HasUserScope.php),
        // but this dashboard is a documented platform-wide ops view (wiki.md
        // §4), so its aggregate counts must stay unscoped or every operator
        // would silently see driver counts of 0/1 instead of the real
        // platform-wide numbers.
        $totalDrivers = DriverProfile::withoutGlobalScope('user')->count();
        $availableDrivers = DriverProfile::withoutGlobalScope('user')->where('is_online', true)->count();
        $approvedDrivers = DriverProfile::withoutGlobalScope('user')->where('status', 'approved')->count();

        $totalRevenue = Ride::where('status', 'completed')->sum('final_fare');

        $statusCounts = Ride::selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $recentRides = Ride::with(['driver', 'passenger', 'vehicle'])
            ->latest()
            ->limit(10)
            ->get();

        return view('dashboard', compact(
            'totalRides', 'activeRides', 'completedRides', 'cancelledRides', 'requestedRides',
            'totalDrivers', 'availableDrivers', 'approvedDrivers',
            'totalRevenue', 'statusCounts', 'recentRides'
        ));
    })->name('dashboard');

    Route::get('/rides', [RideController::class, 'index'])->name('rides.index');
    Route::get('/rides/{ride}', [RideController::class, 'show'])->name('rides.show');
    Route::get('/drivers/{driverProfile}', [DriverController::class, 'show'])->name('drivers.show');

    Route::get('/drive/apply', [DriverApplicationController::class, 'create'])->name('drive.apply');
    Route::post('/drive/apply', [DriverApplicationController::class, 'store'])->name('drive.apply.store');
});
