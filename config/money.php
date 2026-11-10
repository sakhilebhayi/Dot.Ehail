<?php

/*
|--------------------------------------------------------------------------
| Money (R-46): payments, ledger, driver earnings, payouts, incentives, receipts
|--------------------------------------------------------------------------
| All amounts are integer minor units (cents for ZAR). Every figure below is an operator setting,
| not a market fact: the owner decides commission, payout cadence, minimum payout, cash policy and
| VAT. See docs/R46_MONEY.md, "Owner steps".
*/

return [

    'currency' => env('EHAIL_CURRENCY_CODE', 'ZAR'),

    /*
    | Commission used by the built-in splitter (App\Domain\Payments\Seams\ConfigCommissionSplitter) until
    | the pricing domain's EarningsSplitter (commission plans per market/fleet) is bound at merge.
    | Percent of the fare net of tax, whole or decimal (e.g. 20 or 17.5), rounded half up to the cent.
    */
    'commission' => [
        'percent' => (float) env('EHAIL_COMMISSION_PERCENT', 20),
    ],

    /*
    | Cash. Off unless the operator enables it. `categories` limits it to ride categories (empty =
    | every category). A driver must also opt in to accept cash (driver money settings).
    */
    'cash' => [
        'enabled' => (bool) env('EHAIL_CASH_ENABLED', false),
        'categories' => array_values(array_filter(explode(',', (string) env('EHAIL_CASH_CATEGORIES', '')))),
        // A confirmed amount this far below the expected cash (minor units) opens a discrepancy for support.
        'discrepancy_tolerance_minor' => (int) env('EHAIL_CASH_DISCREPANCY_TOLERANCE', 0),
    ],

    /*
    | Card charging after a trip (saved Paystack authorisation, charged server side).
    | retry_minutes: delays between automatic retries of a declined charge. After the last one the
    | passenger is asked to update their method and new requests are blocked until it is settled.
    | pending_verify_after_seconds: a charge with no final answer is re-verified after this long.
    */
    'charging' => [
        'retry_minutes' => array_map('intval', array_filter(explode(',', (string) env('EHAIL_CHARGE_RETRY_MINUTES', '15,120,1440')))),
        'pending_verify_after_seconds' => (int) env('EHAIL_CHARGE_PENDING_VERIFY_SECONDS', 90),
        // Unpaid rides older than this block the passenger from requesting another ride.
        'payment_due_grace_minutes' => (int) env('EHAIL_PAYMENT_DUE_GRACE_MINUTES', 15),
    ],

    /*
    | Adding a card: Paystack has no "tokenise without charging" call for cards. Its documented way to
    | get a reusable authorisation is a first successful card charge. We charge this small amount, keep
    | the authorisation if Paystack marks it reusable, and refund the charge at once.
    */
    'card_setup' => [
        'amount_minor' => (int) env('EHAIL_CARD_SETUP_AMOUNT_MINOR', 100),
        'return_to' => env('EHAIL_CARD_SETUP_RETURN', '/dashboard'),
    ],

    /*
    | Cancellation fees: share of a fee credited to the driver (percent). The fee itself comes from the
    | pricing domain's CancellationFeePolicy seam; until that is bound no fee is ever charged.
    */
    'cancellation' => [
        'driver_share_percent' => (float) env('EHAIL_CANCELLATION_DRIVER_SHARE', 80),
    ],

    /*
    | Driver earnings become "available" when the trip's payment is settled. Optional hold (hours)
    | after settlement before they count towards a payout, for chargeback/dispute risk.
    */
    'earnings' => [
        'availability_hold_hours' => (int) env('EHAIL_EARNINGS_HOLD_HOURS', 0),
    ],

    /*
    | Payouts. Manual batch export is always available. Paystack Transfers is OFF until the owner has
    | a Registered Business on Paystack with transfers enabled and sets EHAIL_PAYSTACK_TRANSFERS=true.
    | schedule_day: ISO weekday a batch is drafted (1 = Monday). period: earnings up to the end of the
    | previous day. payee_for_fleet_drivers: 'driver' (each driver is paid) or 'fleet_owner'.
    */
    'payouts' => [
        'schedule_day' => (int) env('EHAIL_PAYOUT_DAY', 1),
        'schedule_time' => env('EHAIL_PAYOUT_TIME', '06:00'),
        'minimum_minor' => (int) env('EHAIL_PAYOUT_MINIMUM_MINOR', 5000),
        'payee_for_fleet_drivers' => env('EHAIL_FLEET_DRIVER_PAYEE', 'driver'),
        'paystack_transfers' => (bool) env('EHAIL_PAYSTACK_TRANSFERS', false),
        // Verify bank accounts with Paystack's /bank/validate (South Africa) when Paystack is configured.
        'verify_accounts' => (bool) env('EHAIL_VERIFY_PAYOUT_ACCOUNTS', true),
    ],

    /*
    | VAT on receipts. Shown only when the operator is VAT registered (number set). Rate in percent.
    | Whether fares carry VAT is a pricing (tax mode) and owner decision; receipts print the tax amount
    | the fare breakdown carries, never one computed here.
    */
    'vat' => [
        'registered' => (bool) env('EHAIL_VAT_REGISTERED', false),
        // Whether the operator has stated a VAT position at all (true or false). Unset = "not configured": money
        // documents then make no VAT statement and preflight warns (D-1905).
        'declared' => env('EHAIL_VAT_REGISTERED') !== null,
        'number' => env('EHAIL_VAT_NUMBER'),
        'rate_percent' => (float) env('EHAIL_VAT_RATE', 15),
    ],

    'receipts' => [
        'prefix' => env('EHAIL_RECEIPT_PREFIX', 'EHR'),
        'operator_name' => env('EHAIL_OPERATOR_LEGAL_NAME', env('APP_NAME', 'Dot.Ehail')),
        'support_url' => env('EHAIL_SUPPORT_URL'),
        // A real inbox people can write to. No fallback to MAIL_FROM_ADDRESS (a no-reply sender, D-1905).
        'support_email' => env('EHAIL_SUPPORT_EMAIL'),
        'email' => (bool) env('EHAIL_EMAIL_RECEIPTS', true),
    ],

    /*
    | The operating company as it appears on receipts and business statements (App\Domain\Payments\OperatorIdentity,
    | D-1905). Every value comes from here and is never invented: a missing value is left off the document for
    | passengers and businesses, and `php artisan ehail:preflight` lists it for operations as "not configured".
    | Address lines are separated with "|". EFT details are printed on business statements that ask for EFT.
    */
    'operator' => [
        'legal_name' => env('EHAIL_OPERATOR_LEGAL_NAME'),
        'trading_name' => env('EHAIL_OPERATOR_TRADING_NAME', env('APP_NAME', 'Dot.Ehail')),
        'registration_number' => env('EHAIL_OPERATOR_REGISTRATION'),
        'address' => env('EHAIL_OPERATOR_ADDRESS'),
        'support_phone' => env('EHAIL_SUPPORT_PHONE'),
        'eft' => [
            'account_name' => env('EHAIL_EFT_ACCOUNT_NAME'),
            'bank' => env('EHAIL_EFT_BANK'),
            'account_number' => env('EHAIL_EFT_ACCOUNT_NUMBER'),
            'branch_code' => env('EHAIL_EFT_BRANCH_CODE'),
            'account_type' => env('EHAIL_EFT_ACCOUNT_TYPE'),
        ],
    ],

];
