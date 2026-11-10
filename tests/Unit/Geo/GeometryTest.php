<?php

namespace Tests\Unit\Geo;

use App\Domain\Geo\Geometry\BoundingBox;
use App\Domain\Geo\Geometry\Haversine;
use App\Domain\Geo\Geometry\PathDistance;
use App\Domain\Geo\Geometry\Polygon;
use App\Domain\Geo\Geometry\Polyline;
use App\Domain\Geo\LatLng;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class GeometryTest extends TestCase
{
    public function test_haversine_matches_known_distances(): void
    {
        // Johannesburg CBD to Pretoria CBD: about 54 km in a straight line.
        $jhb = new LatLng(-26.2041, 28.0473);
        $pta = new LatLng(-25.7479, 28.2293);
        $this->assertEqualsWithDelta(53_800, Haversine::metres($jhb, $pta), 800);

        // One degree of latitude is about 111.2 km everywhere.
        $this->assertEqualsWithDelta(111_195, Haversine::metres(new LatLng(0, 0), new LatLng(1, 0)), 10);
        $this->assertSame(0.0, Haversine::metres($jhb, $jhb));
    }

    public function test_bearing_points_the_right_way(): void
    {
        $origin = new LatLng(-26.0, 28.0);
        $this->assertEqualsWithDelta(0, Haversine::bearing($origin, new LatLng(-25.9, 28.0)), 0.01);
        $this->assertEqualsWithDelta(90, Haversine::bearing($origin, new LatLng(-26.0, 28.1)), 0.1);
        $this->assertEqualsWithDelta(180, Haversine::bearing($origin, new LatLng(-26.1, 28.0)), 0.01);
    }

    public function test_lat_lng_rejects_out_of_range_values(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new LatLng(91, 0);
    }

    public function test_polyline_decodes_googles_reference_example(): void
    {
        $points = Polyline::decode('_p~iF~ps|U_ulLnnqC_mqNvxq`@');

        $this->assertCount(3, $points);
        $this->assertEqualsWithDelta(38.5, $points[0]->lat, 1e-9);
        $this->assertEqualsWithDelta(-120.2, $points[0]->lng, 1e-9);
        $this->assertEqualsWithDelta(40.7, $points[1]->lat, 1e-9);
        $this->assertEqualsWithDelta(-120.95, $points[1]->lng, 1e-9);
        $this->assertEqualsWithDelta(43.252, $points[2]->lat, 1e-9);
        $this->assertEqualsWithDelta(-126.453, $points[2]->lng, 1e-9);
    }

    public function test_polyline_encodes_googles_reference_example(): void
    {
        $encoded = Polyline::encode([new LatLng(38.5, -120.2), new LatLng(40.7, -120.95), new LatLng(43.252, -126.453)]);

        $this->assertSame('_p~iF~ps|U_ulLnnqC_mqNvxq`@', $encoded);
    }

    public function test_polyline_round_trips_at_precision_six(): void
    {
        $points = [new LatLng(-33.924869, 18.424055), new LatLng(-33.971463, 18.602085), new LatLng(-34.0, 18.5)];
        $decoded = Polyline::decode(Polyline::encode($points, 6), 6);

        foreach ($points as $i => $point) {
            $this->assertEqualsWithDelta($point->lat, $decoded[$i]->lat, 1e-6);
            $this->assertEqualsWithDelta($point->lng, $decoded[$i]->lng, 1e-6);
        }
    }

    public function test_truncated_polyline_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Polyline::decode('_p~iF~ps|U_');
    }

    public function test_point_in_polygon_handles_concave_shapes_edges_and_closed_rings(): void
    {
        // An L shape: the notch at the top right is outside.
        $shape = Polygon::fromPairs([[0, 0], [0, 2], [1, 2], [1, 1], [2, 1], [2, 0], [0, 0]]);

        $this->assertCount(6, $shape->vertices, 'the closing vertex is normalised away');
        $this->assertTrue($shape->contains(new LatLng(0.5, 0.5)));
        $this->assertTrue($shape->contains(new LatLng(0.5, 1.5)));
        $this->assertTrue($shape->contains(new LatLng(1.5, 0.5)));
        $this->assertFalse($shape->contains(new LatLng(1.5, 1.5)), 'inside the bbox but in the notch');
        $this->assertFalse($shape->contains(new LatLng(3, 3)), 'outside the bbox');
        $this->assertTrue($shape->contains(new LatLng(0, 1)), 'on an edge counts as inside');
        $this->assertTrue($shape->contains(new LatLng(1, 1)), 'on the inner corner counts as inside');
    }

    public function test_polygon_needs_three_vertices(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Polygon::fromPairs([[0, 0], [1, 1]]);
    }

    public function test_radius_box_contains_every_point_of_the_circle(): void
    {
        $centre = new LatLng(-26.1, 28.05);
        $box = BoundingBox::radius($centre, 5_000);

        foreach ([0, 45, 90, 135, 180, 225, 270, 315] as $bearing) {
            $rad = deg2rad($bearing);
            $point = new LatLng(
                $centre->lat + rad2deg(4_990 * cos($rad) / Haversine::EARTH_RADIUS_M),
                $centre->lng + rad2deg(4_990 * sin($rad) / (Haversine::EARTH_RADIUS_M * cos(deg2rad($centre->lat)))),
            );
            $this->assertLessThan(5_000, Haversine::metres($centre, $point));
            $this->assertTrue($box->contains($point), "bearing {$bearing}");
        }
    }

    public function test_path_distance_measures_the_perpendicular_offset(): void
    {
        $path = [new LatLng(-26.0, 28.0), new LatLng(-26.0, 28.1)];
        // 0.0009 degrees of latitude is about 100 m.
        $this->assertEqualsWithDelta(100, PathDistance::metres(new LatLng(-26.0009, 28.05), $path), 1.0);
        // Beyond the end of the segment the distance is to the end point.
        $beyond = new LatLng(-26.0, 28.11);
        $this->assertEqualsWithDelta(Haversine::metres($beyond, $path[1]), PathDistance::metres($beyond, $path), 1.0);
        $this->assertSame(INF, PathDistance::metres($beyond, []));
    }
}
