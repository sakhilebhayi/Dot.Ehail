<?php

namespace App\Domain\Pricing\Finalisation;

/**
 * Why a fare differs from the quote, in a stable code and words the passenger reads. amount_minor is the signed
 * effect on the total; the adjustments of a fare always sum to final - quoted.
 */
final readonly class FareAdjustment
{
    public function __construct(
        public string $code,
        public string $text,
        public int $amountMinor,
        public ?int $actorId = null,
        public ?string $at = null,
    ) {}

    /**
     * @return array{code: string, text: string, amount_minor: int, actor_id: ?int, at: ?string}
     */
    public function toArray(): array
    {
        return ['code' => $this->code, 'text' => $this->text, 'amount_minor' => $this->amountMinor, 'actor_id' => $this->actorId, 'at' => $this->at];
    }

    /**
     * @param  array{code: string, text: string, amount_minor: int, actor_id?: ?int, at?: ?string}  $row
     */
    public static function fromArray(array $row): self
    {
        return new self($row['code'], $row['text'], (int) $row['amount_minor'], $row['actor_id'] ?? null, $row['at'] ?? null);
    }
}
