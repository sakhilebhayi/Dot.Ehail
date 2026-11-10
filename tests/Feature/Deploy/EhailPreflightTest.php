<?php

namespace Tests\Feature\Deploy;

use App\Console\Commands\EhailPreflight;
use App\Domain\Geo\Models\ServiceArea;
use App\Domain\Pricing\Models\PricingRuleSet;
use App\Models\DriverProfile;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\Concerns\ApprovesDriverDocuments;
use Tests\TestCase;

/**
 * ehail:preflight, the check deploy.yml runs before the site leaves maintenance mode: a FAIL keeps it down.
 */
class EhailPreflightTest extends TestCase
{
    use ApprovesDriverDocuments;
    use RefreshDatabase;

    private ?string $publicCopy = null;

    protected function tearDown(): void
    {
        if ($this->publicCopy !== null) {
            File::deleteDirectory($this->publicCopy);
        }

        parent::tearDown();
    }

    /**
     * @return array{exit: int, ok: bool, counts: array<string, int>, rows: array<string, array{status: string, detail: string}>}
     */
    private function preflight(array $options = []): array
    {
        $exit = Artisan::call('ehail:preflight', ['--json' => true] + $options);
        $data = json_decode(Artisan::output(), true);
        $this->assertIsArray($data, 'preflight --json printed JSON');

        $rows = [];

        foreach ($data['results'] as $row) {
            $rows[$row['area'].'/'.$row['check']] = ['status' => $row['status'], 'detail' => $row['detail']];
        }

        return ['exit' => $exit, 'ok' => $data['ok'], 'counts' => $data['counts'], 'rows' => $rows];
    }

    /** A copy of public/ with a built manifest that names every @vite entry, so the frontend rows can pass. */
    private function usePublicWithBuild(): void
    {
        $dir = sys_get_temp_dir().'/ehail-preflight-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($dir.'/build/assets');

        foreach (['manifest.webmanifest', 'sw.js', 'offline.html', 'robots.txt'] as $file) {
            File::copy(public_path($file), $dir.'/'.$file);
        }

        File::copyDirectory(public_path('fonts'), $dir.'/fonts');
        File::copyDirectory(public_path('icons'), $dir.'/icons');

        $manifest = [];

        foreach (EhailPreflight::viteEntriesInViews() as $i => $entry) {
            $file = "assets/entry-{$i}.js";
            File::put($dir.'/build/'.$file, '/* built */');
            $manifest[$entry] = ['file' => $file, 'isEntry' => true, 'src' => $entry];
        }

        File::put($dir.'/build/manifest.json', json_encode($manifest));
        $this->publicCopy = $dir;
        $this->app->usePublicPath($dir);
    }

    private function bookableConfiguration(): void
    {
        config([
            'ehail-geo.routing.driver' => 'osrm',
            'ehail-geo.providers.osrm.url' => 'https://osrm.example.test',
            'paystack.secret_key' => 'sk_test_preflight',
            'paystack.public_key' => 'pk_test_preflight',
            'mail.default' => 'smtp',
        ]);
        Artisan::call('ehail:geo-reference-areas');
        Artisan::call('ehail:pricing:install-example-tariff');
    }

    public function test_limits_are_judged_against_the_largest_driver_document(): void
    {
        $ten = 10 * 1024 * 1024;
        $byCheck = fn (array $rows) => collect($rows)->keyBy('check')->map(fn ($r) => $r['status'])->all();

        $poor = $byCheck(EhailPreflight::evaluateLimits(['upload_max_filesize' => '2M', 'post_max_size' => '512K', 'memory_limit' => '32M', 'max_execution_time' => '10'], $ten));
        $this->assertSame(['upload_max_filesize' => 'WARN', 'post_max_size' => 'FAIL', 'memory_limit' => 'FAIL', 'max_execution_time' => 'WARN'], $poor);

        $typical = $byCheck(EhailPreflight::evaluateLimits(['upload_max_filesize' => '8M', 'post_max_size' => '8M', 'memory_limit' => '128M', 'max_execution_time' => '30'], $ten));
        $this->assertSame('WARN', $typical['upload_max_filesize']);
        $this->assertSame('WARN', $typical['post_max_size']);
        $this->assertSame('WARN', $typical['memory_limit']);

        $good = $byCheck(EhailPreflight::evaluateLimits(['upload_max_filesize' => '12M', 'post_max_size' => '16M', 'memory_limit' => '256M', 'max_execution_time' => '0'], $ten));
        $this->assertSame(['PASS'], array_values(array_unique($good)));

        $this->assertNull(EhailPreflight::bytes('-1'));
        $this->assertSame(512 * 1024, EhailPreflight::bytes('512K'));
        $this->assertSame(2 * 1024 ** 3, EhailPreflight::bytes('2G'));
        $this->assertSame(10240 * 1024, EhailPreflight::largestUploadBytes());
    }

