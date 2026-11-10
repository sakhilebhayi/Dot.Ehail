<?php

namespace App\Domain\Payments;

/**
 * The operating company on money documents (receipts, business statements), read from config/money.php
 * ("operator", "vat", "receipts") and never invented (D-1905).
 *
 * - forDocument(): only the values that are configured, frozen into a receipt or statement snapshot. A document
 *   for a passenger or a business leaves a missing line off; it never prints a placeholder.
 * - missing(): what operations still has to configure (preflight lists it as "Operator details not configured").
 * - VAT: "registered" only with a VAT number; "not_registered" only when the operator stated it; otherwise
 *   "not_configured" and the document makes no VAT statement at all.
 * - support email: a no-reply address is refused (people cannot write to it).
 */
final class OperatorIdentity
{
    public const VAT_REGISTERED = 'registered';

    public const VAT_NOT_REGISTERED = 'not_registered';

    public const VAT_NOT_CONFIGURED = 'not_configured';

    public static function vatStatus(): string
    {
        if ((bool) config('money.vat.registered') && filled(config('money.vat.number'))) {
            return self::VAT_REGISTERED;
        }

        if ((bool) config('money.vat.declared') && ! (bool) config('money.vat.registered')) {
            return self::VAT_NOT_REGISTERED;
        }

        return self::VAT_NOT_CONFIGURED;
    }

    public static function supportEmail(): ?string
    {
        $email = trim((string) config('money.receipts.support_email'));

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL) || self::isNoReply($email)) {
            return null;
        }

        return $email;
    }

    public static function isNoReply(string $email): bool
    {
        $local = strtolower((string) strstr($email, '@', true));

        return (bool) preg_match('/^(no[-_.]?reply|do[-_.]?not[-_.]?reply|donotreply|mailer[-_.]?daemon)$/', $local);
    }

    /**
     * @return list<string>
     */
    public static function addressLines(): array
    {
        return array_values(array_filter(array_map('trim', explode('|', (string) config('money.operator.address'))), fn (string $l) => $l !== ''));
    }

    /**
     * @return array{account_name: string, bank: string, account_number: string, branch_code: string, account_type: ?string}|null
     */
    public static function eft(): ?array
    {
        $eft = array_map(fn ($v) => $v === null ? null : trim((string) $v), (array) config('money.operator.eft'));

        foreach (['account_name', 'bank', 'account_number', 'branch_code'] as $required) {
            if (blank($eft[$required] ?? null)) {
                return null;
            }
        }

        return [
            'account_name' => $eft['account_name'],
            'bank' => $eft['bank'],
            'account_number' => $eft['account_number'],
            'branch_code' => $eft['branch_code'],
            'account_type' => filled($eft['account_type'] ?? null) ? $eft['account_type'] : null,
        ];
    }

    /**
     * The configured identity for a document snapshot. Keys with no configured value are null.
     *
     * @return array<string, mixed>
     */
    public static function forDocument(): array
    {
        $vat = self::vatStatus();
        $legal = trim((string) config('money.operator.legal_name'));
        $trading = trim((string) config('money.operator.trading_name'));

        return [
            'brand' => (string) config('app.name', 'Dot.Ehail'),
            'legal_name' => $legal !== '' ? $legal : null,
            'trading_name' => $trading !== '' && $trading !== $legal ? $trading : null,
            'registration_number' => filled(config('money.operator.registration_number')) ? trim((string) config('money.operator.registration_number')) : null,
            'address_lines' => self::addressLines(),
            'vat_status' => $vat,
            'vat_number' => $vat === self::VAT_REGISTERED ? trim((string) config('money.vat.number')) : null,
            'vat_rate_percent' => $vat === self::VAT_REGISTERED ? (float) config('money.vat.rate_percent') : null,
            'support_email' => self::supportEmail(),
            'support_phone' => filled(config('money.operator.support_phone')) ? trim((string) config('money.operator.support_phone')) : null,
            'support_url' => filled(config('money.receipts.support_url')) ? trim((string) config('money.receipts.support_url')) : null,
            'eft' => self::eft(),
        ];
    }

    /**
     * What is not configured yet, in words for the operator.
     *
     * @return list<string>
     */
    public static function missing(): array
    {
        $doc = self::forDocument();
        $missing = [];

        if ($doc['legal_name'] === null) {
            $missing[] = 'legal name (EHAIL_OPERATOR_LEGAL_NAME)';
        }

        if ($doc['registration_number'] === null) {
            $missing[] = 'company registration number (EHAIL_OPERATOR_REGISTRATION)';
        }

        if ($doc['address_lines'] === []) {
            $missing[] = 'physical address (EHAIL_OPERATOR_ADDRESS)';
        }

        if ($doc['vat_status'] === self::VAT_NOT_CONFIGURED) {
            $missing[] = (bool) config('money.vat.registered')
                ? 'VAT number (EHAIL_VAT_NUMBER; VAT registered is on but no number is set)'
                : 'VAT position (EHAIL_VAT_REGISTERED=true with EHAIL_VAT_NUMBER, or EHAIL_VAT_REGISTERED=false)';
        }

        if ($doc['support_email'] === null && $doc['support_phone'] === null && $doc['support_url'] === null) {
            $missing[] = filled(config('money.receipts.support_email')) && self::isNoReply((string) config('money.receipts.support_email'))
                ? 'a support contact people can reach (EHAIL_SUPPORT_EMAIL is a no-reply address)'
                : 'a support contact (EHAIL_SUPPORT_EMAIL, EHAIL_SUPPORT_PHONE or EHAIL_SUPPORT_URL)';
        }

        if ($doc['eft'] === null) {
            $missing[] = 'EFT bank details for business statements (EHAIL_EFT_ACCOUNT_NAME, _BANK, _ACCOUNT_NUMBER, _BRANCH_CODE)';
        }

        return $missing;
    }

    /**
     * The precise VAT sentence for a document, from the configured status (never hedged).
     */
    public static function vatSentence(?array $doc = null, int $taxMinor = 0): ?string
    {
        $doc ??= self::forDocument();

        return match ($doc['vat_status'] ?? self::VAT_NOT_CONFIGURED) {
            self::VAT_REGISTERED => $taxMinor > 0
                ? 'VAT registration number '.$doc['vat_number'].'. VAT is shown on its own line.'
                : 'VAT registration number '.$doc['vat_number'].'. These fares carry no VAT.',
            self::VAT_NOT_REGISTERED => 'The operator is not registered for VAT, so no VAT is charged.',
            default => null,
        };
    }
}
