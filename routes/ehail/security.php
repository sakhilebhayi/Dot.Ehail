<?php

// R-46 security and privacy routes, loaded from routes/web.php (auth group). Routes only: limiters and the
// ehail.recent-auth alias live in EhailSecurityServiceProvider (route:cache). docs/R46_PRIVACY.md.

use App\Http\Controllers\Security\PersonalDataController;
use Illuminate\Support\Facades\Route;

Route::get('/account/data-export', [PersonalDataController::class, 'export'])
    ->middleware(['throttle:ehail-data-export', 'ehail.recent-auth'])
    ->name('account.data-export');
