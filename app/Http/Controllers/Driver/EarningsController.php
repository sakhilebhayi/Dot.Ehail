<?php

namespace App\Http\Controllers\Driver;

use App\Domain\Earnings\EarningsService;
use App\Domain\Earnings\Incentives\IncentiveEngine;
use App\Domain\Earnings\Models\DriverEarning;
use App\Domain\Earnings\Models\Payout;
use App\Domain\Earnings\Models\PayoutAccount;
use App\Domain\Earnings\Payouts\PayoutAccounts;
use App\Domain\Payments\Seams\DriverActivitySource;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Driver\Support\DriverState;
use App\Http\Controllers\Money\DriverMoneyController;
use App\Models\Ride;
use App\Services\Payments\Paystack\PaystackConfig;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Earnings, payouts and incentives, read from the money domain (EarningsService, payouts, incentive engine). The
 * driver sees only their own rows: every query here is scoped to the signed-in user.
 */
class EarningsController extends Controller
{
    public function index(Request $request, EarningsService $earnings, DriverActivitySource $activity, IncentiveEngine $engine): View|RedirectResponse
    {
        $state = DriverState::for($request->user());
        if ($state->profile() === null) {
            return redirect()->route('drive.apply');
        }

        $validated = $request->validate([
            'period' => ['sometimes', Rule::in(['day', 'week', 'month'])],
            'date' => ['sometimes', 'date_format:Y-m-d'],
        ]);
        $period = $validated['period'] ?? 'day';
        $date = $validated['date'] ?? now()->toDateString();

        $statement = $earnings->forDriver($request->user(), $period, $date);
        $from = CarbonImmutable::parse($statement['from']);
        $to = CarbonImmutable::parse($statement['to']);

        // Online time only when dispatch measures it (money's seam answers null otherwise).
        $online = null;
        try {
            $online = $activity->onlineSeconds($request->user(), $from, $to->min(now()));
        } catch (\Throwable) {
            $online = null;
        }

        $rides = Ride::query()->whereIn('id', collect($statement['earnings'])->pluck('ride_id')->filter()->all())
            ->get(['id', 'pickup_address', 'dropoff_address', 'completed_at'])->keyBy('id');

        $incentives = app(DriverMoneyController::class)->incentives($request, $engine)->getData(true)['programs'] ?? [];

        return view('driver.earnings', [
            'statement' => $statement,
            'period' => $period,
            'date' => $date,
            'from' => $from,
            'to' => $to,
            'previous' => $this->shift($period, $date, -1),
            'next' => $this->shift($period, $date, 1),
            'isCurrent' => now()->betweenIncluded($from, $to),
            'onlineSeconds' => $online,
            'rides' => $rides,
            'incentives' => $incentives,
            'nextPayout' => self::nextPayoutDraft(),
        ]);
    }

    public function payouts(Request $request, EarningsService $earnings, PaystackConfig $paystack): View|RedirectResponse
    {
        $state = DriverState::for($request->user());
        if ($state->profile() === null) {
            return redirect()->route('drive.apply');
        }

        return view('driver.payouts', [
            'balances' => $earnings->balancesFor($request->user()),
            'payouts' => Payout::with(['batch', 'account'])->where('payee_user_id', $request->user()->id)->orderByDesc('id')->limit(50)->get(),
            'account' => PayoutAccount::where('user_id', $request->user()->id)->where('payee_type', 'driver')->where('is_active', true)->latest('id')->first(),
            'verificationAvailable' => (bool) config('money.payouts.verify_accounts') && $paystack->canTakePayments(),
            'nextPayout' => self::nextPayoutDraft(),
            'minimumMinor' => (int) config('money.payouts.minimum_minor'),
            'transfers' => (bool) config('money.payouts.paystack_transfers'),
            'payee' => (string) config('money.payouts.payee_for_fleet_drivers'),
        ]);
    }

    public function payout(Request $request, Payout $payout): View
    {
        abort_unless(Gate::allows('money.view-payout', $payout) && $payout->payee_user_id === $request->user()->id, 404);

        $earnings = DriverEarning::whereIn('id', (array) ($payout->composition['earning_ids'] ?? []))->where('driver_id', $request->user()->id)->orderBy('earned_at')->get();

        return view('driver.payout', ['payout' => $payout->load(['batch', 'account']), 'earnings' => $earnings]);
    }

    public function storeAccount(Request $request, PayoutAccounts $accounts): RedirectResponse
    {
        $data = $request->validate([
            'account_holder' => ['required', 'string', 'max:120'],
            'account_number' => ['required', 'string', 'regex:/^[0-9 ]{6,20}$/'],
            'bank_code' => ['required', 'string', 'max:20', 'regex:/^[0-9A-Za-z]+$/'],
            'bank_name' => ['required', 'string', 'max:80'],
            'account_type' => ['required', Rule::in(['personal', 'business'])],
        ], [
            'account_number.regex' => 'Use 6 to 20 digits.',
            'bank_code.regex' => 'Use the branch code: letters and digits only.',
        ]);

        $accounts->add($request->user(), $data + ['payee_type' => 'driver', 'fleet_id' => null]);

        return redirect()->route('drive.earnings.payouts')->with('status', 'Bank account saved.');
    }

    public function verifyAccount(Request $request, PayoutAccount $account, PayoutAccounts $accounts): RedirectResponse
    {
        abort_unless($account->user_id === $request->user()->id, 404);

        $data = $request->validate([
            'document_type' => ['required', Rule::in(['identityNumber', 'passportNumber'])],
            'document_number' => ['required', 'string', 'max:40'],
        ]);

        $account = $accounts->verify($account, $data['document_type'], $data['document_number']);

        return redirect()->route('drive.earnings.payouts')->with('status', match ($account->verification_status) {
            'verified' => 'Your bank confirmed the account.',
            'failed' => 'The bank did not confirm these details. Check them and try again.',
            default => 'Automatic checking is not available; operations checks the account before paying.',
        });
    }

    /**
     * When the next payout batch is drafted (money's weekly schedule). Money moves only when operations pays it.
     */
    public static function nextPayoutDraft(?CarbonImmutable $now = null): CarbonImmutable
    {
        $now ??= CarbonImmutable::now();
        [$hour, $minute] = array_map('intval', explode(':', (string) config('money.payouts.schedule_time', '06:00')) + [0, 0]);
        $day = (int) config('money.payouts.schedule_day', 1);

        $candidate = $now->setTime($hour, $minute);
        $candidate = $candidate->addDays(($day - $candidate->dayOfWeekIso + 7) % 7);

        return $candidate->lte($now) ? $candidate->addWeek() : $candidate;
    }

    private function shift(string $period, string $date, int $direction): string
    {
        $d = CarbonImmutable::parse($date);

        $shifted = match ($period) {
            'day' => $d->addDays($direction),
            'week' => $d->addWeeks($direction),
            'month' => $d->startOfMonth()->addMonths($direction),
        };

        return $shifted->toDateString();
    }
}
