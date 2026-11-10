<?php

namespace Tests\Feature\Ui;

use App\Domain\Geo\Geocoding\Geocoder;
use App\Domain\Geo\Geocoding\Place;
use App\Domain\Geo\LatLng;
use App\Support\Ui\PlaceNames;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Places read as words on work screens (R-46 polish, D-2201): never raw coordinates as the name. */
class PlaceNamesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_stored_address_is_kept_and_shortened(): void
    {
        $this->assertSame('Oxford Road, Rosebank, Johannesburg', PlaceNames::for('Oxford Road, Rosebank, Johannesburg, 2196, South Africa', -26.14, 28.04));
        $this->assertFalse(PlaceNames::isPinned('12 Main Road'));
        $this->assertTrue(PlaceNames::isPinned('Pinned location (-26.14570, 28.04130)'));
        $this->assertTrue(PlaceNames::isPinned(null));
    }

    public function test_a_pinned_point_never_reads_as_coordinates(): void
    {
        $name = PlaceNames::for('Pinned location (-26.14570, 28.04130)', -26.1457, 28.0413);

        $this->assertStringStartsWith('A pinned point ', $name);
        $this->assertDoesNotMatchRegularExpression('/-?\d{1,2}\.\d{3,}/', $name);
        $this->assertSame('Place not recorded', PlaceNames::for(null));
    }

    public function test_a_looked_up_name_is_remembered_for_lists_that_may_not_look_up(): void
    {
        $this->mock(Geocoder::class, fn ($mock) => $mock->shouldReceive('reverse')->once()
            ->andReturn(new Place('Oxford Road, Rosebank, Johannesburg, 2196, South Africa', 'Oxford Road', new LatLng(-26.1457, 28.0413), 'test')));

        $this->assertSame('Near Oxford Road, Rosebank, Johannesburg', PlaceNames::for(null, -26.1457, 28.0413, lookup: true));
        // No second provider call: the list reads the remembered name.
        $this->assertSame('Near Oxford Road, Rosebank, Johannesburg', PlaceNames::for('Pinned location (-26.14570, 28.04130)', -26.1457, 28.0413));
    }

    public function test_distances_and_nearest_stop_read_plainly(): void
    {
        $this->assertSame('350 m', PlaceNames::distance(352.4));
        $this->assertSame('2.8 km', PlaceNames::distance(2810));

        $phrase = app(PlaceNames::class)->relativeTo(-26.1457, 28.0413, [
            'pickup' => ['lat' => -26.1457, 'lng' => 28.0413, 'label' => 'Oxford Road, Rosebank, Johannesburg, 2196'],
            'drop-off' => ['lat' => -26.02, 'lng' => 28.01, 'label' => null],
        ]);
        $this->assertSame('At the pickup (Oxford Road, Rosebank, Johannesburg)', $phrase);
    }
}
