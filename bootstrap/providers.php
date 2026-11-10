<?php

use App\Domain\Geo\EhailGeoServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\BroadcastServiceProvider;
use App\Providers\EhailDispatchServiceProvider;
use App\Providers\EhailIntegrationServiceProvider;
use App\Providers\EhailMoneyServiceProvider;
use App\Providers\EhailPricingServiceProvider;
use App\Providers\EhailSafetyServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\JetstreamServiceProvider;

return [
    AppServiceProvider::class,
    BroadcastServiceProvider::class,
    EhailPricingServiceProvider::class,
    FortifyServiceProvider::class,
    JetstreamServiceProvider::class,
    EhailGeoServiceProvider::class,
    EhailSafetyServiceProvider::class,
    EhailMoneyServiceProvider::class,
    EhailDispatchServiceProvider::class,
    App\Providers\EhailDesignServiceProvider::class,
    // Must stay last: binds every cross-domain seam to its real implementation (docs/R46_INTEGRATION.md).
    EhailIntegrationServiceProvider::class,
];
