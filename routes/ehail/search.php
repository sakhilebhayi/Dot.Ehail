<?php

// R-52 header search, loaded from routes/web.php inside the signed-in group (auth, verified, company entitlement).
// Routes only. The scope of what is searched is decided in App\Domain\Search\HeaderSearch from who the person is.

use App\Http\Controllers\Search\SearchController;
use Illuminate\Support\Facades\Route;

Route::get('/search', [SearchController::class, 'page'])->middleware('throttle:120,1')->name('search');
Route::get('/search/suggest', [SearchController::class, 'suggest'])->middleware('throttle:120,1')->name('search.suggest');
