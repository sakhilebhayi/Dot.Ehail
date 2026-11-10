<?php

namespace Tests\Unit;

use App\Support\Ui\Duration;
use App\Support\Ui\MoneyFormatter;
use PHPUnit\Framework\TestCase;

class MoneyFormatterTest extends TestCase
{
    public function test_rand_amounts_format_from_minor_units(): void
    {
        $this->assertSame('R0.00', MoneyFormatter::format(0));
        $this->assertSame('R0.05', MoneyFormatter::format(5));
        $this->assertSame('R125.50', MoneyFormatter::format(12550));
        $this->assertSame("R1\u{202F}250.00", MoneyFormatter::format(125000));
        $this->assertSame("R1\u{202F}234\u{202F}567.89", MoneyFormatter::format(123456789));
        $this->assertSame("\u{2212}R40.00", MoneyFormatter::format(-4000));
        $this->assertSame('+R12.00', MoneyFormatter::format(1200, 'ZAR', signed: true));
        $this->assertSame('R80', MoneyFormatter::format(8000, wholeUnits: true));
        $this->assertSame('R80.50', MoneyFormatter::format(8050, wholeUnits: true), 'cents are never hidden');
    }

    public function test_other_currencies_use_their_exponent(): void
    {
        $this->assertSame("JPY 1\u{202F}500", MoneyFormatter::format(1500, 'JPY'));
        $this->assertSame('KWD 1.500', MoneyFormatter::format(1500, 'KWD'));
        $this->assertSame('KSh250.00', MoneyFormatter::format(25000, 'KES'));
    }

    public function test_spoken_form_says_minus(): void
    {
        $this->assertSame('minus R40.00', MoneyFormatter::spoken(-4000));
        $this->assertSame('R1250.00', MoneyFormatter::spoken(125000));
    }

    public function test_durations_read_as_journey_times(): void
    {
        $this->assertSame('1 min', Duration::short(0));
        $this->assertSame('1 min', Duration::short(59));
        $this->assertSame('2 min', Duration::short(61));
        $this->assertSame('1 h 05 min', Duration::short(3900));
        $this->assertSame('1 hour 5 minutes', Duration::spoken(3900));
        $this->assertSame('4 minutes', Duration::spoken(240));
    }
}
