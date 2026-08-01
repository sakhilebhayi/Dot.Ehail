<?php

namespace App\Notifications;

use App\Models\DriverProfile;
use Illuminate\Notifications\Notification;

/**
 * In-app (database channel only) notification for a new driver application
 * (a `DriverProfile` created with `status = pending`). Not yet wired to any
 * automatic trigger — dispatch manually via
 * `$operator->notify(new DriverApplicationSubmittedNotification($driverProfile))`
 * until driver onboarding has real observer/event wiring (see wiki.md §8).
 */
class DriverApplicationSubmittedNotification extends Notification
{
    public function __construct(public DriverProfile $driverProfile)
    {
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type'              => 'driver_application_submitted',
            'title'             => 'New driver application',
            'message'           => "{$this->driverProfile->user?->name} applied to drive (license {$this->driverProfile->license_number}).",
            'driver_profile_id' => $this->driverProfile->id,
            'url'               => route('drivers.show', $this->driverProfile),
        ];
    }
}
