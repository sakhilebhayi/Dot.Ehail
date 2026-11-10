<?php

namespace App\Domain\Pricing\Admin;

use App\Domain\Pricing\Exceptions\ImmutablePricingRecord;
use App\Domain\Pricing\Models\CommissionPlan;
use App\Domain\Pricing\Models\PricingRuleSet;
use App\Models\AuditLog;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The operator's tariff and commission lifecycle: draft -> publish (new versions supersede old ones at their
 * effective time) -> retire. Published rows are never edited; "editing" drafts version n + 1. Every step is
 * audited with the full terms, so any past price can be explained.
 */
final class PricingAdmin
{
    /**
     * @param  array<string, mixed>  $terms
     */
    public function draftRuleSet(array $terms, User $actor): PricingRuleSet
    {
        $terms = array_merge((array) config('ehail_pricing.rule_set_defaults'), [
            'country' => config('ehail_pricing.default_country'),
            'currency' => config('ehail_pricing.default_currency'),
            'timezone' => config('ehail_pricing.default_timezone'),
            'base_minor' => 0, 'per_km_minor' => 0, 'per_minute_minor' => 0, 'minimum_minor' => 0, 'booking_fee_minor' => 0,
            'wait_per_minute_minor' => 0, 'cancel_fee_minor' => 0, 'no_show_fee_minor' => 0, 'demand_enabled' => false, 'tax_rate_bp' => 0,
        ], $terms);
        $valid = RuleSetValidator::validate(array_intersect_key($terms, array_flip(PricingRuleSet::TERMS)));

        $rules = PricingRuleSet::query()->create($valid + [
            'lineage_key' => (string) Str::ulid(),
            'version' => 1,
            'status' => 'draft',
            'created_by' => $actor->id,
        ]);

        AuditLog::record('pricing.rule_set_drafted', $actor, $rules, ['lineage_key' => $rules->lineage_key, 'version' => 1]);

        return $rules;
    }

