<?php

use App\Http\Controllers\Auth\EcosystemAuthController;
use App\Http\Controllers\Ehail\DriverController;
use App\Http\Controllers\Ehail\RideController;
use Illuminate\Support\Facades\Route;


Route::get('/auth/ecosystem', [EcosystemAuthController::class, 'handle'])->name('ecosystem.auth');
Route::get('/', function () {
    return view('welcome');
});

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    Route::get('/dashboard', function () {
        $totalRides       = \App\Models\Ride::count();
        $activeRides      = \App\Models\Ride::whereIn('status', ['accepted', 'en_route', 'arrived', 'in_progress'])->count();
        $completedRides   = \App\Models\Ride::where('status', 'completed')->count();
        $cancelledRides   = \App\Models\Ride::where('status', 'cancelled')->count();
        $requestedRides   = \App\Models\Ride::where('status', 'requested')->count();

        $totalDrivers     = \App\Models\DriverProfile::count();
        $availableDrivers = \App\Models\DriverProfile::where('is_online', true)->count();
        $approvedDrivers  = \App\Models\DriverProfile::where('status', 'approved')->count();

        $totalRevenue     = \App\Models\Ride::where('status', 'completed')->sum('final_fare');

        $statusCounts = \App\Models\Ride::selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $recentRides = \App\Models\Ride::with(['driver', 'passenger', 'vehicle'])
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
});
