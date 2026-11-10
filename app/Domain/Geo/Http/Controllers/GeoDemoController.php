<?php

namespace App\Domain\Geo\Http\Controllers;

use App\Domain\Geo\Support\GeoClientConfig;
use App\Http\Controllers\Controller;
use Illuminate\View\View;

/**
 * Development-only page to exercise the map module (search, route, simulated moving marker). Answers 404
 * everywhere except APP_ENV=local, so it is inert in production even with a cached route table.
 */
class GeoDemoController extends Controller
{
    public function __invoke(GeoClientConfig $config): View
    {
        abort_unless(app()->environment('local'), 404);

        return view('geo.demo', ['geoConfig' => $config->toArray()]);
    }
}
