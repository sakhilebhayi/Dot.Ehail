<?php

namespace App\Domain\Payments\Receipts;

use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\Receipt;
use App\Domain\Payments\OperatorIdentity;
use App\Domain\Payments\Money;
use App\Domain\Payments\Seams\FareSource;
use App\Models\Ride;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Issues one receipt per paid ride (and one per paid cancellation fee): a gapless sequence number
 * (money_sequences, row-locked) and a frozen snapshot. A VAT line appears only when the operator is
 * VAT registered AND the fare breakdown carries tax; no tax figure is computed here.
 */
class ReceiptIssuer
{
    public function __construct(private FareSource $fares) {}

    public function issueFor(Ride $ride, Payment $payment): ?Receipt
    {
        $kind = $payment->purpose === Payment::PURPOSE_CANCELLATION_FEE ? 'cancellation_fee' : 'ride';
        $existing = Receipt::where('ride_id', $ride->id)->where('kind', $kind)->first();

        if ($existing !== null) {
            return $existing;
        }

        $snapshot = $this->snapshot($ride, $payment, $kind);

        try {
            $receipt = DB::transaction(function () use ($ride, $payment, $kind, $snapshot) {
                $number = $this->nextNumber();

                return Receipt::create([
                    'number' => $number,
                    'kind' => $kind,
                    'ride_id' => $ride->id,
                    'passenger_id' => $ride->passenger_id,
                    'payment_id' => $payment->id,
                    'currency' => $payment->currency,
                    'total_minor' => $snapshot['total_minor'],
                    'snapshot' => $snapshot + ['number' => $number],
                    'issued_at' => now(),
                    'created_at' => now(),
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            return Receipt::where('ride_id', $ride->id)->where('kind', $kind)->first();
        }

        if (config('money.receipts.email') && $ride->passenger?->email) {
            DB::afterCommit(fn () => $ride->passenger->notify(new ReceiptIssuedNotification($receipt)));
        }

        return $receipt;
    }

    private function nextNumber(): string
    {
        $name = 'receipt';

        DB::table('money_sequences')->insertOrIgnore(['name' => $name, 'last_value' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $current = (int) DB::table('money_sequences')->where('name', $name)->lockForUpdate()->value('last_value');
        $next = $current + 1;
        DB::table('money_sequences')->where('name', $name)->update(['last_value' => $next, 'updated_at' => now()]);

        return config('money.receipts.prefix', 'EHR').'-'.str_pad((string) $next, 8, '0', STR_PAD_LEFT);
    }

    /**
     * @param  array<int, array<string, mixed>>  $components
     * @return array{distance: ?string, time: ?string}
     */
    public static function billed(array $components): array
    {
        $find = fn (string $code) => collect($components)->first(fn ($c) => ($c['code'] ?? null) === $code && isset($c['quantity'], $c['unit']));
        $distance = $find('distance');
        $time = $find('time');

        return [
            'distance' => $distance ? $distance['quantity'].' '.$distance['unit'] : null,
            'time' => $time ? $time['quantity'].' '.$time['unit'] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Ride $ride, Payment $payment, string $kind): array
    {
        $ride->loadMissing(['driver', 'vehicle']);
        $fare = $kind === 'ride' ? $this->fares->finalFareFor($ride) : null;
        $method = $payment->method;
        $operator = OperatorIdentity::forDocument();
        $vatRegistered = $operator['vat_status'] === OperatorIdentity::VAT_REGISTERED;
        $tax = $fare?->taxMinor ?? 0;

        $components = $kind === 'ride'
            ? ($fare?->components ?? [['code' => 'fare', 'label' => 'Trip fare', 'amount_minor' => $payment->amountMinor()]])
            : [['code' => 'cancellation_fee', 'label' => 'Cancellation fee', 'amount_minor' => $payment->amountMinor()]];

        return [
            'kind' => $kind,
            'ride_id' => $ride->id,
            // The operating company as configured (D-1905), frozen with the receipt.
            'operator' => $operator,
            'operator_name' => $operator['legal_name'] ?? $operator['brand'],
            'vat_number' => $operator['vat_number'],
            'vat_sentence' => OperatorIdentity::vatSentence($operator, $vatRegistered ? $tax : 0),
            'pickup_address' => $ride->pickup_address,
            'dropoff_address' => $ride->dropoff_address,
            'requested_at' => $ride->requested_at?->toIso8601String(),
            'started_at' => $ride->started_at?->toIso8601String(),
            'completed_at' => $ride->completed_at?->toIso8601String(),
            'cancelled_at' => $ride->cancelled_at?->toIso8601String(),
            'distance_m' => $fare?->distanceM,
            'duration_s' => $fare?->durationS,
            'fare_basis' => $fare?->basis,
            // What the time and distance lines were billed on (D-1904): the metered quantities of the fare itself,
            // which differ from the measured trip when the quoted fare stood.
            'billed' => self::billed($components),
            'components' => $components,
            'promotion_minor' => $fare?->promotionMinor ?? 0,
            'tax_minor' => $vatRegistered ? $tax : 0,
            'tax_label' => $vatRegistered && $tax > 0 ? 'VAT included ('.rtrim(rtrim(number_format((float) config('money.vat.rate_percent'), 2, '.', ''), '0'), '.').'%)' : null,
            'total_minor' => $payment->amountMinor(),
            'currency' => $payment->currency,
            'payment_method' => match ($payment->provider) {
                'cash' => 'Cash',
                'business_account' => 'Business account',
                default => $method !== null ? $method->maskedLabel() : 'Card (Paystack)',
            },
            'paid_at' => ($payment->paid_at ?? now())->toIso8601String(),
            'driver_first_name' => $ride->driver === null ? null : strtok(trim((string) $ride->driver->name), ' '),
            'vehicle' => $ride->vehicle === null ? null : trim($ride->vehicle->color.' '.$ride->vehicle->make.' '.$ride->vehicle->model).' ('.$ride->vehicle->plate_number.')',
            'support_url' => $operator['support_url'],
            'support_email' => $operator['support_email'],
            'support_phone' => $operator['support_phone'],
            'formatted_total' => Money::format($payment->amountMinor(), $payment->currency),
        ];
    }
}
