<?php

namespace Tests\Unit\Passenger;

use App\Domain\Passenger\PlaceLabel;
use PHPUnit\Framework\TestCase;

/**
 * Place names on the passenger's screens (D-2004) and the area-only form the public trip-share page uses (D-2011).
 * tests/js/passenger.test.mjs holds the same split cases for the browser.
 */
class PlaceLabelTest extends TestCase
{
    public function test_a_label_splits_into_the_name_and_where_it_is(): void
    {
        $this->assertSame(
            ['name' => 'Sandton City', 'detail' => 'Rivonia Road, Sandhurst', 'short' => 'Sandton City, Rivonia Road'],
            PlaceLabel::split('Sandton City, Rivonia Road, Sandhurst, Sandton, 2196, South Africa'),
        );
        $this->assertSame('12 Bree Street, Newtown', PlaceLabel::short('12, Bree Street, Newtown, Johannesburg, 2001, South Africa'));
        $this->assertSame('Kloof Street', PlaceLabel::short('Kloof Street'));
        $this->assertSame('', PlaceLabel::short(null));
    }

    public function test_the_area_never_carries_a_street_a_building_or_a_number(): void
    {
        $this->assertSame('Soweto', PlaceLabel::area('12 Secret Home Road, Soweto'));
        $this->assertSame('Sandhurst', PlaceLabel::area('Sandton City, Rivonia Road, Sandhurst, Sandton, 2196, South Africa'));
        $this->assertSame('Rosebank', PlaceLabel::area('Oxford Road, Rosebank, Johannesburg, 2196, South Africa'));
        $this->assertSame('Gardens', PlaceLabel::area('10, Kloof Street, Gardens, Cape Town'));
        $this->assertSame('Kempton Park', PlaceLabel::area('OR Tambo International Airport, R21, Kempton Park'));
        $this->assertSame('Stellenbosch', PlaceLabel::area('Die Braak, Birdstraat, Stellenbosch'));
    }

    public function test_a_label_with_no_area_gives_nothing_rather_than_the_address(): void
    {
        $this->assertSame('', PlaceLabel::area('99 Private Office Park'));
        $this->assertSame('', PlaceLabel::area('14 Long Street, Unit 7'));
        $this->assertSame('', PlaceLabel::area(''));
        $this->assertSame('', PlaceLabel::area(null));
    }
}
