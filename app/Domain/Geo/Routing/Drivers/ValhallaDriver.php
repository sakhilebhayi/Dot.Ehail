<?php

namespace App\Domain\Geo\Routing\Drivers;

use App\Domain\Geo\Geometry\Polyline;
use App\Domain\Geo\LatLng;
use App\Domain\Geo\Routing\RouteOptions;
use App\Domain\Geo\Routing\RouteResult;
use Illuminate\Http\Client\Response;

/**
 * Valhalla /route on an operator-run or contracted server (EHAIL_VALHALLA_URL), auto costing. Valhalla shapes are
 * precision-6 polylines; they are re-encoded to the house precision 5.
 */
class ValhallaDriver extends HttpRoutingDriver
{
    public function name(): string
    {
        return 'valhalla';
    }

    public function isConfigured(): bool
    {
        return filled(config('ehail-geo.providers.valhalla.url'));
    }

    public function route(LatLng $from, LatLng $to, RouteOptions $options): RouteResult
    {
        $url = rtrim((string) config('ehail-geo.providers.valhalla.url'), '/').'/route';

        $response = $this->send(fn ($http) => $http->post($url, [
            'locations' => [['lat' => $from->lat, 'lon' => $from->lng], ['lat' => $to->lat, 'lon' => $to->lng]],
            'costing' => 'auto',
            'costing_options' => ['auto' => ['use_tolls' => $options->avoidTolls ? 0.0 : 0.5]],
            'units' => 'kilometers',
            'directions_type' => 'none',
        ]));

        $summary = $response->json('trip.summary');
        $legs = $response->json('trip.legs');

        if (! is_array($summary) || ! isset($summary['length'], $summary['time']) || ! is_array($legs)) {
            throw $this->invalid();
        }

        $points = [];

        foreach ($legs as $leg) {
            $legPoints = Polyline::decode((string) ($leg['shape'] ?? ''), 6);

            if ($points !== [] && $legPoints !== []) {
                array_shift($legPoints);
            }

            array_push($points, ...$legPoints);
        }

        return new RouteResult(
            (int) round((float) $summary['length'] * 1000),
            (int) round((float) $summary['time']),
            Polyline::encode($points, 5),
            $this->name(),
        );
    }

    protected function noRouteStatus(Response $response): bool
    {
        // 442: no path could be found; 171: no suitable edges near location.
        return in_array((int) $response->json('error_code'), [442, 171], true);
    }
}
