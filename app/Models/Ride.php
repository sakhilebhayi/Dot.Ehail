<?php

namespace App\Models;

use App\Domain\Dispatch\Models\RideOffer;
use App\Domain\Rides\Models\RideEvent;
use App\Enums\PaymentStatus;
use App\Enums\RideActor;
use App\Enums\RideStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ride extends Model
{
    protected $fillable = [
        'passenger_id', 'driver_id', 'vehicle_id',
        'pickup_address', 'pickup_lat', 'pickup_lng',
        'dropoff_address', 'dropoff_lat', 'dropoff_lng',
        'status', 'vehicle_type', 'estimated_fare', 'final_fare',
        'distance_km', 'requested_at', 'accepted_at', 'completed_at',
        'started_at', 'cancelled_at', 'expired_at', 'needs_review_at',
        'cancelled_by', 'cancel_reason', 'cancel_note', 'reassignment_count',
        'final_fare_reason', 'final_fare_set_by',
        // R-46 dispatch (docs/R46_DISPATCH.md)
        'fare_quote_id', 'quote_snapshot', 'category_code', 'quoted_total_minor', 'final_fare_minor',
        'cancellation_fee_minor', 'currency', 'route_distance_m', 'route_duration_s',
        'payment_method_kind', 'payment_method_ref',
        'search_started_at', 'search_round', 'offers_sent', 'dispatch_priority', 'next_dispatch_at',
        'pending_offer_expires_at', 'driver_arriving_at', 'arrived_at', 'no_show_at', 'failed_at',
        'failure_reason', 'needs_review_reason', 'scheduled_for', 'requote_required_at',
        'requote_quote_id', 'requote_total_minor', 'reminder_sent_at',
    ];

    /**
     * The PIN never leaves through serialisation; RideFeed shows the
     * decrypted PIN to the passenger only. The guard columns are internal.
     */
    protected $hidden = ['pin_hash', 'pin_encrypted', 'dispatch_lease_owner'];

    protected $casts = [
        'requested_at' => 'datetime',
        'accepted_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'expired_at' => 'datetime',
        'needs_review_at' => 'datetime',
        'estimated_fare' => 'decimal:2',
        'final_fare' => 'decimal:2',
        'fare_waived_at' => 'datetime',
        'reassignment_count' => 'integer',
        'version' => 'integer',
        'quote_snapshot' => 'array',
        'quoted_total_minor' => 'integer',
        'final_fare_minor' => 'integer',
        'cancellation_fee_minor' => 'integer',
        'requote_total_minor' => 'integer',
        'route_distance_m' => 'integer',
        'route_duration_s' => 'integer',
        'pin_encrypted' => 'encrypted',
        'pin_attempts' => 'integer',
        'pin_locked_until' => 'datetime',
        'search_started_at' => 'datetime',
        'search_round' => 'integer',
        'offers_sent' => 'integer',
        'dispatch_priority' => 'integer',
        'next_dispatch_at' => 'datetime',
        'pending_offer_expires_at' => 'datetime',
        'dispatch_lease_until' => 'datetime',
        'driver_arriving_at' => 'datetime',
        'arrived_at' => 'datetime',
        'no_show_at' => 'datetime',
        'failed_at' => 'datetime',
        'scheduled_for' => 'datetime',
        'requote_required_at' => 'datetime',
        'reminder_sent_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // The waiting window (ehail.ride_expiry_minutes) is measured from
        // requested_at, so a ride never exists without one.
        static::creating(function (Ride $ride): void {
            $ride->requested_at ??= now();
        });

        static::saving(function (Ride $ride): void {
            if ($ride->exists && ! $ride->isDirty()) {
                return;
            }

            // Feed version: epoch ms, strictly increasing per ride, so a poller's
            // "since" comparison never misses a change made in the same millisecond.
            $ride->version = max((int) now()->getPreciseTimestamp(3), (int) $ride->version + 1);

            $ride->syncConcurrencyGuards();
        });
    }

    /**
     * Keep the nullable-unique guard columns in step with the status (see the
     * 2026_11_10_400000 migration): a value while the ride holds its driver /
     * is active for its passenger, NULL otherwise. Set on entry into the
     * holding states only, so a legacy duplicate that predates the guard can
     * still move between active states and be finished.
     */
    public function syncConcurrencyGuards(): void
    {
        if ($this->exists && ! $this->isDirty('status') && ! $this->isDirty('driver_id')) {
            return;
        }

        $status = RideStatus::fromStored((string) ($this->status ?? RideStatus::Requested->value));
        $was = $this->exists ? RideStatus::fromStored((string) $this->getOriginal('status')) : null;

        if ($status->holdsDriver() && $this->driver_id !== null) {
            if ($was === null || ! $was->holdsDriver() || $this->isDirty('driver_id')) {
                $this->active_driver_id = $this->driver_id;
            }
        } else {
            $this->active_driver_id = null;
        }

        if ($status->isActiveForPassenger()) {
            if ($was === null || ! $was->isActiveForPassenger()) {
                $this->active_passenger_id = $this->passenger_id;
            }
        } else {
            $this->active_passenger_id = null;
        }
    }

    public function statusEnum(): RideStatus
    {
        return RideStatus::fromStored($this->status);
    }

    public function events(): HasMany
    {
        return $this->hasMany(RideEvent::class)->orderBy('sequence');
    }

    public function offers(): HasMany
    {
        return $this->hasMany(RideOffer::class);
    }

    /**
     * payment_status is deliberately not fillable: it only changes through App\Services\Ehail\RidePayments
     * and RideLifecycle, never from request input.
     */
    public function paymentStatusEnum(): PaymentStatus
    {
        return PaymentStatus::tryFrom((string) $this->payment_status) ?? PaymentStatus::NotDue;
    }

    /**
     * The part $user plays on this ride, or null if they are not a party to
     * it. A platform operator who is neither passenger nor driver acts as the
     * operator.
     */
    public function actorFor(User $user): ?RideActor
    {
        return match (true) {
            $user->id === $this->passenger_id => RideActor::Passenger,
            $this->driver_id !== null && $user->id === $this->driver_id => RideActor::Driver,
            $user->isPlatformOperator() => RideActor::Operator,
            default => null,
        };
    }

    /**
     * Rides a user is entitled to see: their own (as passenger or as the
     * assigned driver), the rides of drivers in fleets they own (approved
     * fleets only), and everything for a platform operator.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isPlatformOperator()) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($user) {
            $q->where('passenger_id', $user->id)
                ->orWhere('driver_id', $user->id)
                ->orWhereIn('driver_id', static::fleetDriverUserIds($user));
        });
    }

    /**
     * Rides driven by drivers belonging to approved fleets the user owns.
     */
    public function scopeDrivenInFleetsOwnedBy(Builder $query, User $user): Builder
    {
        return $query->whereIn('driver_id', static::fleetDriverUserIds($user));
    }

    /**
     * user_ids of every driver profile in an approved fleet owned by $user.
     * DriverProfile carries HasUserScope, which would silently filter this
     * to the authenticated user's own row, so it is bypassed explicitly.
     */
    public static function fleetDriverUserIds(User $user): Builder
    {
        return DriverProfile::withoutGlobalScope('user')
            ->select('user_id')
            ->whereIn('fleet_id', Fleet::approved()->where('owner_user_id', $user->id)->select('id'));
    }

    public function passenger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'passenger_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(RidePayment::class);
    }

    public function waivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fare_waived_by');
    }

    public function ratings(): HasMany
    {
        return $this->hasMany(RideRating::class);
    }
}
