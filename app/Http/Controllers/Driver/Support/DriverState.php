<?php

namespace App\Http\Controllers\Driver\Support;

use App\Domain\Verification\DocumentRequirements;
use App\Domain\Verification\DriverEligibility;
use App\Domain\Verification\Models\VerificationDocument;
use App\Domain\Verification\VerificationService;
use App\Models\DriverProfile;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Collection;

/**
 * What the driver app needs to know about one driver, read from the owning domains (driver profile, vehicles,
 * verification, eligibility). Read-only; every rule is decided by its domain, this only gathers the answers.
 */
final class DriverState
{
    private ?DriverProfile $profile = null;

    private bool $profileLoaded = false;

    /** @var Collection<int, Vehicle>|null */
    private ?Collection $vehicles = null;

    public function __construct(public readonly User $user) {}

    public static function for(User $user): self
    {
        return new self($user);
    }

    public function profile(): ?DriverProfile
    {
        if (! $this->profileLoaded) {
            // DriverProfile carries a user scope (own row); read the driver's own row explicitly.
            $this->profile = DriverProfile::withoutGlobalScope('user')->where('user_id', $this->user->id)->first();
            $this->profileLoaded = true;
        }

        return $this->profile;
    }

    /** @return Collection<int, Vehicle> */
    public function vehicles(): Collection
    {
        if ($this->vehicles === null) {
            $profile = $this->profile();
            $this->vehicles = $profile === null ? collect() : Vehicle::query()->where('driver_profile_id', $profile->id)->orderByDesc('is_active')->orderBy('id')->get();
        }

        return $this->vehicles;
    }

    public function activeVehicle(): ?Vehicle
    {
        return $this->vehicles()->firstWhere('is_active', true);
    }

    public function isApproved(): bool
    {
        return $this->profile()?->status === 'approved';
    }

    /**
     * Go-online eligibility for the active vehicle, from the safety domain's gate (the same one dispatch asks).
     *
     * @return array{allowed: bool, documents_enforced: bool, reasons: list<array{code: string, subject: string, type: ?string, message: string}>}
     */
    public function eligibility(): array
    {
        $vehicle = $this->activeVehicle();

        if ($this->profile() === null) {
            return ['allowed' => false, 'documents_enforced' => (bool) config('ehail_safety.verification.require_documents'), 'reasons' => [
                ['code' => 'no_driver_profile', 'subject' => 'driver', 'type' => null, 'message' => 'Apply to drive first.'],
            ]];
        }

        if ($vehicle === null) {
            return ['allowed' => false, 'documents_enforced' => (bool) config('ehail_safety.verification.require_documents'), 'reasons' => [
                ['code' => 'no_active_vehicle', 'subject' => 'vehicle', 'type' => null, 'message' => 'Add a vehicle and make it your active vehicle.'],
            ]];
        }

        return app(DriverEligibility::class)->canGoOnline($this->user, $vehicle)->toArray();
    }

    /**
     * The document checklist: every required document for the driver and for each vehicle, with the state the
     * verification domain reports and, for a rejected or returned document, the reviewer's reason.
     *
     * @return array{driver: array<string, mixed>|null, vehicles: list<array<string, mixed>>, counts: array{total: int, done: int, waiting: int, action: int}}
     */
    public function documents(): array
    {
        $profile = $this->profile();

        if ($profile === null) {
            return ['driver' => null, 'vehicles' => [], 'counts' => ['total' => 0, 'done' => 0, 'waiting' => 0, 'action' => 0]];
        }

        $verification = app(VerificationService::class);
        $requirements = collect(app(DocumentRequirements::class)->types());
        $driver = $verification->driverStatus($profile);
        $vehicles = $this->vehicles()->map(fn (Vehicle $v) => ['vehicle' => $v] + $verification->vehicleStatus($v))->values()->all();

        $ids = collect([$driver, ...$vehicles])->flatMap(fn (array $s) => collect($s['items'])->pluck('document_id'))->filter()->all();
        $docs = VerificationDocument::query()->whereIn('id', $ids)->get()->keyBy('id');

        $decorate = function (array $status) use ($docs, $requirements): array {
            $status['items'] = array_map(function (array $item) use ($docs, $requirements) {
                $doc = $item['document_id'] ? $docs->get($item['document_id']) : null;
                $rule = (array) $requirements->get($item['type'], []);

                return $item + [
                    'chip' => self::chipFor($item + ['assigned' => $doc?->assigned_reviewer_id !== null]),
                    'reason' => $doc && in_array($item['state'], ['rejected', 'resubmission_requested'], true)
                        ? ($doc->toPublicArray()['reject_reason_label'] ?? null) ?? $doc->reject_reason
                        : null,
                    'review_note' => $doc && in_array($item['state'], ['rejected', 'resubmission_requested'], true) ? $doc->review_note : null,
                    'submitted_at' => $doc?->submitted_at,
                    'expiry_required' => (bool) ($rule['expiry_required'] ?? false),
                    'kinds' => (array) ($rule['kinds'] ?? ['image', 'pdf']),
                ];
            }, $status['items']);

            return $status;
        };

        $driver = $decorate($driver);
        $vehicles = array_map($decorate, $vehicles);

        $all = collect([$driver, ...$vehicles])->flatMap(fn (array $s) => $s['items']);

        return [
            'driver' => $driver,
            'vehicles' => $vehicles,
            'counts' => [
                'total' => $all->count(),
                'done' => $all->where('satisfied', true)->count(),
                'waiting' => $all->where('state', 'pending_review')->count(),
                'action' => $all->filter(fn ($i) => ! $i['satisfied'] && $i['state'] !== 'pending_review')->count(),
            ],
        ];
    }

    /**
     * One status chip per document: a word and a tone, never colour alone.
     *
     * @param  array<string, mixed>  $item
     * @return array{label: string, tone: string}
     */
    public static function chipFor(array $item): array
    {
        if ($item['satisfied'] ?? false) {
            if ($item['renewal_pending'] ?? false) {
                return ['label' => 'Approved, renewal in review', 'tone' => 'go'];
            }

            return ($item['expiring_soon'] ?? false) ? ['label' => 'Expiring soon', 'tone' => 'caution'] : ['label' => 'Approved', 'tone' => 'go'];
        }

        return match ($item['state'] ?? 'missing') {
            // Waiting for a person: "Submitted" until a reviewer picks it up, then "In review".
            'pending_review' => ($item['assigned'] ?? false) ? ['label' => 'In review', 'tone' => 'info'] : ['label' => 'Submitted', 'tone' => 'info'],
            'rejected' => ['label' => 'Rejected', 'tone' => 'stop'],
            'resubmission_requested' => ['label' => 'Send again', 'tone' => 'stop'],
            'expired' => ['label' => 'Expired', 'tone' => 'stop'],
            'too_old' => ['label' => 'Photo too old', 'tone' => 'caution'],
            default => ['label' => 'Missing', 'tone' => 'neutral'],
        };
    }
}
