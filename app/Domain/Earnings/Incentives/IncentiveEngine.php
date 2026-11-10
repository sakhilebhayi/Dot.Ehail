<?php

namespace App\Domain\Earnings\Incentives;

use App\Domain\Earnings\Models\DriverEarning;
use App\Domain\Earnings\Models\IncentiveProgram;
use App\Domain\Earnings\Models\IncentiveProgress;
use App\Domain\Ledger\Ledger;
use App\Domain\Ledger\PostingRules;
use App\Domain\Payments\Seams\DriverActivitySource;
use App\Domain\Payments\Seams\PayeeResolver;
use App\Domain\Payments\Settlement;
use App\Models\DriverProfile;
use App\Models\Ride;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Evaluates incentive programmes from real trip data. A reward becomes an earning (and a ledger
 * journal) only at the moment every rule is met inside the window; until then progress shows the
 * measured facts and "in progress". Rules that need data nobody records yet (online time,
 * acceptance, referrals) are "unmeasurable", never met. Paused or ended programmes award nothing;
 * a programme whose budget is spent awards nothing more.
 */
class IncentiveEngine
{
    public function __construct(
        private DriverActivitySource $activity,
        private PayeeResolver $payees,
        private Settlement $settlement,
        private Ledger $ledger,
        private PostingRules $rules,
    ) {}

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function windowFor(IncentiveProgram $program, CarbonImmutable $at): array
    {
        $start = CarbonImmutable::instance($program->starts_at);
        $end = $program->ends_at === null ? null : CarbonImmutable::instance($program->ends_at);

        [$from, $to] = match ($program->window) {
            'day' => [$at->startOfDay(), $at->endOfDay()],
            'week' => [$at->startOfWeek(CarbonImmutable::MONDAY), $at->endOfWeek(CarbonImmutable::SUNDAY)],
            'month' => [$at->startOfMonth(), $at->endOfMonth()],
            default => [$start, $end ?? $at->addYears(50)],
        };

        return [$from->max($start), $end === null ? $to : $to->min($end)];
    }

    /**
     * Evaluate a programme for every driver with activity in the current window and in the window that
     * just closed (so the last hours of a week are not lost between runs).
     *
     * @return array{evaluated: int, awarded: int}
     */
    public function evaluate(IncentiveProgram $program, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $counts = ['evaluated' => 0, 'awarded' => 0];

        if ($program->status !== 'active' || $now->lt(CarbonImmutable::instance($program->starts_at))) {
            return $counts;
        }

        $windows = [$this->windowFor($program, $now)];

        if ($program->window !== 'program') {
            $previous = $this->windowFor($program, $windows[0][0]->subSecond());

            if ($previous[0]->lt($previous[1]) && $previous[1]->gte(CarbonImmutable::instance($program->starts_at))) {
                $windows[] = $previous;
            }
        }

        foreach ($windows as [$from, $to]) {
            foreach ($this->candidateDrivers($program, $from, $to) as $driver) {
                $progress = $this->evaluateDriver($program, $driver, $from, $to, $now);
                $counts['evaluated']++;
                $counts['awarded'] += $progress->status === 'earned' && $progress->wasChanged('status') ? 1 : 0;
            }
        }

        return $counts;
    }

    public function evaluateDriver(IncentiveProgram $program, User $driver, CarbonImmutable $from, CarbonImmutable $to, ?CarbonImmutable $now = null): IncentiveProgress
    {
        $now ??= CarbonImmutable::now();
        $progress = IncentiveProgress::firstOrCreate(
            ['incentive_program_id' => $program->id, 'driver_id' => $driver->id, 'window_start' => $from],
            ['window_end' => $to, 'progress' => [], 'status' => 'in_progress'],
        );

        if (in_array($progress->status, ['earned', 'budget_exhausted'], true)) {
            return $progress;
        }

        $measured = $this->measure($program, $driver, $from, $now->min($to));
        $met = $measured['met'];
        $status = match (true) {
            $measured['unmeasurable'] => 'unmeasurable',
            $met => 'earned',
            $now->gt($to) => 'not_met',
            default => 'in_progress',
        };

        $progress->forceFill([
            'window_end' => $to,
            'progress' => $measured['facts'],
            'percent' => $measured['percent'],
            'evaluated_at' => $now,
        ]);

        if ($status !== 'earned') {
            $progress->forceFill(['status' => $status])->save();

            return $progress;
        }

        return $this->award($program, $driver, $progress, $from);
    }

