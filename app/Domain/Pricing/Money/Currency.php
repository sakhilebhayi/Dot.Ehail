<?php

namespace App\Domain\Pricing\Money;

use InvalidArgumentException;

/**
 * Currency facts from config/ehail_pricing.php (currencies): the symbol and how many minor units make one whole
 * unit. Unknown codes are refused rather than guessed.
 */
final readonly class Currency
{
    public function __construct(public string $code, public string $symbol, public int $exponent) {}

    public static function of(string $code): self
    {
        $config = config("ehail_pricing.currencies.{$code}");

        if (! is_array($config)) {
            throw new InvalidArgumentException("Currency {$code} is not configured in ehail_pricing.currencies.");
        }

        return new self($code, (string) $config['symbol'], (int) $config['exponent']);
    }

    public static function isSupported(string $code): bool
    {
        return is_array(config("ehail_pricing.currencies.{$code}"));
    }

    /**
     * Minor units in one whole unit: 100 for ZAR (cents per rand).
     */
    public function minorPerUnit(): int
    {
        return 10 ** $this->exponent;
    }
}
