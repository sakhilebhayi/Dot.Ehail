<?php

namespace App\Domain\Geo\Routing\Drivers;

use App\Domain\Geo\Exceptions\RoutingUnavailable;
use App\Domain\Geo\Routing\RoutingDriver;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

abstract class HttpRoutingDriver implements RoutingDriver
{
    protected function http(): PendingRequest
    {
        return Http::timeout((float) config('ehail-geo.routing.timeout_seconds', 4))
            ->connectTimeout(min(3.0, (float) config('ehail-geo.routing.timeout_seconds', 4)))
            ->withUserAgent((string) config('ehail-geo.user_agent'))
            ->acceptJson();
    }

    /**
     * Runs the request and turns transport and HTTP failures into RoutingUnavailable.
     *
     * @param  Closure(PendingRequest): Response  $send
     */
    protected function send(Closure $send): Response
    {
        try {
            $response = $send($this->http());
        } catch (ConnectionException) {
            throw new RoutingUnavailable('timeout', $this->name());
        }

        if ($response->failed()) {
            throw new RoutingUnavailable($this->noRouteStatus($response) ? 'no_route' : 'provider_error', $this->name());
        }

        return $response;
    }

    /**
     * Whether an error response means "no route between these points" rather than a provider fault.
     */
    protected function noRouteStatus(Response $response): bool
    {
        return false;
    }

    protected function invalid(): RoutingUnavailable
    {
        return new RoutingUnavailable('invalid_response', $this->name());
    }

    protected function noRoute(): RoutingUnavailable
    {
        return new RoutingUnavailable('no_route', $this->name());
    }
}
