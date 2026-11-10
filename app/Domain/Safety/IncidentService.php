<?php

namespace App\Domain\Safety;

use App\Domain\Safety\Models\IncidentNotification;
use App\Domain\Safety\Models\SafetyIncident;
use App\Domain\Safety\Notifications\SafetyUpdateNotification;
use App\Domain\Safety\Seams\SafetyLocationSource;
use App\Models\Ride;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Safety incidents (contract section 7): raise (SOS idempotent per ride and person while the
 * incident is active), capture context, notify, acknowledge, escalate on timers, resolve, close.
 * Dot.Ehail never claims to dispatch emergency services; whatWeDid() lists exactly what was sent.
 */
class IncidentService
{
    public function __construct(
        private readonly IncidentNotifier $notifier,
        private readonly IncidentTimeline $timeline,
        private readonly SafetyLocationSource $locations,
        private readonly TripShareService $shares,
    ) {}

    /**
     * @param  array{description?: ?string, lat?: ?float, lng?: ?float, accuracy_m?: ?int, source?: string, severity?: ?IncidentSeverity, notify_contacts?: bool}  $input
     * @return array{incident: SafetyIncident, created: bool}
     */
    public function raise(?Ride $ride, User $reporter, IncidentKind $kind, array $input = []): array
    {
        $role = $ride ? RideSafety::roleOf($ride, $reporter) : null;

        if ($ride !== null && $role === null && ! $reporter->isPlatformOperator()) {
            throw ValidationException::withMessages(['ride' => 'You can only report incidents on your own rides.']);
        }

        // One active SOS per person per ride (or per person without a ride). The unique index on
        // idempotency_key makes concurrent taps collapse; the key is released on resolve.
        $key = $kind === IncidentKind::Sos ? 'sos:'.($ride ? 'ride:'.$ride->id : 'none').':user:'.$reporter->id : null;

        if ($key !== null && ($existing = SafetyIncident::query()->where('idempotency_key', $key)->first())) {
            if (isset($input['lat'], $input['lng'])) {
                $this->updateLocation($existing, $reporter, (float) $input['lat'], (float) $input['lng'], $input['accuracy_m'] ?? null);
            }

            return ['incident' => $existing, 'created' => false];
        }

        $severity = $input['severity'] ?? $kind->defaultSeverity();

        try {
            $incident = DB::transaction(function () use ($ride, $reporter, $kind, $input, $key, $severity, $role) {
                $incident = SafetyIncident::create([
                    'reference' => $this->newReference(),
                    'ride_id' => $ride?->id,
                    'reporter_id' => $reporter->id,
                    'reporter_role' => $role ?? ($reporter->isPlatformOperator() ? 'operator' : null),
                    'subject_user_id' => $ride ? RideSafety::otherParty($ride, $reporter) : null,
                    'kind' => $kind,
                    'severity' => $severity,
                    'status' => IncidentStatus::Open,
                    'source' => $input['source'] ?? ($role === 'driver' ? 'driver_app' : 'passenger_app'),
                    'escalation_level' => 0,
                    'next_escalation_at' => now()->addMinutes($severity->escalationMinutes()),
                    'location_lat' => $input['lat'] ?? null,
                    'location_lng' => $input['lng'] ?? null,
                    'location_accuracy_m' => isset($input['accuracy_m']) ? (int) $input['accuracy_m'] : null,
                    'location_captured_at' => isset($input['lat'], $input['lng']) ? now() : null,
                    'description' => isset($input['description']) ? mb_substr(trim((string) $input['description']), 0, 2000) : null,
                    'context' => $ride ? $this->captureContext($ride) : null,
                    'idempotency_key' => $key,
                ]);

                $this->timeline->add($incident, $reporter, 'raised', ['kind' => $kind->value, 'severity' => $severity->value, 'source' => $incident->source]);
                $this->timeline->add($incident, null, 'context_captured', [
                    'ride' => $ride !== null,
                    'reporter_location' => $incident->location_lat !== null,
                    'driver_location' => ($incident->context['driver_position'] ?? null) !== null,
                    'trail_points' => count($incident->context['trail'] ?? []),
                ]);

                return $incident;
            });
        } catch (UniqueConstraintViolationException) {
            return ['incident' => SafetyIncident::query()->where('idempotency_key', $key)->firstOrFail(), 'created' => false];
        }

        $this->dispatchNotifications($incident, $reporter, $ride, (bool) ($input['notify_contacts'] ?? $kind === IncidentKind::Sos));

        return ['incident' => $incident->fresh(), 'created' => true];
    }