    public function test_a_bookable_configuration_passes_and_says_a_passenger_can_book(): void
    {
        $this->usePublicWithBuild();
        $this->bookableConfiguration();

        $result = $this->preflight();

        $this->assertSame(0, $result['exit'], json_encode(array_filter($result['rows'], fn ($r) => $r['status'] === 'FAIL')));
        $this->assertTrue($result['ok']);
        $this->assertSame('PASS', $result['rows']['Readiness/Can a passenger book?']['status']);
        $this->assertSame('PASS', $result['rows']['Providers/Routing']['status']);
        $this->assertSame('PASS', $result['rows']['Frontend/Built assets']['status']);
        $this->assertSame('PASS', $result['rows']['Frontend/Installable app (PWA)']['status']);
        $this->assertSame('PASS', $result['rows']['Schedule/Registered tasks']['status']);
        $this->assertSame('PASS', $result['rows']['Database/Migrations']['status']);
        $this->assertSame('PASS', $result['rows']['Storage/Driver documents']['status']);
        $this->assertStringContainsString('/webhooks/paystack', $result['rows']['Providers/Card payments (Paystack)']['detail']);
    }

    public function test_routing_off_is_the_loud_warning_and_blocks_bookings(): void
    {
        $this->bookableConfiguration();
        config(['ehail-geo.routing.driver' => 'none']);

        $result = $this->preflight();

        $this->assertSame('WARN', $result['rows']['Providers/Routing']['status']);
        $this->assertStringContainsString('Nobody can book', $result['rows']['Providers/Routing']['detail']);
        $this->assertSame('WARN', $result['rows']['Readiness/Can a passenger book?']['status']);
        $this->assertStringContainsString('no routing provider', $result['rows']['Readiness/Can a passenger book?']['detail']);
    }

    public function test_no_tariff_no_area_and_no_way_to_pay_are_each_named(): void
    {
        config(['ehail-geo.routing.driver' => 'osrm', 'ehail-geo.providers.osrm.url' => 'https://osrm.example.test', 'paystack.secret_key' => '', 'money.cash.enabled' => false]);

        $result = $this->preflight();
        $readiness = $result['rows']['Readiness/Can a passenger book?']['detail'];

        $this->assertSame('WARN', $result['rows']['Reference data/Tariff']['status']);
        $this->assertSame('WARN', $result['rows']['Reference data/Service areas']['status']);
        $this->assertSame('WARN', $result['rows']['Providers/Way to pay']['status']);
        $this->assertStringContainsString('no published tariff', $readiness);
        $this->assertStringContainsString('no active service area', $readiness);
        $this->assertStringContainsString('no way to pay', $readiness);

        config(['money.cash.enabled' => true]);
        $this->assertArrayNotHasKey('Providers/Way to pay', $this->preflight()['rows']);
    }

    public function test_the_example_tariff_warns_in_production_and_a_reviewed_one_passes(): void
    {
        $this->bookableConfiguration();
        $this->app['env'] = 'production';

        $rows = $this->preflight()['rows'];
        $this->assertSame('WARN', $rows['Reference data/Tariff']['status']);
        $this->assertStringContainsString('example tariff', $rows['Reference data/Tariff']['detail']);
        $this->assertSame('WARN', $rows['Reference data/Service areas']['status'], 'the reference areas are approximate');

        PricingRuleSet::query()->update(['is_example' => false]);
        ServiceArea::query()->update(['is_approximate' => false]);

        $rows = $this->preflight()['rows'];
        $this->assertSame('PASS', $rows['Reference data/Tariff']['status']);
        $this->assertSame('PASS', $rows['Reference data/Service areas']['status']);
    }

    public function test_paystack_modes_are_reported_honestly(): void
    {
        $this->app['env'] = 'production';

        config(['paystack.secret_key' => 'sk_test_x']);
        $this->assertSame('WARN', $this->preflight()['rows']['Providers/Card payments (Paystack)']['status']);

        config(['paystack.secret_key' => 'sk_live_x', 'paystack.allow_live' => false]);
        $result = $this->preflight();
        $this->assertSame('FAIL', $result['rows']['Providers/Card payments (Paystack)']['status']);
        $this->assertStringContainsString('PAYSTACK_ALLOW_LIVE', $result['rows']['Providers/Card payments (Paystack)']['detail']);
        $this->assertSame(1, $result['exit']);

        config(['paystack.allow_live' => true]);
        $this->assertSame('PASS', $this->preflight()['rows']['Providers/Card payments (Paystack)']['status']);

        config(['money.payouts.paystack_transfers' => true, 'paystack.secret_key' => '']);
        $this->assertSame('FAIL', $this->preflight()['rows']['Providers/Payout transfers']['status']);
    }

