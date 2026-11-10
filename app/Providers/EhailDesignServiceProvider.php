<?php

namespace App\Providers;

use App\Support\Ui\Navigation;
use App\Support\Ui\RoleHome;
use Illuminate\Support\ServiceProvider;

/**
 * R-46 design system (ds agent): the role and navigation helpers the app shells read. Scoped so role facts are
 * looked up once per request.
 */
class EhailDesignServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(RoleHome::class);
        $this->app->scoped(Navigation::class);
    }
}
