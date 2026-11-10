<?php

namespace App\Domain\Fleet\Models;

use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One service, repair, inspection or tyre change, with the odometer reading and when the next service is due.
 */
class VehicleMaintenanceRecord extends Model
{
    public const KINDS = [
        'service' => 'Service',
        'repair' => 'Repair',
        'inspection' => 'Inspection',
        'tyres' => 'Tyres',
        'other' => 'Other',
    ];

    protected $guarded = ['id'];

    protected $casts = [
        'performed_on' => 'date',
        'next_service_due_on' => 'date',
        'odometer_km' => 'integer',
        'next_service_due_km' => 'integer',
        'cost_minor' => 'integer',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
