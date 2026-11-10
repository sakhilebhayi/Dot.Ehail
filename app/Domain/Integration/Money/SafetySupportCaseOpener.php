<?php

namespace App\Domain\Integration\Money;

use App\Domain\Payments\Seams\SupportCaseOpener;
use App\Domain\Support\SupportCaseService;
use App\Domain\Support\SupportCategory;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Money problems (cash discrepancy, disputed charge) become cases in the support queue with its SLA timers.
 * The case is opened in the name of the person money says raised it; a refusal by support (for example a ride
 * the person is not part of) leaves only the finance issue, logged.
 */
class SafetySupportCaseOpener implements SupportCaseOpener
{
    public function __construct(private readonly SupportCaseService $cases) {}

    public function open(string $kind, ?Ride $ride, User $raisedBy, string $summary): ?string
    {
        $category = str_contains($kind, 'dispute') || str_contains($kind, 'fare') ? SupportCategory::FareDispute : SupportCategory::PaymentIssue;

        try {
            $case = $this->cases->open($raisedBy, $category, mb_substr('Payments: '.str_replace('_', ' ', $kind), 0, 120), $summary, $ride ? ['ride_id' => $ride->id] : []);
        } catch (Throwable $e) {
            Log::warning('ehail.integration.support_case_not_opened', ['kind' => $kind, 'ride_id' => $ride?->id, 'error' => class_basename($e)]);

            return null;
        }

        return (string) $case->reference;
    }
}
