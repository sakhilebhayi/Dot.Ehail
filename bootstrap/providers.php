<?php

use App\Domain\Geo\EhailGeoServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\BroadcastServiceProvider;
use App\Providers\EhailSafetyServiceProvider;
use App\Providers\EhailMoneyServiceProvider;
use App\Providers\EhailDispatchServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\JetstreamServiceProvider;

return [
    AppServiceProvider::class,
    BroadcastServiceProvider::class,
    App\Providers\EhailPricingServiceProvider::class,
    FortifyServiceProvider::class,
    JetstreamServiceProvider::class,
    EhailGeoServiceProvider::class,
    EhailSafetyServiceProvider::class,
    EhailMoneyServiceProvider::class,
    EhailDispatchServiceProvider::class,
    App\Providers\EhailDesignServiceProvider::class,
    App\Providers\EhailDriverServiceProvider::class,
];
