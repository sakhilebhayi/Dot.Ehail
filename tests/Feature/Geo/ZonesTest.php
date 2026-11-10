<?php

namespace Tests\Feature\Geo;

use App\Domain\Geo\LatLng;
use App\Domain\Geo\Models\ServiceArea;
use App\Domain\Geo\Models\Zone;
use App\Domain\Geo\Zones\ReferenceAreas;
use App\Domain\Geo\Zones\ZoneResolver;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ZonesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Public landmark coordinates.
     *
     * @return array<string, array{0: float, 1: float, 2: ?string, 3: ?string}>
     */
    public static function landmarks(): array
    {
        return [
            'Johannesburg CBD' => [-26.2041, 28.0473, 'za-johannesburg', null],
            'Sandton City' => [-26.1076, 28.0567, 'za-johannesburg', null],
            'Soweto, Vilakazi Street' => [-26.2385, 27.9086, 'za-johannesburg', null],
            'OR Tambo terminal' => [-26.1337, 28.2420, 'za-johannesburg', 'za-jnb-airport'],
            'Pretoria Church Square' => [-25.7461, 28.1881, 'za-pretoria', null],
            'Centurion Mall' => [-25.8579, 28.1886, 'za-pretoria', null],
            'Cape Town Greenmarket Square' => [-33.9249, 18.4199, 'za-cape-town', null],
            'Cape Town International terminal' => [-33.9715, 18.6021, 'za-cape-town', 'za-cpt-airport'],
            'Durban CBD' => [-29.8587, 31.0218, 'za-durban', null],
            'King Shaka terminal' => [-29.6144, 31.1197, 'za-durban', 'za-dur-airport'],
            'Bloemfontein (not served)' => [-29.0852, 26.1596, null, null],
            'Atlantic Ocean' => [-33.0, 15.0, null, null],
        ];
    }

    #[DataProvider('landmarks')]
    public function test_reference_areas_classify_landmarks(float $lat, float $lng, ?string $area, ?string $airport): void
    {
        ReferenceAreas::install();
        $resolver = new ZoneResolver;
        $point = new LatLng($lat, $lng);

        $this->assertSame($area, $resolver->serviceAreaFor($point)?->slug);
        $this->assertSame($airport, $resolver->zonesFor($point, 'airport')[0]->slug ?? null);
    }

    public function test_reference_install_is_idempotent_and_never_overwrites_edits(): void
    {
        $this->assertSame(['areas_created' => 4, 'zones_created' => 3], ReferenceAreas::install());

        ServiceArea::where('slug', 'za-cape-town')->first()->update(['name' => 'Cape Town (reviewed)', 'is_approximate' => false]);
        ServiceArea::where('slug', 'za-durban')->delete();

        $this->assertSame(['areas_created' => 1, 'zones_created' => 0], ReferenceAreas::install());
        $this->assertSame('Cape Town (reviewed)', ServiceArea::where('slug', 'za-cape-town')->value('name'));
        $this->assertTrue(ServiceArea::where('slug', 'za-johannesburg')->value('is_approximate'));
        $this->assertSame(ReferenceAreas::NOTE, Zone::where('slug', 'za-jnb-airport')->value('source_note'));
        $this->assertSame('airport', Zone::where('slug', 'za-jnb-airport')->value('fee_code'));
    }

    public function test_the_artisan_command_installs_reference_areas(): void
    {
        $this->artisan('ehail:geo-reference-areas')->expectsOutputToContain('4 service areas and 3 zones created')->assertSuccessful();
    }

    public function test_bounding_box_is_kept_in_step_with_the_polygon(): void
    {
        $zone = Zone::factory()->create(['polygon' => [[-26.0, 28.0], [-26.0, 28.2], [-26.3, 28.1]]]);

        $this->assertSame(-26.3, $zone->min_lat);
        $this->assertSame(28.2, $zone->max_lng);

        $zone->update(['polygon' => [[-25.0, 27.0], [-25.0, 27.5], [-25.5, 27.5], [-25.5, 27.0]]]);
        $this->assertSame(-25.5, $zone->fresh()->min_lat);
    }

    public function test_inactive_zones_and_areas_are_ignored_and_the_smallest_area_wins(): void
    {
        ServiceArea::factory()->create(['slug' => 'big', 'polygon' => [[-27, 27], [-27, 29], [-25, 29], [-25, 27]]]);
        ServiceArea::factory()->create(['slug' => 'small', 'polygon' => [[-26.3, 27.95], [-26.3, 28.15], [-26.1, 28.15], [-26.1, 27.95]]]);
        ServiceArea::factory()->create(['slug' => 'off', 'is_active' => false, 'polygon' => [[-26.25, 28.0], [-26.25, 28.1], [-26.15, 28.1], [-26.15, 28.0]]]);
        Zone::factory()->create(['slug' => 'closed', 'kind' => 'restricted', 'is_active' => false]);
        Zone::factory()->create(['slug' => 'no-go', 'kind' => 'restricted']);

        $resolver = new ZoneResolver;
        $point = new LatLng(-26.2, 28.04);

        $this->assertSame('small', $resolver->serviceAreaFor($point)->slug);
        $this->assertSame(['no-go'], array_map(fn ($z) => $z->slug, $resolver->zonesFor($point)));
        $this->assertTrue($resolver->isRestricted($point));
    }

    public function test_zones_endpoint(): void
    {
        ReferenceAreas::install();

        $this->actingAs(User::factory()->withPersonalTeam()->create())
            ->getJson(route('geo.zones', ['lat' => -26.1337, 'lng' => 28.2420]))
            ->assertOk()
            ->assertJsonPath('service_area.slug', 'za-johannesburg')
            ->assertJsonPath('service_area.currency', 'ZAR')
            ->assertJsonPath('service_area.approximate', true)
            ->assertJsonPath('zones.0.kind', 'airport')
            ->assertJsonPath('zones.0.fee_code', 'airport');
    }
}