    public function acknowledge(SafetyIncident $incident, User $operator): SafetyIncident
    {
        $updated = $this->transition($incident, $operator, IncidentStatus::Acknowledged, function (SafetyIncident $locked) use ($operator) {
            $locked->acknowledged_at ??= now();
            $locked->owner_id ??= $operator->id;
            $locked->next_escalation_at = null;
        });

        $this->tellReporter($updated, 'safety_incident_acknowledged', 'Operations are on it', "An operator is now handling {$updated->reference}.");

        return $updated;
    }

    public function assign(SafetyIncident $incident, User $owner, User $actor): SafetyIncident
    {
        if (! $owner->isPlatformOperator()) {
            throw new SafetyActionRefused('Incidents can only be assigned to operators.');
        }

        return DB::transaction(function () use ($incident, $owner, $actor) {
            $locked = SafetyIncident::query()->lockForUpdate()->findOrFail($incident->id);
            $locked->forceFill(['owner_id' => $owner->id])->save();
            $this->timeline->add($locked, $actor, 'assigned', ['owner_id' => $owner->id]);

            return $locked;
        });
    }

    /** Move to the next escalation level now (an operator) or because a timer ran out (null actor). */
    public function escalate(SafetyIncident $incident, ?User $actor, string $reason = 'timer'): SafetyIncident
    {
        $updated = $this->transition($incident, $actor, IncidentStatus::Escalated, function (SafetyIncident $locked) {
            $maxLevel = max(array_keys((array) config('ehail_safety.escalation.levels', [])) ?: [0]);
            $locked->escalation_level = min($locked->escalation_level + 1, max(1, $maxLevel) + 1);
            $locked->escalated_at = now();
            $locked->next_escalation_at = now()->addMinutes($locked->severity->escalationMinutes());
        }, ['reason' => $reason]);

        $configured = array_key_exists($updated->escalation_level, (array) config('ehail_safety.escalation.levels', []));

        if ($configured) {
            $this->notifier->notifyEscalationLevel($updated, $updated->escalation_level);
        }

        // Past the last configured level, keep reminding the whole operations team on the same timer.
        $this->notifier->alertOperations($updated, $configured ? 'escalated' : 'reminder');

        return $updated;
    }

    /** Scheduler and polls: escalate every unacknowledged incident whose timer has run out. */
    public function escalateDue(): int
    {
        $count = 0;

        SafetyIncident::query()
            ->whereIn('status', [IncidentStatus::Open->value, IncidentStatus::Escalated->value])
            ->whereNull('acknowledged_at')
            ->whereNotNull('next_escalation_at')
            ->where('next_escalation_at', '<=', now())
            ->orderBy('id')
            ->each(function (SafetyIncident $incident) use (&$count) {
                // Claim the timer so two schedulers cannot escalate the same incident twice.
                $claimed = SafetyIncident::query()
                    ->whereKey($incident->id)
                    ->where('next_escalation_at', $incident->next_escalation_at)
                    ->update(['next_escalation_at' => null]);

                if ($claimed === 0) {
                    return;
                }

                try {
                    $this->escalate($incident->fresh(), null, 'unacknowledged');
                    $count++;
                } catch (Throwable $e) {
                    Log::error('Incident escalation failed.', ['incident_id' => $incident->id, 'error' => class_basename($e)]);
                    SafetyIncident::query()->whereKey($incident->id)->update(['next_escalation_at' => now()->addMinute()]);
                }
            });

        return $count;
    }

