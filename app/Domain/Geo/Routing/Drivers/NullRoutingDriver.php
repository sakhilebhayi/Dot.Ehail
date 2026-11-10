<?php

namespace App\Domain\Geo\Routing\Drivers;

use App\Domain\Geo\Exceptions\RoutingUnavailable;
use App\Domain\Geo\LatLng;
use App\Domain\Geo\Routing\RouteOptions;
use App\Domain\Geo\Routing\RouteResult;
use App\Domain\Geo\Routing\RoutingDriver;

/**
 * The honest unconfigured state: there is no road route, and nothing pretends there is.
 */
class NullRoutingDriver implements RoutingDriver
{
    public function name(): string
    {
        return 'none';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function route(LatLng $from, LatLng $to, RouteOptions $options): RouteResult
    {
        throw RoutingUnavailable::notConfigured();
    }
}
