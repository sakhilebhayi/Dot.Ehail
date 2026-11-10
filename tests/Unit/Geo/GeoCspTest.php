<?php

namespace Tests\Unit\Geo;

use App\Domain\Geo\Support\GeoCsp;
use Tests\TestCase;

class GeoCspTest extends TestCase
{
    public function test_default_tiles_need_the_osm_tile_host(): void
    {
        $this->assertSame(['https://tile.openstreetmap.org'], GeoCsp::imgSources());
    }

    public function test_subdomain_templates_and_non_https_urls(): void
    {
        config(['ehail-geo.tiles.url' => 'https://{s}.tiles.example.test/{z}/{x}/{y}.png?key=abc']);
        $this->assertSame(['https://*.tiles.example.test'], GeoCsp::imgSources());

        config(['ehail-geo.tiles.url' => 'http://tiles.example.test/{z}/{x}/{y}.png']);
        $this->assertSame([], GeoCsp::imgSources());
    }
}
