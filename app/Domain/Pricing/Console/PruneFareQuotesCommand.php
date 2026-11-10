<?php

namespace App\Domain\Pricing\Console;

use App\Domain\Pricing\Models\FareQuote;
use Illuminate\Console\Command;

/**
 * Unused booking quotes hold pickup and drop-off coordinates and bind nobody once expired: delete them after the
 * retention window (POPIA minimisation). Consumed quotes stay: the ride's fare refers to them.
 */
class PruneFareQuotesCommand extends Command
{
    protected $signature = 'ehail:pricing:prune-quotes';

    protected $description = 'Delete expired, unused fare quotes older than the retention window.';

    public function handle(): int
    {
        $cutoff = now()->subHours(max(1, (int) config('ehail_pricing.quotes.retain_unconsumed_hours', 24)));
        $deleted = FareQuote::query()->whereNull('consumed_at')->where('expires_at', '<', $cutoff)->delete();
        $this->info("Deleted {$deleted} expired unused quote(s).");

        return self::SUCCESS;
    }
}
