<?php

namespace App\Domain\Pricing\Models\Concerns;

use App\Domain\Pricing\Exceptions\ImmutablePricingRecord;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Draft -> published -> retired, with published and retired rows frozen: the only change a published row accepts
 * is being retired (status + retired_at). Editing a published tariff means drafting version n + 1 of the same
 * lineage. Quotes, ride fares and audits refer to (id, version), so history always re-prices exactly.
 *
 * @property string $status
 * @property string $lineage_key
 * @property int $version
 * @property ?CarbonInterface $effective_from
 * @property ?CarbonInterface $retired_at
 */
trait VersionedOnPublish
{
    public static function bootVersionedOnPublish(): void
    {
        static::updating(function (self $model): void {
            $originalStatus = $model->getOriginal('status');

            if ($originalStatus === 'draft') {
                return;
            }

            $changed = array_keys($model->getDirty());
            $allowed = ['status', 'retired_at', 'updated_at'];
            $retiring = $originalStatus === 'published' && $model->status === 'retired';
            $movingRetirement = $originalStatus === 'published' && $model->status === 'published' && $changed !== [] && array_diff($changed, ['retired_at', 'updated_at']) === [];

            if (array_diff($changed, $allowed) !== [] || ! ($retiring || $movingRetirement)) {
                throw new ImmutablePricingRecord(sprintf('%s #%d v%d is %s and cannot be changed. Draft a new version instead.', class_basename($model), $model->id, $model->version, $originalStatus));
            }
        });

        static::deleting(function (self $model): void {
            if ($model->getOriginal('status') !== 'draft') {
                throw new ImmutablePricingRecord('Only draft versions can be deleted; retire published versions instead.');
            }
        });
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    /**
     * Versions that were in force at $at: published or retired (never draft), effective_from <= at and not yet
     * retired at that moment.
     */
    public function scopeInForceAt(Builder $query, CarbonInterface $at): Builder
    {
        return $query->whereIn('status', ['published', 'retired'])
            ->whereNotNull('effective_from')
            ->where('effective_from', '<=', $at)
            ->where(fn (Builder $q) => $q->whereNull('retired_at')->orWhere('retired_at', '>', $at));
    }
}
