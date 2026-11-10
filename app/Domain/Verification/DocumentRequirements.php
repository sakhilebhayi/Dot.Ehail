<?php

namespace App\Domain\Verification;

use InvalidArgumentException;

/**
 * The configured document types per market (config/ehail_safety.php markets.*.document_types)
 * and what a given vehicle category requires.
 */
class DocumentRequirements
{
    public function defaultMarket(): string
    {
        return (string) config('ehail_safety.default_market', 'ZA');
    }

    /**
     * @return array<string, array{subject: string, label: string, required: bool, expiry_required: bool, kinds: list<string>, categories?: array<string, array<string, mixed>>}>
     */
    public function types(?string $market = null): array
    {
        $market ??= $this->defaultMarket();

        return (array) config("ehail_safety.markets.{$market}.document_types", []);
    }

    /**
     * @return array{subject: string, label: string, required: bool, expiry_required: bool, kinds: list<string>, categories?: array<string, array<string, mixed>>}
     */
    public function type(string $code, ?string $market = null): array
    {
        $types = $this->types($market);

        if (! isset($types[$code])) {
            throw new InvalidArgumentException("Unknown document type [{$code}].");
        }

        return $types[$code];
    }

    public function exists(string $code, ?string $market = null): bool
    {
        return isset($this->types($market)[$code]);
    }

    /**
     * Types mandatory for a subject ('driver' or 'vehicle') in a category, with the effective rule.
     *
     * @return array<string, array{label: string, expiry_required: bool, max_age_days: ?int}>
     */
    public function required(string $subject, ?string $category = null, ?string $market = null): array
    {
        $out = [];

        foreach ($this->types($market) as $code => $type) {
            if ($type['subject'] !== $subject) {
                continue;
            }

            $override = $category !== null ? (array) ($type['categories'][$category] ?? []) : [];
            $required = (bool) ($override['required'] ?? $type['required']);

            if (! $required) {
                continue;
            }

            $out[$code] = [
                'label' => $type['label'],
                'expiry_required' => (bool) $type['expiry_required'],
                'max_age_days' => isset($override['max_age_days']) ? (int) $override['max_age_days'] : null,
            ];
        }

        return $out;
    }
}