    /**
     * @return array{met: bool, unmeasurable: bool, percent: int, facts: array<string, mixed>}
     */
    public function measure(IncentiveProgram $program, User $driver, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rules = (array) $program->rules;
        $facts = [];
        $ratios = [];
        $unmeasurable = false;

        $completed = fn () => Ride::query()->where('driver_id', $driver->id)->where('status', 'completed')->whereBetween('completed_at', [$from, $to]);

        switch ($program->kind) {
            case 'trip_count':
                $facts['trips'] = $completed()->count();
                $facts['target_trips'] = (int) ($rules['trips'] ?? 0);
                $ratios[] = $this->ratio($facts['trips'], $facts['target_trips']);
                break;

            case 'area':
                $polygon = (array) ($rules['polygon'] ?? []);
                $facts['trips'] = $completed()->whereNotNull('pickup_lat')->get(['pickup_lat', 'pickup_lng'])
                    ->filter(fn (Ride $r) => self::pointInPolygon((float) $r->pickup_lat, (float) $r->pickup_lng, $polygon))->count();
                $facts['target_trips'] = (int) ($rules['trips'] ?? 0);
                $ratios[] = $this->ratio($facts['trips'], $facts['target_trips']);
                break;

            case 'milestone':
                $facts['lifetime_trips'] = Ride::query()->where('driver_id', $driver->id)->where('status', 'completed')->where('completed_at', '<=', $to)->count();
                $facts['target_lifetime_trips'] = (int) ($rules['lifetime_trips'] ?? 0);
                $ratios[] = $this->ratio($facts['lifetime_trips'], $facts['target_lifetime_trips']);
                break;

            case 'online_hours':
                $seconds = $this->activity->onlineSeconds($driver, $from, $to);
                $facts['target_hours'] = (float) ($rules['hours'] ?? 0);
                $facts['online_hours'] = $seconds === null ? null : round($seconds / 3600, 2);
                $unmeasurable = $seconds === null;
                $ratios[] = $seconds === null ? 0.0 : $this->ratio($seconds, (int) round($facts['target_hours'] * 3600));

                if (isset($rules['min_acceptance'])) {
                    $rate = $this->activity->acceptanceRate($driver, $from, $to);
                    $facts['acceptance'] = $rate;
                    $facts['min_acceptance'] = (float) $rules['min_acceptance'];
                    $unmeasurable = $unmeasurable || $rate === null;
                    $ratios[] = $rate === null ? 0.0 : ($rate >= (float) $rules['min_acceptance'] ? 1.0 : $rate / max(0.0001, (float) $rules['min_acceptance']));
                }
                break;

            case 'referral':
                $referrals = $this->activity->qualifiedReferrals($driver, $from, $to);
                $facts['referrals'] = $referrals;
                $facts['target_referrals'] = (int) ($rules['referrals'] ?? 0);
                $unmeasurable = $referrals === null;
                $ratios[] = $referrals === null ? 0.0 : $this->ratio($referrals, $facts['target_referrals']);
                break;

            default:
                $unmeasurable = true;
        }

        if (isset($rules['min_rating'])) {
            $rating = DriverProfile::withoutGlobalScope('user')->where('user_id', $driver->id)->value('rating');
            $facts['rating'] = $rating === null ? null : (float) $rating;
            $facts['min_rating'] = (float) $rules['min_rating'];
            $ratios[] = $rating !== null && (float) $rating >= (float) $rules['min_rating'] ? 1.0 : 0.0;
        }

        $met = ! $unmeasurable && $ratios !== [] && min($ratios) >= 1.0;
        $percent = $ratios === [] ? 0 : (int) floor(min(1.0, array_sum($ratios) / count($ratios)) * 100);

        return ['met' => $met, 'unmeasurable' => $unmeasurable, 'percent' => $met ? 100 : min(99, $percent), 'facts' => $facts];
    }