    public function resolve(SafetyIncident $incident, User $operator, string $code, ?string $note = null): SafetyIncident
    {
        $updated = $this->transition($incident, $operator, IncidentStatus::Resolved, function (SafetyIncident $locked) use ($operator, $code, $note) {
            $locked->resolved_at = now();
            $locked->owner_id ??= $operator->id;
            $locked->resolution_code = $code;
            $locked->resolution_note = $note;
            $locked->next_escalation_at = null;
            $locked->idempotency_key = null;
        }, ['code' => $code]);

        $this->tellReporter($updated, 'safety_incident_resolved', 'Safety report resolved', "{$updated->reference} has been resolved by operations.");

        return $updated;
    }

    public function close(SafetyIncident $incident, User $operator): SafetyIncident
    {
        return $this->transition($incident, $operator, IncidentStatus::Closed, function (SafetyIncident $locked) {
            $locked->closed_at = now();
        });
    }

    public function addNote(SafetyIncident $incident, User $actor, string $note): void
    {
        $this->timeline->add($incident, $actor, 'note', ['note' => mb_substr(trim($note), 0, 2000)]);
    }

    /** The reporter says they are safe now. Recorded for operations; it does not close anything. */
    public function markSafe(SafetyIncident $incident, User $reporter): void
    {
        $this->timeline->add($incident, $reporter, 'reporter_marked_safe');
    }

    public function updateLocation(SafetyIncident $incident, User $reporter, float $lat, float $lng, ?int $accuracy = null): void
    {
        if (! $incident->status->isActive()) {
            return;
        }

        $incident->forceFill([
            'location_lat' => $lat,
            'location_lng' => $lng,
            'location_accuracy_m' => $accuracy,
            'location_captured_at' => now(),
        ])->save();

        $this->timeline->add($incident, $reporter, 'location_update', ['accuracy_m' => $accuracy]);
    }

    /**
     * The confirmation screen: exactly which notifications were attempted and how each went,
     * what Dot.Ehail did NOT do, and the numbers to call.
     *
     * @return array<string, mixed>
     */
    public function whatWeDid(SafetyIncident $incident): array
    {
        $rows = IncidentNotification::query()->where('safety_incident_id', $incident->id)->orderBy('id')->get();

        $statusText = [
            IncidentNotification::SENT => 'Sent',
            IncidentNotification::FAILED => 'Failed',
            IncidentNotification::SKIPPED => 'Not sent',
            IncidentNotification::QUEUED => 'Queued (delivery not confirmed)',
            IncidentNotification::PENDING => 'Sending',
            IncidentNotification::NOT_DELIVERED => 'Not delivered (email is not set up on this server)',
        ];

        $why = [
            'no_confirmed_contacts' => 'You have no confirmed trusted contacts.',
            'sms_not_configured' => 'SMS alerts are off: the operator has not set up an SMS provider.',
            'no_recipients_configured' => 'Nobody is configured for this escalation level.',
            'no_operators' => 'No operations accounts exist.',
            'delivery_not_visible_to_ehail' => 'Handed to Dot.Notify; Dot.Ehail cannot see whether the email arrived.',
        ];

        return [
            'incident' => [
                'id' => $incident->id,
                'reference' => $incident->reference,
                'kind' => $incident->kind->value,
                'kind_label' => $incident->kind->label(),
                'status' => $incident->status->value,
                'raised_at' => $incident->created_at->toIso8601String(),
                'acknowledged' => $incident->acknowledged_at !== null,
            ],
            'actions' => [
                ['what' => 'Safety incident '.$incident->reference.' recorded', 'status' => 'done'],
                ['what' => $incident->context ? 'Trip, driver, vehicle and last known locations saved with the incident' : 'Report saved (no trip attached)', 'status' => 'done'],
                ...$rows->map(fn (IncidentNotification $n) => [
                    'what' => $n->displayLabel(),
                    'channel' => $n->channel,
                    'status' => $n->status,
                    'status_text' => $statusText[$n->status] ?? $n->status,
                    'why' => $n->error ? ($why[$n->error] ?? $n->error) : null,
                    'at' => ($n->sent_at ?? $n->attempted_at)?->toIso8601String(),
                ])->all(),
            ],
            'not_done' => [
                'Dot.Ehail did not call the police, an ambulance or a security company. If you are in danger, call now.',
            ],
            'emergency_numbers' => $this->notifier->emergencyNumbers(),
        ];
    }

