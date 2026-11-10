<?php

namespace Tests\Feature\Ops;

use App\Domain\Verification\Models\VerificationDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\OpsFixtures;
use Tests\TestCase;

/** Every operations page renders with real data for a full-access operator (smoke test of each view). */
class OpsPagesTest extends TestCase
{
    use OpsFixtures;
    use RefreshDatabase;

    public function test_every_page_renders_for_an_administrator(): void
    {
        $w = $this->seedOpsWorld();
        $admin = $this->operatorWith(['admin']);
        $doc = VerificationDocument::query()->create([
            'user_id' => $w['driver']->id, 'driver_profile_id' => $w['profile']->id, 'market' => 'ZA', 'subject' => 'driver', 'type' => 'drivers_licence',
            'status' => 'pending', 'disk' => 'local', 'file_path' => 'x/y.jpg', 'original_filename' => 'licence.jpg', 'mime' => 'image/jpeg', 'size_bytes' => 1000, 'sha256' => str_repeat('a', 64), 'submitted_at' => now(),
        ]);

        $pages = [
            route('ops.live'), route('ops.live.feed'), route('ops.rides'), route('ops.rides', ['view' => 'all']), route('ops.rides.show', $w['searching']->id),
            route('ops.rides.show', $w['completed']->id), route('ops.rides.show', $w['review']->id),
            route('ops.drivers'), route('ops.drivers.show', $w['driver']->id), route('ops.verification'), route('ops.verification', ['subject' => 'vehicle']),
            route('ops.verification', ['subject' => 'applications']), route('ops.verification.expiry'), route('ops.verification.show', $doc->id),
            route('ops.safety'), route('ops.safety.show', $w['incident']->id), route('ops.safety.check-ins'), route('ops.safety.patterns'), route('ops.safety.reports'), route('ops.safety.access'),
            route('ops.support'), route('ops.support', ['queue' => 'lost_property']), route('ops.support.show', $w['case']->id),
            route('ops.pricing'), route('ops.pricing.simulator'), route('ops.pricing.promotions'), route('ops.pricing.catalogue'), route('ops.pricing.commission'), route('ops.zones'),
            route('ops.finance'), route('ops.finance.payment', $w['payment']->id), route('ops.finance.payouts'), route('ops.finance.reconciliation'), route('ops.finance.ledger'),
            route('ops.insights'), route('ops.insights', ['grain' => 'month']), route('ops.audit'), route('ops.roles'), route('ops.fleets'),
        ];

        foreach ($pages as $url) {
            $response = $this->actingAs($admin)->get($url);
            $this->assertSame(200, $response->status(), $url."\n".mb_substr((string) $response->getContent(), 0, 3000));
        }
    }
}