    /**
     * Ray casting over [[lat, lng], ...]. Fewer than three points never matches.
     *
     * @param  array<int, array<int, float|int>>  $polygon
     */
    public static function pointInPolygon(float $lat, float $lng, array $polygon): bool
    {
        $n = count($polygon);

        if ($n < 3) {
            return false;
        }

        $inside = false;

        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            [$latI, $lngI] = [(float) $polygon[$i][0], (float) $polygon[$i][1]];
            [$latJ, $lngJ] = [(float) $polygon[$j][0], (float) $polygon[$j][1]];

            if ((($lngI > $lng) !== ($lngJ > $lng)) && ($lat < ($latJ - $latI) * ($lng - $lngI) / (($lngJ - $lngI) ?: 1e-12) + $latI)) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }

    private function award(IncentiveProgram $program, User $driver, IncentiveProgress $progress, CarbonImmutable $from): IncentiveProgress
    {
        return DB::transaction(function () use ($program, $driver, $progress, $from) {
            $lockedProgram = IncentiveProgram::whereKey($program->id)->lockForUpdate()->firstOrFail();
            $lockedProgress = IncentiveProgress::whereKey($progress->id)->lockForUpdate()->firstOrFail();

            if (in_array($lockedProgress->status, ['earned', 'budget_exhausted'], true) || $lockedProgram->status !== 'active') {
                return $lockedProgress;
            }

            $remaining = $lockedProgram->budgetRemaining();

            if ($remaining !== null && $remaining < $lockedProgram->reward_minor) {
                $lockedProgress->forceFill(['status' => 'budget_exhausted', 'progress' => $progress->progress, 'percent' => 100, 'evaluated_at' => now()])->save();

                return $lockedProgress;
            }

            $payee = $this->payees->payeeFor($driver);
            $sourceKey = "incentive:{$lockedProgram->id}:{$driver->id}:".$from->format('YmdHis');

            $earning = DriverEarning::create([
                'kind' => DriverEarning::KIND_INCENTIVE,
                'source_key' => $sourceKey,
                'driver_id' => $driver->id,
                'fleet_id' => $payee['fleet_id'],
                'payee_type' => $payee['payee_type'],
                'payee_user_id' => $payee['payee_user_id'],
                'currency' => $lockedProgram->currency,
                'incentive_minor' => $lockedProgram->reward_minor,
                'net_minor' => $lockedProgram->reward_minor,
                'breakdown' => ['program_id' => $lockedProgram->id, 'program' => $lockedProgram->name, 'facts' => $progress->progress],
                'status' => DriverEarning::AVAILABLE,
                'earned_at' => now(),
                'available_at' => now(),
            ]);

            $journal = $this->ledger->post($this->rules->incentiveEarned(
                substr($sourceKey, strlen('incentive:')),
                $lockedProgram->reward_minor,
                $lockedProgram->currency,
                $this->settlement->earningsAccount($payee, $driver->id),
                $lockedProgram->name,
                $earning->id,
            ));

            $earning->forceFill(['journal_id' => $journal->id])->save();
            $lockedProgram->forceFill(['awarded_minor' => $lockedProgram->awarded_minor + $lockedProgram->reward_minor])->save();
            $lockedProgress->forceFill([
                'status' => 'earned',
                'earned_at' => now(),
                'earning_id' => $earning->id,
                'progress' => $progress->progress,
                'percent' => 100,
                'evaluated_at' => now(),
            ])->save();

            return $lockedProgress;
        });
    }

    /**
     * Drivers worth evaluating: anyone who completed a trip in the window (or, for unmeasurable kinds,
     * nobody: the driver's own view shows them as unmeasurable).
     *
     * @return iterable<User>
     */
    private function candidateDrivers(IncentiveProgram $program, CarbonImmutable $from, CarbonImmutable $to): iterable
    {
        if (in_array($program->kind, ['online_hours', 'referral'], true)) {
            return [];
        }

        $query = Ride::query()->where('status', 'completed')->whereNotNull('driver_id')->select('driver_id')->distinct();

        if ($program->kind !== 'milestone') {
            $query->whereBetween('completed_at', [$from, $to]);
        } else {
            $query->where('completed_at', '>=', $from->subDay());
        }

        if ($program->fleet_id !== null) {
            $query->whereIn('driver_id', DriverProfile::withoutGlobalScope('user')->where('fleet_id', $program->fleet_id)->select('user_id'));
        }

        return User::whereIn('id', $query)->cursor();
    }

    private function ratio(int|float $value, int|float $target): float
    {
        return $target <= 0 ? 0.0 : min(1.0, $value / $target);
    }
}
