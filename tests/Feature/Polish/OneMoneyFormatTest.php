<?php

namespace Tests\Feature\Polish;

use App\Domain\Payments\Money as PaymentsMoney;
use App\Domain\Pricing\Money\Money as PricingMoney;
use App\Domain\Pricing\Money\MoneyFormatter as PricingFormatter;
use App\Support\Ui\MoneyFormatter;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * One money text everywhere (D-1903): "R10 000.00", the thousands separated by a narrow no-break space (U+202F), a dot
 * before the cents, the symbol attached. Every formatter in the platform gives the same characters, so a quote, a
 * receipt, an earnings line and a statement can be compared by eye. The browser side is tested in tests/js.
 */
class OneMoneyFormatTest extends TestCase
{
    private const TEN_THOUSAND = "R10\u{202F}000.00";

    public function test_every_formatter_writes_the_same_characters(): void
    {
        $samples = [0 => 'R0.00', 5 => 'R0.05', 6400 => 'R64.00', 99999 => 'R999.99', 100000 => "R1\u{202F}000.00", 1000000 => self::TEN_THOUSAND, 123456789 => "R1\u{202F}234\u{202F}567.89"];

        foreach ($samples as $minor => $text) {
            $this->assertSame($text, MoneyFormatter::format($minor), 'Ui formatter');
            $this->assertSame($text, MoneyFormatter::withSymbol($minor, 'R'), 'Ui withSymbol');
            $this->assertSame($text, PricingFormatter::formatMinor($minor, 'R'), 'pricing formatter');
            $this->assertSame($text, PricingFormatter::format(PricingMoney::of($minor, 'ZAR')), 'pricing money');
            $this->assertSame($text, PricingMoney::of($minor, 'ZAR')->format(), 'pricing money object');
            $this->assertSame($text, PaymentsMoney::format($minor, 'ZAR'), 'payments money');
            $this->assertSame($text, trim(strip_tags(Blade::render('<x-eh.money :minor="$minor" />', ['minor' => $minor]))), 'the money component');
        }
    }

    public function test_the_separator_is_a_narrow_no_break_space_and_never_a_comma_or_plain_space(): void
    {
        $text = MoneyFormatter::format(1000000);

        $this->assertSame(self::TEN_THOUSAND, $text);
        $this->assertStringContainsString("\u{202F}", $text);
        $this->assertStringNotContainsString(',', $text);
        $this->assertStringNotContainsString(' ', $text, 'a plain space would let the amount wrap across lines');
        $this->assertStringNotContainsString("\u{00A0}", $text);
        $this->assertMatchesRegularExpression('/\.\d{2}$/', $text);
        // Negative amounts use a real minus sign before the symbol.
        $this->assertSame("\u{2212}R10\u{202F}000.00", MoneyFormatter::format(-1000000));
    }
}