    private function dispatchNotifications(SafetyIncident $incident, User $reporter, ?Ride $ride, bool $notifyContacts): void
    {
        try {
            $this->notifier->alertOperations($incident);
        } catch (Throwable $e) {
            Log::error('Operations alert failed.', ['incident_id' => $incident->id, 'error' => class_basename($e)]);
        }

        if (! $notifyContacts || $incident->reporter_role === 'operator') {
            return;
        }

        $shareUrl = null;

        if ($ride !== null && ! RideSafety::isTerminal($ride)) {
            try {
                $shareUrl = $this->shares->create($ride, $reporter)['url'];
                $this->timeline->add($incident, null, 'trip_share_created', ['for' => 'trusted_contacts']);
            } catch (Throwable) {
                $shareUrl = null;
            }
        }

        try {
            $this->notifier->notifyTrustedContacts($incident, $reporter, $shareUrl);
        } catch (Throwable $e) {
            Log::error('Trusted contact alerts failed.', ['incident_id' => $incident->id, 'error' => class_basename($e)]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function captureContext(Ride $ride): array
    {
        $ride->loadMissing(['passenger:id,name', 'driver:id,name']);
        $vehicle = $ride->vehicle_id ? Vehicle::query()->find($ride->vehicle_id) : null;

        return [
            'ride' => [
                'id' => $ride->id,
                'status' => $ride->status,
                'vehicle_type' => $ride->vehicle_type,
                'pickup_address' => $ride->pickup_address,
                'dropoff_address' => $ride->dropoff_address,
                'pickup' => $ride->pickup_lat !== null ? ['lat' => (float) $ride->pickup_lat, 'lng' => (float) $ride->pickup_lng] : null,
                'dropoff' => $ride->dropoff_lat !== null ? ['lat' => (float) $ride->dropoff_lat, 'lng' => (float) $ride->dropoff_lng] : null,
                'requested_at' => $ride->requested_at?->toIso8601String(),
                'accepted_at' => $ride->accepted_at?->toIso8601String(),
                'started_at' => $ride->started_at?->toIso8601String(),
            ],
            'passenger' => ['user_id' => $ride->passenger_id, 'name' => $ride->passenger?->name],
            'driver' => $ride->driver_id ? ['user_id' => $ride->driver_id, 'name' => $ride->driver?->name] : null,
            'vehicle' => $vehicle ? [
                'id' => $vehicle->id,
                'description' => trim("{$vehicle->color} {$vehicle->make} {$vehicle->model}"),
                'registration' => $vehicle->plate_number,
            ] : null,
            'driver_position' => $this->locations->latestDriverPosition($ride),
            'trail' => $this->locations->recentTrail($ride, 20),
            'captured_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function transition(SafetyIncident $incident, ?User $actor, IncidentStatus $to, callable $apply, array $data = []): SafetyIncident
    {
        return DB::transaction(function () use ($incident, $actor, $to, $apply, $data) {
            $locked = SafetyIncident::query()->lockForUpdate()->findOrFail($incident->id);
            $from = $locked->status;

            if (! $from->canBecome($to)) {
                throw new SafetyActionRefused("An incident that is {$from->value} cannot become {$to->value}.");
            }

            $apply($locked);
            $locked->status = $to;
            $locked->save();

            $this->timeline->add($locked, $actor, $to->value, ['from' => $from->value] + $data + ($to === IncidentStatus::Escalated ? ['level' => $locked->escalation_level] : []));

            return $locked;
        });
    }

    private function tellReporter(SafetyIncident $incident, string $type, string $title, string $message): void
    {
        try {
            $incident->reporter?->notify(new SafetyUpdateNotification($type, $title, $message, ['incident_id' => $incident->id]));
        } catch (Throwable $e) {
            Log::warning('Could not tell the reporter about an incident update.', ['incident_id' => $incident->id, 'error' => class_basename($e)]);
        }
    }

    private function newReference(): string
    {
        do {
            $reference = 'INC-'.Str::upper(Str::random(8));
        } while (SafetyIncident::query()->where('reference', $reference)->exists());

        return $reference;
    }
}
