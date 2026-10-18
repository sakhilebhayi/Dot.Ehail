<?php

use App\Providers\AppServiceProvider;
use App\Providers\BroadcastServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\JetstreamServiceProvider;

return [
    AppServiceProvider::class,
    BroadcastServiceProvider::class,
    FortifyServiceProvider::class,
    JetstreamServiceProvider::class,
];