    public function test_half_configured_providers_fail_and_unconfigured_ones_are_off(): void
    {
        $rows = $this->preflight()['rows'];
        $this->assertSame('OFF', $rows['Providers/SMS']['status']);
        $this->assertSame('OFF', $rows['Providers/Web Push']['status']);
        $this->assertSame('OFF', $rows['Providers/Dot.Notify email events']['status']);

        config(['ehail_safety.sms.driver' => 'bulksms', 'ehail_safety.sms.bulksms.token_id' => '', 'ehail_safety.sms.bulksms.token_secret' => '']);
        config(['ehail_comms.push.public_key' => 'not-a-key', 'ehail_comms.push.private_key' => '']);
        config(['notify.url' => 'https://notify.example.test/api/ingest/events', 'notify.secret' => '']);

        $result = $this->preflight();
        $this->assertSame('FAIL', $result['rows']['Providers/SMS']['status']);
        $this->assertSame('FAIL', $result['rows']['Providers/Web Push']['status']);
        $this->assertSame('WARN', $result['rows']['Providers/Dot.Notify email events']['status']);
        $this->assertSame(1, $result['exit']);
    }

    public function test_production_debug_and_a_local_url_fail(): void
    {
        $this->app['env'] = 'production';
        config(['app.debug' => true, 'app.url' => 'http://localhost']);

        $result = $this->preflight();

        $this->assertSame('FAIL', $result['rows']['Application/APP_DEBUG']['status']);
        $this->assertSame('FAIL', $result['rows']['Application/APP_URL']['status']);
        $this->assertSame(1, $result['exit']);
        $this->assertFalse($result['ok']);
    }

    public function test_strict_turns_warnings_into_a_failing_exit(): void
    {
        $this->usePublicWithBuild();
        $this->bookableConfiguration();
        config(['ehail-geo.routing.driver' => 'none']);

        $this->assertSame(0, $this->preflight()['exit']);
        $this->assertSame(1, $this->preflight(['--strict' => true])['exit']);
    }

    public function test_a_public_folder_without_the_service_worker_or_a_precached_file_fails(): void
    {
        $dir = sys_get_temp_dir().'/ehail-pwa-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($dir.'/icons');
        File::copy(public_path('manifest.webmanifest'), $dir.'/manifest.webmanifest');

        try {
            $row = EhailPreflight::pwaRows($dir)[0];
            $this->assertSame('FAIL', $row['status']);
            $this->assertStringContainsString('sw.js is missing', $row['detail']);
            $this->assertStringContainsString('icon-192.png is missing', $row['detail']);

            File::put($dir.'/sw.js', "const PRECACHE = [\n    '/offline.html',\n];");
            $this->assertStringContainsString('precaches /offline.html', EhailPreflight::pwaRows($dir)[0]['detail']);

            $this->assertSame('PASS', EhailPreflight::pwaRows(public_path())[0]['status']);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_verification_impact_counts_the_drivers_who_may_go_online(): void
    {
        $blocked = DriverProfile::factory()->create(['status' => 'approved']);
        Vehicle::factory()->create(['driver_profile_id' => $blocked->id, 'is_active' => true]);

        $ready = DriverProfile::factory()->create(['status' => 'approved']);
        $vehicle = Vehicle::factory()->create(['driver_profile_id' => $ready->id, 'is_active' => true]);
        $this->approveAllDocuments($ready, $vehicle);

        DriverProfile::factory()->create(['status' => 'pending']);

        $row = $this->preflight()['rows']['Drivers/Verification'];

        $this->assertSame('INFO', $row['status']);
        $this->assertStringContainsString('2 approved driver(s): 1 may go online now, 1 are blocked', $row['detail']);
        $this->assertStringContainsString('document_missing', $row['detail']);
    }

    public function test_operators_are_counted_and_the_operator_command_grants_and_revokes(): void
    {
        $this->app['env'] = 'production';
        $this->assertSame('WARN', $this->preflight()['rows']['Reference data/Operators']['status']);

        $user = User::factory()->create(['email' => 'Owner@Example.test']);

        $this->artisan('ehail:operator', ['email' => 'nobody@example.test'])->assertFailed();
        $this->artisan('ehail:operator', ['email' => 'owner@example.test'])->assertSuccessful();
        $this->assertTrue($user->fresh()->is_platform_operator);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ops.operator_granted', 'subject_id' => $user->id]);
        $this->assertSame('PASS', $this->preflight()['rows']['Reference data/Operators']['status']);

        $this->artisan('ehail:operator', ['email' => 'owner@example.test', '--revoke' => true])->assertSuccessful();
        $this->assertFalse($user->fresh()->is_platform_operator);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ops.operator_revoked', 'subject_id' => $user->id]);
    }

    public function test_every_scheduled_task_the_product_needs_is_registered(): void
    {
        $row = $this->preflight()['rows']['Schedule/Registered tasks'];

        $this->assertSame('PASS', $row['status'], $row['detail']);
        $this->assertStringContainsString(count(EhailPreflight::EXPECTED_SCHEDULE).' tasks', $row['detail']);
    }
}
