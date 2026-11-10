<?php

namespace App\Domain\Ledger;

use InvalidArgumentException;

/**
 * A journal to post: one business event, its lines and the key that makes posting it twice a no-op.
 * Build with debit()/credit(); zero-amount lines are dropped, negative amounts are refused.
 */
final class JournalEntry
{
    /** @var array<int, array{account: string, debit: int, credit: int}> */
    private array $lines = [];

    /**
     * @param  array{ride_id?: ?int, payment_id?: ?int, refund_id?: ?int, payout_id?: ?int, earning_id?: ?int}  $refs
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $idempotencyKey,
        public readonly string $currency,
        public readonly string $reason,
        public readonly array $refs = [],
        public readonly ?int $postedBy = null,
    ) {
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException('Currency must be an ISO 4217 code.');
        }
    }

    public function debit(string $account, int $amountMinor): self
    {
        return $this->line($account, $amountMinor, 0);
    }

    public function credit(string $account, int $amountMinor): self
    {
        return $this->line($account, 0, $amountMinor);
    }

    /**
     * @return array<int, array{account: string, debit: int, credit: int}>
     */
    public function lines(): array
    {
        return $this->lines;
    }

    public function totalDebits(): int
    {
        return array_sum(array_column($this->lines, 'debit'));
    }

    public function totalCredits(): int
    {
        return array_sum(array_column($this->lines, 'credit'));
    }

    public function isBalanced(): bool
    {
        return $this->lines !== [] && $this->totalDebits() === $this->totalCredits();
    }

    private function line(string $account, int $debit, int $credit): self
    {
        if ($debit < 0 || $credit < 0) {
            throw new InvalidArgumentException('Ledger amounts are never negative; post to the other side instead.');
        }

        Account::parse($account);

        if ($debit === 0 && $credit === 0) {
            return $this;
        }

        $this->lines[] = ['account' => $account, 'debit' => $debit, 'credit' => $credit];

        return $this;
    }
}
