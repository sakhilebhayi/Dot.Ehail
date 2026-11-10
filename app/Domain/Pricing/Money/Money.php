<?php

namespace App\Domain\Pricing\Money;

use InvalidArgumentException;
use JsonSerializable;

/**
 * An amount of money in integer minor units (cents for ZAR) with its ISO 4217 currency. Immutable; every operation
 * returns a new value; mixing currencies is refused; no float ever enters or leaves.
 */
final readonly class Money implements JsonSerializable
{
    public function __construct(public int $amountMinor, public string $currency)
    {
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidArgumentException("'{$currency}' is not an ISO 4217 currency code.");
        }
    }

    public static function of(int $amountMinor, string $currency): self
    {
        return new self($amountMinor, $currency);
    }

    public static function zero(string $currency): self
    {
        return new self(0, $currency);
    }

    /**
     * Parse a decimal string such as "80.00" or "1234.5" exactly (no float), for the legacy decimal columns.
     */
    public static function fromDecimal(string|int|float $decimal, string $currency): self
    {
        $exponent = Currency::isSupported($currency) ? Currency::of($currency)->exponent : 2;
        $text = is_float($decimal) ? number_format($decimal, $exponent, '.', '') : trim((string) $decimal);

        if (preg_match('/^(-)?(\d+)(?:\.(\d+))?$/', $text, $m) !== 1) {
            throw new InvalidArgumentException("'{$text}' is not a decimal amount.");
        }

        $fraction = $m[3] ?? '';
        $rounded = strlen($fraction) > $exponent;
        $kept = str_pad(substr($fraction, 0, $exponent), $exponent, '0');
        $minor = IntegerMath::add(IntegerMath::multiply((int) $m[2], 10 ** $exponent), (int) ($kept === '' ? '0' : $kept));

        if ($rounded && (int) $fraction[$exponent] >= 5) {
            $minor = IntegerMath::add($minor, 1);
        }

        return new self(($m[1] ?? '') === '-' ? -$minor : $minor, $currency);
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self(IntegerMath::add($this->amountMinor, $other->amountMinor), $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self(IntegerMath::subtract($this->amountMinor, $other->amountMinor), $this->currency);
    }

    public function negate(): self
    {
        return new self(IntegerMath::subtract(0, $this->amountMinor), $this->currency);
    }

    public function times(int $factor): self
    {
        return new self(IntegerMath::multiply($this->amountMinor, $factor), $this->currency);
    }

    /**
     * this x numerator / denominator, rounded once at the end.
     */
    public function multiplyRatio(int $numerator, int $denominator, RoundingMode $mode = RoundingMode::HalfUp): self
    {
        return new self(IntegerMath::divide(IntegerMath::multiply($this->amountMinor, $numerator), $denominator, $mode), $this->currency);
    }

    /**
     * this x basisPoints / 10 000 (1 500 bp = 15 %, 12 500 bp = 1.25x).
     */
    public function applyBasisPoints(int $basisPoints, RoundingMode $mode = RoundingMode::HalfUp): self
    {
        return $this->multiplyRatio($basisPoints, 10_000, $mode);
    }

    /**
     * Rounded to a multiple of $unitMinor minor units (100 = whole rand for ZAR).
     */
    public function roundToUnit(int $unitMinor, RoundingMode $mode = RoundingMode::HalfUp): self
    {
        return new self(IntegerMath::roundToMultiple($this->amountMinor, $unitMinor, $mode), $this->currency);
    }

    public function max(self $other): self
    {
        $this->assertSameCurrency($other);

        return $this->amountMinor >= $other->amountMinor ? $this : $other;
    }

    public function min(self $other): self
    {
        $this->assertSameCurrency($other);

        return $this->amountMinor <= $other->amountMinor ? $this : $other;
    }

    public function isZero(): bool
    {
        return $this->amountMinor === 0;
    }

    public function isNegative(): bool
    {
        return $this->amountMinor < 0;
    }

    public function isPositive(): bool
    {
        return $this->amountMinor > 0;
    }

    public function greaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->amountMinor > $other->amountMinor;
    }

    public function lessThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->amountMinor < $other->amountMinor;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->amountMinor === $other->amountMinor;
    }

    public function format(): string
    {
        return MoneyFormatter::format($this);
    }

    /**
     * "80.00": the decimal string for the legacy decimal(8,2) ride columns.
     */
    public function toDecimalString(): string
    {
        return MoneyFormatter::decimal($this->amountMinor, Currency::isSupported($this->currency) ? Currency::of($this->currency)->exponent : 2);
    }

    /**
     * @return array{amount_minor: int, currency: string, formatted: string}
     */
    public function jsonSerialize(): array
    {
        return ['amount_minor' => $this->amountMinor, 'currency' => $this->currency, 'formatted' => $this->format()];
    }

    private function assertSameCurrency(self $other): void
    {
        if ($other->currency !== $this->currency) {
            throw new CurrencyMismatch("Cannot combine {$this->currency} with {$other->currency}.");
        }
    }
}
