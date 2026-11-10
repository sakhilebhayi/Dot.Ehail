<?php

namespace App\Domain\Geo\Routing;

use App\Domain\Geo\Exceptions\RoutingUnavailable;
use App\Domain\Geo\LatLng;

interface RoutingDriver
{
    public function name(): string;

    /**
     * Whether the driver has what it needs (key or URL) to make a request.
     */
    public function isConfigured(): bool;

    /**
     * @throws RoutingUnavailable
     */
    public function route(LatLng $from, LatLng $to, RouteOptions $options): RouteResult;
}
