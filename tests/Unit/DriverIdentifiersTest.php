<?php

namespace Tests\Unit;

use App\Support\DriverIdentifiers;
use Tests\TestCase;

class DriverIdentifiersTest extends TestCase
{
    public function test_a_long_value_shows_only_its_last_four_characters(): void
    {
        $this->assertSame('•••• 8841', DriverIdentifiers::mask('ZA-LICENCE-8841'));
        $this->assertSame('•••• 0129', DriverIdentifiers::mask('8001015009087-0129'));
    }

    public function test_a_short_value_is_masked_completely_so_the_tail_is_not_most_of_it(): void
    {
        $this->assertSame('••••', DriverIdentifiers::mask('LIC-5'));
        $this->assertSame('••••', DriverIdentifiers::mask('1234567'));
        $this->assertSame('•••• 2345', DriverIdentifiers::mask('12345678 2345'));
        $this->assertSame('•••• 5678', DriverIdentifiers::mask('12345678'));
    }

    public function test_nothing_in_nothing_out(): void
    {
        $this->assertSame('—', DriverIdentifiers::mask(null));
        $this->assertSame('—', DriverIdentifiers::mask(''));
    }

    public function test_the_blind_index_ignores_case_and_surrounding_space_but_not_content(): void
    {
        $a = DriverIdentifiers::blindIndex('ZA-LICENCE-8841');

        $this->assertSame($a, DriverIdentifiers::blindIndex('  za-licence-8841 '));
        $this->assertNotSame($a, DriverIdentifiers::blindIndex('ZA-LICENCE-8842'));
        $this->assertSame(64, strlen($a));
        $this->assertStringNotContainsString('8841', $a);
        $this->assertNull(DriverIdentifiers::blindIndex(null));
        $this->assertNull(DriverIdentifiers::blindIndex('   '));
    }

    public function test_the_blind_index_depends_on_the_application_key(): void
    {
        $before = DriverIdentifiers::blindIndex('ZA-LICENCE-8841');
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);

        $this->assertNotSame($before, DriverIdentifiers::blindIndex('ZA-LICENCE-8841'));
    }
}
