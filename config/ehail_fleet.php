<?php

/*
|--------------------------------------------------------------------------
| R-46 fleet management (docs/R46_FLEET.md)
|--------------------------------------------------------------------------
| Owned by the fleet domain (App\Domain\Fleet). Thresholds for the alerts on the fleet portal. Every alert is
| computed from records (verification, maintenance, availability, rides); none is estimated.
*/

return [
    'alerts' => [
        // A document expiring within this many days is listed as expiring.
        'document_expiry_days' => (int) env('EHAIL_FLEET_DOCUMENT_EXPIRY_DAYS', 30),
        // Service due within this many days or kilometres is "due soon".
        'service_due_days' => (int) env('EHAIL_FLEET_SERVICE_DUE_DAYS', 14),
        'service_due_km' => (int) env('EHAIL_FLEET_SERVICE_DUE_KM', 1000),
        // A driver offline this long while holding a fleet vehicle is flagged (the vehicle is standing idle).
        'idle_assignment_hours' => (int) env('EHAIL_FLEET_IDLE_ASSIGNMENT_HOURS', 24),
        // Unusual cancellations: at least this many driver cancellations in the window, and at least this share
        // of the driver's finished assignments in the same window.
        'cancellation_window_days' => 7,
        'cancellation_min_count' => 3,
        'cancellation_min_share_bp' => 2000,
    ],
];
