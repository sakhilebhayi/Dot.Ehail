<?php

namespace Tests\Feature\Deploy;

use Dotenv\Dotenv;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * .env.example is what CI copies to .env and what the owner reads to configure the host, so it must list every
 * variable the product reads, work on any machine as it is, and never carry a real secret.
 */
class EnvExampleTest extends TestCase
{
    private function example(): string
    {
        return (string) file_get_contents(base_path('.env.example'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function configFiles(): array
    {
        $files = [];

        foreach (array_merge(glob(dirname(__DIR__, 3).'/config/ehail*.php') ?: [], array_map(fn ($f) => dirname(__DIR__, 3).'/config/'.$f, ['money.php', 'paystack.php', 'security.php', 'notify.php', 'analytics.php'])) as $file) {
            $files[basename($file)] = [$file];
        }

        return $files;
    }

    #[DataProvider('configFiles')]
    public function test_every_variable_the_config_reads_is_documented(string $file): void
    {
        preg_match_all("/env\\('([A-Z0-9_]+)'/", (string) file_get_contents($file), $m);
        preg_match_all('/^(?:# )?([A-Z0-9_]+)=/m', $this->example(), $listed);
        $missing = array_values(array_diff(array_unique($m[1]), $listed[1]));

        $this->assertSame([], $missing, basename($file).' reads variables .env.example does not list (add them, commented with their default if optional)');
    }

    public function test_it_is_environment_independent_and_parses(): void
    {
        $values = Dotenv::parse($this->example());

        $this->assertSame('http://localhost', $values['APP_URL']);
        $this->assertSame('', $values['APP_KEY']);
        $this->assertSame('sqlite', $values['DB_CONNECTION']);
        $this->assertSame('log', $values['BROADCAST_CONNECTION'], 'no Reverb on the shared host');
        $this->assertSame('none', $values['EHAIL_ROUTING_DRIVER'], 'no paid provider is called from a fresh checkout');
        $this->assertSame('false', $values['PAYSTACK_ALLOW_LIVE']);
        $this->assertSame('false', $values['ALLOW_REGISTRATION']);
    }

    public function test_it_carries_no_secret(): void
    {
        $values = Dotenv::parse($this->example());

        foreach (['PAYSTACK_SECRET_KEY', 'PAYSTACK_PUBLIC_KEY', 'INFODOT_SERVICE_SECRET', 'NOTIFY_INGEST_SECRET', 'ANALYTICS_INGEST_SECRET', 'REVERB_APP_SECRET', 'ANTHROPIC_API_KEY'] as $key) {
            $this->assertSame('', $values[$key] ?? '', "{$key} must be blank in .env.example");
        }

        $this->assertDoesNotMatchRegularExpression('/sk_(live|test)_[A-Za-z0-9]{6,}|pk_(live|test)_[A-Za-z0-9]{6,}|AIza[0-9A-Za-z_-]{20,}/', $this->example());
    }
}
