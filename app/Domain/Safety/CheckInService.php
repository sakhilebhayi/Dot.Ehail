<?php

namespace App\Domain\Safety;

use App\Domain\Safety\Models\SafetyCheckIn;
use App\Domain\Safety\Notifications\SafetyUpdateNotification;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * After the geo domain reports a route deviation or an unusual stop, both the passenger and the
 * driver are asked "are you OK?". "I need help" raises an incident at once (and alerts the
 * person's trusted contacts); no answer within the configured minutes raises an incident for
 * operations. One prompt per ride, trigger and person per 10-minute bucket, so a noisy detector
 * cannot flood anyone.
 */
class CheckInService
{
    public function __construct(private readonly IncidentService $incidents) {}

    /**
     * @param  array<string, mixed>  $context  what the detector saw (distance off route, stop length, position)
     * @return list<SafetyCheckIn>
     */
    public function prompt(Ride $ride, string $trigger, array $context = []): array
    {
        if (! RideSafety::isActive($ride) || ! in_array($trigger, ['route_deviation', 'unusual_stop'], true)) {
            return [];
        }

        $bucket = intdiv(now()->timestamp, 600);
        $created = [];

        foreach (['passenger' => $ride->passenger_id, 'driver' => $ride->driver_id] as $role => $userId) {
            if ($userId === null) {
                continue;
            }

            try {
                // Savepoint: a duplicate must not abort an enclosing PostgreSQL transaction (D-715).
                $checkIn = DB::transaction(fn () => SafetyCheckIn::create([
                    'ride_id' => $ride->id,
                    'user_id' => $userId,
                    'role' => $role,
                    'trigger' => $trigger,
                    'status' => 'pending',
                    'prompted_at' => now(),
                    'respond_by' => now()->addMinutes((int) config('ehail_safety.check_ins.respond_within_minutes', 3)),
                    'context' => $context,
                    'dedupe_key' => "{$ride->id}:{$trigger}:{$userId}:{$bucket}",
                ]));
            } catch (UniqueConstraintViolationException) {
                continue;
            }

            $created[] = $checkIn;

            try {
                User::find($userId)?->notify(new SafetyUpdateNotification(
                    'safety_check_in',
                    'Are you OK?',
                    $checkIn->toPublicArray()['question'],
                    ['check_in_id' => $checkIn->id, 'ride_id' => $ride->id],
                ));
            } catch (Throwable $e) {
                Log::warning('Check-in prompt notification failed.', ['check_in_id' => $checkIn->id, 'error' => class_basename($e)]);
            }
        }

        return $created;
    }

    /**
     * @param  'ok'|'need_help'  $answer
     */
    public function respond(SafetyCheckIn $checkIn, User $user, string $answer, ?float $lat = null, ?float $lng = null): SafetyCheckIn
    {
        if ($checkIn->user_id !== $user->id) {
            throw new SafetyActionRefused('This check-in is not yours.');
        }

        $answered = DB::transaction(function () use ($checkIn, $answer) {
            $locked = SafetyCheckIn::query()->lockForUpdate()->findOrFail($checkIn->id);

            // An answer after the deadline still counts: "I need help" must never be dropped, and
            // "I'm OK" is recorded next to the incident the timeout already raised.
            if (! in_array($locked->status, ['pending', 'unanswered'], true)) {
                return null;
            }

            $locked->forceFill(['status' => $answer, 'responded_at' => now()])->save();

            return $locked;
        });

        if ($answered === null) {
            return $checkIn->fresh();
        }

        if ($answer === 'need_help') {
            $ride = Ride::find($answered->ride_id);
            $result = $this->incidents->raise($ride, $user, IncidentKind::Deviation, [
                'severity' => IncidentSeverity::Critical,
                'source' => 'check_in_need_help',
                'description' => 'Answered "I need help" to a '.str_replace('_', ' ', $answered->trigger).' check-in.',
                'lat' => $lat,
                'lng' => $lng,
                'notify_contacts' => true,
            ]);
            $answered->forceFill(['safety_incident_id' => $result['incident']->id])->save();
        } elseif ($answered->safety_incident_id !== null) {
            $incident = $answered->incident;

            if ($incident !== null) {
                app(IncidentTimeline::class)->add($incident, $user, 'check_in_answered_ok', ['check_in_id' => $answered->id, 'late' => true]);
            }
        }

        return $answered;
    }

    /** Scheduler and polls: unanswered prompts past their deadline raise an incident for operations. */
    public function sweepUnanswered(): int
    {
        $count = 0;

        SafetyCheckIn::query()
            ->where('status', 'pending')
            ->where('respond_by', '<=', now())
            ->orderBy('id')
            ->each(function (SafetyCheckIn $checkIn) use (&$count) {
                $claimed = SafetyCheckIn::query()->whereKey($checkIn->id)->where('status', 'pending')->update(['status' => 'unanswered']);

                if ($claimed === 0) {
                    return;
                }

                $ride = Ride::find($checkIn->ride_id);
                $user = User::find($checkIn->user_id);

                if ($ride === null || $user === null) {
                    return;
                }

                $result = $this->incidents->raise($ride, $user, IncidentKind::Deviation, [
                    'severity' => IncidentSeverity::High,
                    'source' => 'check_in_timeout',
                    'description' => 'No answer to a '.str_replace('_', ' ', $checkIn->trigger).' check-in within '.config('ehail_safety.check_ins.respond_within_minutes', 3).' minutes.',
                    'notify_contacts' => false,
                ]);
                SafetyCheckIn::query()->whereKey($checkIn->id)->update(['safety_incident_id' => $result['incident']->id]);
                $count++;
            });

        return $count;
    }
}
