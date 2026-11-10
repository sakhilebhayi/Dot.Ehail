<?php

namespace App\Domain\Ops\Live;

use App\Domain\Ops\Support\Durations;
use App\Domain\Safety\IncidentKind;
use App\Domain\Safety\IncidentSeverity;
use App\Domain\Safety\IncidentStatus;
use App\Domain\Safety\Models\SafetyIncident;
use App\Support\Ui\LocalTime;
use App\Support\Ui\PlaceNames;
use Illuminate\Support\Collection;

/**
 * Words and map points for safety incidents on operations screens (R-46 polish, D-2202..D-2204): the live board's
 * incident rail and the incident page share them, so owner, escalation level and place read the same everywhere.
 */
class IncidentPresenter
{
    public function __construct(private readonly PlaceNames $places) {}

    /**
     * Active incidents that take over the live board: every SOS and every other critical incident, the ones nobody
     * has acknowledged first, then the oldest.
     *
     * @return Collection<int, SafetyIncident>
     */
    public function takeover(int $limit = 6): Collection
    {
        return SafetyIncident::query()
            ->with(['reporter:id,name', 'owner:id,name'])
            ->whereIn('status', [IncidentStatus::Open->value, IncidentStatus::Acknowledged->value, IncidentStatus::Escalated->value])
            ->where(fn ($q) => $q->where('kind', IncidentKind::Sos->value)->orWhere('severity', IncidentSeverity::Critical->value))
            ->orderByRaw('case when acknowledged_at is null then 0 else 1 end')
            ->orderBy('created_at')
            ->limit($limit)
            ->get();
    }

    /** @return array<string, mixed> one rail card for the live board (page and polling feed) */
    public function rail(SafetyIncident $incident): array
    {
        $ctx = is_array($incident->context) ? $incident->context : [];
        $point = $this->point($incident);
        $age = (int) $incident->created_at->diffInSeconds(now(), true);

        return [
            'id' => $incident->id,
            'reference' => $incident->reference,
            'kind' => $incident->kind->label(),
            'is_sos' => $incident->kind === IncidentKind::Sos,
            'status' => $incident->status->value,
            'acknowledged' => $incident->acknowledged_at !== null,
            'owner' => $incident->owner?->name,
            'owner_text' => $this->ownerText($incident),
            'level' => (int) $incident->escalation_level,
            'level_text' => $this->levelText($incident),
            'next_text' => $this->nextText($incident),
            'who' => $this->reporterText($incident),
            'ride_id' => $incident->ride_id,
            'vehicle' => is_array($ctx['vehicle'] ?? null) ? ($ctx['vehicle']['registration'] ?? null) : null,
            'place' => $point ? $point['words'] : 'No position sent',
            'opened_at' => $incident->created_at->toIso8601String(),
            'opened_text' => 'Raised '.LocalTime::time($incident->created_at).' '.LocalTime::label().', '.Durations::short($age).' ago',
            'lat' => $point['lat'] ?? null,
            'lng' => $point['lng'] ?? null,
            'href' => route('ops.safety.show', $incident->id),
        ];
    }

    public function ownerText(SafetyIncident $incident): string
    {
        if ($incident->owner !== null) {
            return 'Owner: '.$incident->owner->name;
        }

        return $incident->acknowledged_at === null ? 'Nobody owns it and nobody has acknowledged it' : 'Acknowledged, nobody owns it';
    }

    /** "Level 2, Safety manager" / "Level 3: past the last level; every operator is reminded every 2 min". */
    public function levelText(SafetyIncident $incident): string
    {
        $level = (int) $incident->escalation_level;
        $levels = (array) config('ehail_safety.escalation.levels', []);
        $minutes = $incident->severity->escalationMinutes();

        if ($level === 0) {
            return 'Level 0, the operations queue';
        }

        if (isset($levels[$level]['label'])) {
            return 'Level '.$level.', '.$levels[$level]['label'];
        }

        return 'Level '.$level.': past the last escalation level; every operator is reminded every '.$minutes.' min';
    }

    /** "next escalation at 10:23 SAST" / "next reminder due now"; null once someone has acknowledged it. */
    public function nextText(SafetyIncident $incident): ?string
    {
        if ($incident->next_escalation_at === null || $incident->acknowledged_at !== null || ! $incident->status->isActive()) {
            return null;
        }

        $last = max(array_keys((array) config('ehail_safety.escalation.levels', [])) ?: [0]);
        $what = (int) $incident->escalation_level >= $last ? 'reminder to every operator' : 'escalation';

        return 'next '.$what.' '.($incident->next_escalation_at->isPast() ? 'due now' : 'at '.LocalTime::time($incident->next_escalation_at).' '.LocalTime::label());
    }

