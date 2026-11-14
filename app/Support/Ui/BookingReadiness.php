<?php

namespace App\Support\Ui;

use App\Domain\Catalog\Models\RideCategory;
use App\Domain\Geo\Models\ServiceArea;
use App\Domain\Geo\Routing\RoutePlanner;
use App\Domain\Pricing\Models\PricingRuleSet;
use App\Services\Payments\Paystack\PaystackConfig;
use Throwable;

/**
 * Can a passenger book right now? A read-only answer for the signed-in landing, from the same five facts
 * `php artisan ehail:preflight` reports under "Can a passenger book?": a road-routing provider, a way to pay, an
 * active service area, an active ride category and a published tariff in force. It changes nothing and decides
 * nothing: the booking flow keeps its own checks. A check that cannot be read counts as not ready, so the landing
 * never offers a booking the platform would refuse.
 */
final class BookingReadiness
{
    /** @var list<array{key: string, label: string, ready: bool, missing: string, route: ?string}>|null */
    private ?array $steps = null;

    /**
     * The setup line, in the order an owner works through it. `missing` says in plain words what to do; `route` is
     * the operations screen where it is done, or null when it is set in the host's environment file.
     *
     * @return list<array{key: string, label: string, ready: bool, missing: string, route: ?string}>
     */
    public function steps(): array
    {
        return $this->steps ??= [
            [
                'key' => 'routing',
                'label' => 'Road routing',
                'ready' => $this->check(fn () => app(RoutePlanner::class)->isConfigured()),
                'missing' => 'No routing provider is set, so no trip can be priced. Add a provider key to the host environment (EHAIL_ROUTING_DRIVER).',
                'route' => null,
            ],
            [
                'key' => 'payment',
                'label' => 'A way to pay',
                'ready' => $this->check(fn () => app(PaystackConfig::class)->canTakePayments() || (bool) config('money.cash.enabled')),
                'missing' => 'Card payments are not connected and cash is off, so no fare can be collected. Add the Paystack keys or switch cash on in the host environment.',
                'route' => null,
            ],
            [
                'key' => 'area',
                'label' => 'A service area',
                'ready' => $this->check(fn () => ServiceArea::query()->where('is_active', true)->exists()),
                'missing' => 'No service area is active, so no pickup is inside a served area. Draw or switch one on under Areas.',
                'route' => 'ops.zones',
            ],
            [
                'key' => 'category',
                'label' => 'A ride category',
                'ready' => $this->check(fn () => RideCategory::query()->where('is_active', true)->exists()),
                'missing' => 'Every ride category is switched off. Switch one on in the tariff catalogue.',
                'route' => 'ops.pricing.catalogue',
            ],
            [
                'key' => 'tariff',
                'label' => 'A published tariff',
                'ready' => $this->check(fn () => PricingRuleSet::query()
                    ->where('status', 'published')
                    ->whereNull('retired_at')
                    ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', now()))
                    ->exists()),
                'missing' => 'No tariff is published, so there are no prices. Publish one under Tariffs.',
                'route' => 'ops.pricing',
            ],
        ];
    }

    public function isOpen(): bool
    {
        return $this->missing() === [];
    }

    /** @return list<array{key: string, label: string, ready: bool, missing: string, route: ?string}> */
    public function missing(): array
    {
        return array_values(array_filter($this->steps(), fn (array $step) => ! $step['ready']));
    }

    /** @param  callable(): bool  $fact */
    private function check(callable $fact): bool
    {
        try {
            return (bool) $fact();
        } catch (Throwable) {
            return false;
        }
    }
}
