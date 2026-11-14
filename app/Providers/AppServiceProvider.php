<?php

namespace App\Providers;

use App\Http\Middleware\EnsureCompanyEntitled;
use App\Models\Ride;
use App\Observers\RideObserver;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // /up answered 200 whenever PHP booted, even with the database down. The framework dispatches DiagnosingHealth
        // before it renders the page: a failing query turns the answer into a 500 (the exception text is never shown).
        Event::listen(DiagnosingHealth::class, fn () => DB::select('select 1'));

        // R-50: Livewire updates from a page in the signed-in area carry that page's entitlement check with them.
        Livewire::addPersistentMiddleware([EnsureCompanyEntitled::class]);

        Ride::observe(RideObserver::class);
    }
}