    public function reporterText(SafetyIncident $incident): string
    {
        $role = $incident->reporter_role ? ' ('.str_replace('_', ' ', (string) $incident->reporter_role).')' : '';

        return ($incident->reporter?->name ?? 'System').$role;
    }

    /**
     * Where the incident is, best first: the reporter's own fix, else the driver's last fix at the time it was
     * raised, else the pickup. No provider call (the live board polls); the incident page adds a looked-up name.
     *
     * @return array{lat: float, lng: float, source: string, words: string, relative: ?string}|null
     */
    public function point(SafetyIncident $incident, bool $lookup = false): ?array
    {
        $ctx = is_array($incident->context) ? $incident->context : [];
        $stops = $this->stops($incident);

        $candidates = [
            ['reported position', $incident->location_lat, $incident->location_lng],
            ["driver's last position", $ctx['driver_position']['lat'] ?? null, $ctx['driver_position']['lng'] ?? null],
            ['pickup', $stops['pickup']['lat'] ?? null, $stops['pickup']['lng'] ?? null],
        ];

        foreach ($candidates as [$source, $lat, $lng]) {
            if ($lat === null || $lng === null) {
                continue;
            }
            $lat = (float) $lat;
            $lng = (float) $lng;
            $relative = $this->places->relativeTo($lat, $lng, $stops);
            $name = $lookup ? $this->places->name(null, $lat, $lng, true) : null;
            $words = $name !== null && ! str_starts_with($name, 'A pinned point') ? $name : ($relative ?? $this->places->name(null, $lat, $lng));

            return ['lat' => $lat, 'lng' => $lng, 'source' => $source, 'words' => $words, 'relative' => $relative];
        }

        return null;
    }

    /** @return array<string, array{lat: ?float, lng: ?float, label: ?string}> */
    public function stops(SafetyIncident $incident): array
    {
        $ride = $incident->ride;
        $ctx = is_array($incident->context['ride'] ?? null) ? $incident->context['ride'] : [];

        return [
            'pickup' => [
                'lat' => $ride?->pickup_lat !== null ? (float) $ride->pickup_lat : ($ctx['pickup']['lat'] ?? null),
                'lng' => $ride?->pickup_lng !== null ? (float) $ride->pickup_lng : ($ctx['pickup']['lng'] ?? null),
                'label' => $ride?->pickup_address ?? ($ctx['pickup_address'] ?? null),
            ],
            'drop-off' => [
                'lat' => $ride?->dropoff_lat !== null ? (float) $ride->dropoff_lat : ($ctx['dropoff']['lat'] ?? null),
                'lng' => $ride?->dropoff_lng !== null ? (float) $ride->dropoff_lng : ($ctx['dropoff']['lng'] ?? null),
                'label' => $ride?->dropoff_address ?? ($ctx['dropoff_address'] ?? null),
            ],
        ];
    }

    /**
     * Everything the incident map draws: the stops, the reported position (with its accuracy), the driver's last
     * position and trail as captured when the incident was raised.
     *
     * @return array<string, mixed>
     */
    public function map(SafetyIncident $incident): array
    {
        $ctx = is_array($incident->context) ? $incident->context : [];
        $stops = $this->stops($incident);
        $at = fn (?array $p) => ($p['lat'] ?? null) !== null && ($p['lng'] ?? null) !== null ? ['lat' => (float) $p['lat'], 'lng' => (float) $p['lng']] : null;

        return array_filter([
            'pickup' => $at($stops['pickup']),
            'dropoff' => $at($stops['drop-off']),
            'sos' => $incident->location_lat !== null ? ['lat' => (float) $incident->location_lat, 'lng' => (float) $incident->location_lng, 'accuracy' => $incident->location_accuracy_m] : null,
            'driver' => $at($ctx['driver_position'] ?? null),
            'trail' => array_values(array_filter(array_map(fn ($p) => is_array($p) ? $at($p) : null, (array) ($ctx['trail'] ?? [])))),
        ], fn ($v) => $v !== null && $v !== []);
    }
}
