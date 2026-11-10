<?php

namespace App\Domain\Comms\Console;

use App\Domain\Comms\Push\Base64Url;
use App\Domain\Comms\Push\EcKeys;
use Illuminate\Console\Command;

/**
 * Prints a new VAPID key pair for .env. It writes nothing: keys are secrets and belong in the
 * host's environment, never in the repository. Changing keys invalidates every existing browser
 * subscription (people turn push on again).
 */
class VapidKeysCommand extends Command
{
    protected $signature = 'ehail:comms-vapid-keys';

    protected $description = 'Generate a VAPID key pair for Web Push (prints the .env lines)';

    public function handle(): int
    {
        $keys = EcKeys::generate();

        $this->line('EHAIL_VAPID_PUBLIC_KEY='.Base64Url::encode($keys['public']));
        $this->line('EHAIL_VAPID_PRIVATE_KEY='.Base64Url::encode($keys['private']));
        $this->line('EHAIL_VAPID_SUBJECT=mailto:no-reply@infodot.co.za');
        $this->newLine();
        $this->comment('Add these to the host .env (never commit them). Changing keys later means every device must turn push on again.');

        return self::SUCCESS;
    }
}
