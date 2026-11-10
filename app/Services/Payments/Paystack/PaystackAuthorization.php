<?php

namespace App\Services\Payments\Paystack;

/**
 * The `authorization` block Paystack returns with a successful card charge (verify, charge_authorization,
 * charge.success). Only what is needed to charge again and to show the card is read; `bin` and
 * `account_name` are dropped. authorization_code is a token usable only with our secret key.
 */
final readonly class PaystackAuthorization
{
    public function __construct(
        public string $authorizationCode,
        public ?string $cardType,
        public ?string $brand,
        public ?string $last4,
        public ?string $expMonth,
        public ?string $expYear,
        public ?string $bank,
        public ?string $countryCode,
        public ?string $channel,
        public ?string $signature,
        public bool $reusable,
    ) {}

    /**
     * @param  mixed  $data  the authorization object, or anything else (null result)
     */
    public static function fromData(mixed $data): ?self
    {
        if (! is_array($data) || ! is_string($data['authorization_code'] ?? null) || $data['authorization_code'] === '') {
            return null;
        }

        $str = fn (string $key, int $max): ?string => is_scalar($data[$key] ?? null) && (string) $data[$key] !== ''
            ? mb_substr(trim((string) $data[$key]), 0, $max) : null;

        return new self(
            authorizationCode: mb_substr($data['authorization_code'], 0, 120),
            cardType: $str('card_type', 30),
            brand: $str('brand', 30) ?? $str('card_type', 30),
            last4: is_string($data['last4'] ?? null) && preg_match('/^\d{4}$/', $data['last4']) ? $data['last4'] : null,
            expMonth: $str('exp_month', 2),
            expYear: $str('exp_year', 4),
            bank: $str('bank', 80),
            countryCode: is_string($data['country_code'] ?? null) ? strtoupper(mb_substr($data['country_code'], 0, 2)) : null,
            channel: $str('channel', 30),
            signature: $str('signature', 120),
            reusable: ($data['reusable'] ?? false) === true,
        );
    }
}
