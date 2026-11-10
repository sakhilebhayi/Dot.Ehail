<?php

namespace Tests\Unit\Driver;

use App\Http\Controllers\Driver\Support\Place;
use PHPUnit\Framework\TestCase;

/**
 * Short addresses on the driver's screens (D-2102): street and suburb, no city, postcode, province or country. The
 * same cases as tests/js/driver-offer.test.mjs, so the server pages and the console agree.
 */
class PlaceTest extends TestCase
{
    public function test_addresses_shorten_to_the_street_and_the_suburb(): void
    {
        $this->assertSame('Oxford Road, Rosebank', Place::short('Oxford Road, Rosebank, Johannesburg, 2196, South Africa'));
        $this->assertSame('Sandton City, Rivonia Road, Sandhurst', Place::short('Sandton City, Rivonia Road, Sandhurst, Sandton, 2196, South Africa'));
        $this->assertSame('Juta Street, Braamfontein', Place::short('Juta Street, Braamfontein, Johannesburg, 2001, South Africa'));
        $this->assertSame('10 Kloof Street, Gardens', Place::short('10 Kloof Street, Gardens, Cape Town, Western Cape, 8001'));
        $this->assertSame('Kloof Street', Place::short('Kloof Street'));
        $this->assertSame('', Place::short(null));
    }
}
