<?php

namespace App\Domain\Verification\Console;

use App\Domain\Verification\VerificationService;
use Illuminate\Console\Command;

/**
 * Daily: warn owners 30 and 7 days before an approved document expires, then expire the ones
 * whose date has passed (which takes an affected driver offline). Safe to run repeatedly.
 */
class SweepVerificationExpiry extends Command
{
    protected $signature = 'ehail:verification-expiry';

    protected $description = 'Warn about expiring driver and vehicle documents and expire the ones past their date';

    public function handle(VerificationService $verification): int
    {
        $warned = $verification->warnExpiring();
        $expired = $verification->expireDue();

        $this->info("Warnings sent: {$warned}. Documents expired: {$expired}.");

        return self::SUCCESS;
    }
}