    /**
     * @param  array<string, mixed>  $terms
     */
    public function updateDraft(PricingRuleSet $rules, array $terms, User $actor): PricingRuleSet
    {
        if (! $rules->isDraft()) {
            throw new ImmutablePricingRecord('Only drafts can be edited. Create a new version of a published tariff.');
        }

        $merged = array_merge($rules->only(PricingRuleSet::TERMS), array_intersect_key($terms, array_flip(PricingRuleSet::TERMS)));
        $valid = RuleSetValidator::validate($merged);
        $rules->fill($valid)->save();

        AuditLog::record('pricing.rule_set_draft_updated', $actor, $rules, ['changed' => array_keys(array_intersect_key($terms, array_flip(PricingRuleSet::TERMS)))]);

        return $rules->fresh();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function newRuleSetVersion(PricingRuleSet $source, User $actor, array $overrides = []): PricingRuleSet
    {
        return DB::transaction(function () use ($source, $actor, $overrides) {
            $next = (int) PricingRuleSet::query()->where('lineage_key', $source->lineage_key)->lockForUpdate()->pluck('version')->max() + 1;
            $terms = array_merge($source->only(PricingRuleSet::TERMS), array_intersect_key($overrides, array_flip(PricingRuleSet::TERMS)));
            $valid = RuleSetValidator::validate($terms);

            $draft = PricingRuleSet::query()->create($valid + [
                'lineage_key' => $source->lineage_key,
                'version' => $next,
                'status' => 'draft',
                // A copy of an example tariff is still example data until someone deliberately changes that.
                'is_example' => $source->is_example && $overrides === [],
                'created_by' => $actor->id,
            ]);

            AuditLog::record('pricing.rule_set_version_drafted', $actor, $draft, ['from_id' => $source->id, 'from_version' => $source->version, 'version' => $next]);

            return $draft;
        });
    }

    public function publishRuleSet(PricingRuleSet $draft, User $actor, ?CarbonInterface $effectiveFrom = null): PricingRuleSet
    {
        RuleSetValidator::validate($draft->only(PricingRuleSet::TERMS));

        return $this->publish($draft, $actor, $effectiveFrom, 'pricing.rule_set_published', $draft->only(PricingRuleSet::TERMS));
    }

    public function retire(PricingRuleSet|CommissionPlan $record, User $actor, ?CarbonInterface $at = null): PricingRuleSet|CommissionPlan
    {
        if ($record->status !== 'published') {
            throw new ImmutablePricingRecord('Only a published version can be retired.');
        }

        $at = $at === null || $at->lessThan(now()) ? now() : $at;
        $record->forceFill(['status' => 'retired', 'retired_at' => $at])->save();

        AuditLog::record($record instanceof PricingRuleSet ? 'pricing.rule_set_retired' : 'pricing.commission_plan_retired', $actor, $record, ['version' => $record->version, 'retired_at' => $at->toIso8601String()]);

        return $record;
    }

    /**
     * @param  array<string, mixed>  $terms
     */
    public function draftCommissionPlan(array $terms, User $actor, ?CommissionPlan $from = null): CommissionPlan
    {
        $merged = array_merge(
            ['country' => config('ehail_pricing.default_country'), 'currency' => config('ehail_pricing.default_currency'), 'fixed_minor' => 0],
            $from?->only(CommissionPlan::TERMS) ?? [],
            array_intersect_key($terms, array_flip(CommissionPlan::TERMS)),
        );
        $valid = self::validateCommission($merged);

        return DB::transaction(function () use ($valid, $actor, $from) {
            $lineage = $from?->lineage_key ?? (string) Str::ulid();
            $version = $from === null ? 1 : (int) CommissionPlan::query()->where('lineage_key', $lineage)->lockForUpdate()->pluck('version')->max() + 1;
            $plan = CommissionPlan::query()->create($valid + ['lineage_key' => $lineage, 'version' => $version, 'status' => 'draft', 'created_by' => $actor->id]);
            AuditLog::record('pricing.commission_plan_drafted', $actor, $plan, ['lineage_key' => $lineage, 'version' => $version]);

            return $plan;
        });
    }

    public function publishCommissionPlan(CommissionPlan $draft, User $actor, ?CarbonInterface $effectiveFrom = null): CommissionPlan
    {
        self::validateCommission($draft->only(CommissionPlan::TERMS));

        return $this->publish($draft, $actor, $effectiveFrom, 'pricing.commission_plan_published', $draft->only(CommissionPlan::TERMS));
    }

    /**
     * @param  array<string, mixed>  $terms
     * @return array<string, mixed>
     */
    public static function validateCommission(array $terms): array
    {
        return Validator::make($terms, [
            'name' => ['required', 'string', 'max:120'],
            'country' => ['required', 'string', 'regex:/^[A-Z]{2}$/'],
            'currency' => ['required', Rule::in(array_keys((array) config('ehail_pricing.currencies')))],
            'service_area_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'ride_category_id' => ['sometimes', 'nullable', 'integer', Rule::exists('ride_categories', 'id')],
            'fleet_id' => ['sometimes', 'nullable', 'integer', Rule::exists('fleets', 'id')],
            'percent_bp' => ['required', 'integer', 'min:0', 'max:5000'],
            'fixed_minor' => ['required', 'integer', 'min:0', 'max:'.RuleSetValidator::MAX_MINOR],
            'min_commission_minor' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:'.RuleSetValidator::MAX_MINOR],
            'max_commission_minor' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:'.RuleSetValidator::MAX_MINOR, 'gte:min_commission_minor'],
            'commissionable_codes' => ['sometimes', 'nullable', 'array'],
            'commissionable_codes.*' => ['string', Rule::in(['base', 'distance', 'time', 'waiting', 'minimum_adjustment', 'demand', 'recalculation_cap', 'rounding', 'operator_adjustment', 'booking_fee', 'zone_fee', 'tolls'])],
        ])->validate();
    }

    /**
     * @param  array<string, mixed>  $terms
     */
    private function publish(PricingRuleSet|CommissionPlan $draft, User $actor, ?CarbonInterface $effectiveFrom, string $action, array $terms): PricingRuleSet|CommissionPlan
    {
        if (! $draft->isDraft()) {
            throw new ImmutablePricingRecord('Only a draft can be published.');
        }

        return DB::transaction(function () use ($draft, $actor, $effectiveFrom, $action, $terms) {
            $effective = $effectiveFrom === null || $effectiveFrom->lessThan(now()) ? now() : $effectiveFrom;
            /** @var class-string<Model> $class */
            $class = $draft::class;

            // Earlier live versions of the same tariff stop when this one starts.
            $class::query()->where('lineage_key', $draft->lineage_key)->where('status', 'published')->whereNull('retired_at')->lockForUpdate()->get()
                ->each(fn ($previous) => $previous->forceFill(['status' => 'retired', 'retired_at' => $effective])->save());

            $draft->forceFill(['status' => 'published', 'effective_from' => $effective, 'published_at' => now(), 'published_by' => $actor->id])->save();

            AuditLog::record($action, $actor, $draft, ['version' => $draft->version, 'lineage_key' => $draft->lineage_key, 'effective_from' => $effective->toIso8601String(), 'is_example' => $draft->is_example, 'terms' => $terms]);

            return $draft;
        });
    }
}
