<?php

namespace App\Domain\Pricing\Admin;

use App\Domain\Pricing\Money\Currency;
use App\Domain\Pricing\Money\RoundingMode;
use App\Domain\Pricing\Support\TimeWindows;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Validation for rule-set terms (used by the admin API and again at publish time, so a draft edited by any route
 * can never be published broken). Amount ceilings stop typos (an extra zero) from reaching passengers.
 */
final class RuleSetValidator
{
    public const MAX_MINOR = 10_000_000;

    /**
     * @param  array<string, mixed>  $terms
     * @return array<string, mixed> the validated terms
     *
     * @throws ValidationException
     */
    public static function validate(array $terms, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';
        $money = [$required, 'integer', 'min:0', 'max:'.self::MAX_MINOR];

        $validator = Validator::make($terms, [
            'name' => [$required, 'string', 'max:120'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'country' => [$required, 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],
            'currency' => [$required, 'string', 'size:3', Rule::in(array_keys((array) config('ehail_pricing.currencies')))],
            'service_area_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'zone_slug' => ['sometimes', 'nullable', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9_-]*$/'],
            'ride_category_id' => ['sometimes', 'nullable', 'integer', Rule::exists('ride_categories', 'id')],
            'time_windows' => ['sometimes', 'nullable', 'array'],
            'timezone' => [$required, 'timezone:all'],
            'base_minor' => $money,
            'per_km_minor' => $money,
            'per_minute_minor' => $money,
            'minimum_minor' => $money,
            'booking_fee_minor' => $money,
            'wait_free_minutes' => [$required, 'integer', 'min:0', 'max:60'],
            'wait_per_minute_minor' => $money,
            'cancel_free_minutes' => [$required, 'integer', 'min:0', 'max:60'],
            'cancel_fee_minor' => $money,
            'no_show_fee_minor' => $money,
            'zone_fees' => ['sometimes', 'nullable', 'array'],
            'zone_fees.*.pickup_minor' => ['sometimes', 'integer', 'min:0', 'max:'.self::MAX_MINOR],
            'zone_fees.*.dropoff_minor' => ['sometimes', 'integer', 'min:0', 'max:'.self::MAX_MINOR],
            'zone_fees.*.label' => ['required_with:zone_fees', 'string', 'max:60'],
            'toll_mode' => [$required, Rule::in(['none', 'pass_through'])],
            'demand_enabled' => [$required, 'boolean'],
            'demand_cap_bp' => [$required, 'integer', 'min:10000', 'max:30000'],
            'tax_mode' => [$required, Rule::in(['none', 'inclusive', 'exclusive'])],
            'tax_rate_bp' => [$required, 'integer', 'min:0', 'max:5000'],
            'tax_label' => ['sometimes', 'nullable', 'string', 'max:32'],
            'rounding_unit_minor' => [$required, 'integer', Rule::in([1, 5, 10, 50, 100, 500, 1000])],
            'rounding_mode' => [$required, Rule::in(array_map(fn (RoundingMode $m) => $m->value, RoundingMode::cases()))],
            'distance_tolerance_bp' => [$required, 'integer', 'min:0', 'max:10000'],
            'duration_tolerance_bp' => [$required, 'integer', 'min:0', 'max:10000'],
            'overrun_cap_bp' => [$required, 'integer', 'min:0', 'max:10000'],
        ]);

        $validator->after(function ($v) use ($terms) {
            foreach (TimeWindows::validate($terms['time_windows'] ?? null) as $error) {
                $v->errors()->add('time_windows', $error);
            }

            if (isset($terms['zone_fees']) && is_array($terms['zone_fees'])) {
                foreach (array_keys($terms['zone_fees']) as $code) {
                    if (! is_string($code) || preg_match('/^[a-z0-9_]{1,64}$/', $code) !== 1) {
                        $v->errors()->add('zone_fees', 'Zone fee keys are zone fee codes (a-z, 0-9, underscore).');
                    }
                }
            }

            if (($terms['tax_mode'] ?? 'none') !== 'none' && ((int) ($terms['tax_rate_bp'] ?? 0) <= 0 || trim((string) ($terms['tax_label'] ?? '')) === '')) {
                $v->errors()->add('tax_rate_bp', 'A tax mode other than "none" needs a rate above zero and a label (e.g. VAT).');
            }

            if (isset($terms['currency']) && is_string($terms['currency']) && ! Currency::isSupported($terms['currency'])) {
                $v->errors()->add('currency', 'Unsupported currency.');
            }
        });

        return $validator->validate();
    }
}
