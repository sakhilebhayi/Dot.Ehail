<?php

namespace App\Domain\Rides;

use App\Domain\Dispatch\Exceptions\DispatchRefused;
use App\Enums\RideActor;
use App\Models\AuditLog;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The 4-digit trip PIN the passenger reads out and the driver enters to start
 * the trip (architecture section 2).
 *
 * At rest: an HMAC (keyed with APP_KEY, salted with the ride id) for checking,
 * plus an encrypted copy so the passenger's app can show it again. A 4-digit
 * code has 10,000 values, so no hash protects it on its own; the real
 * protection is the attempt limit (ehail_dispatch.pin.max_attempts, then a
 * lock of pin.lock_minutes) and the audit trail of every wrong entry.
 */
class TripPin
{
    /**
     * @return array{pin_hash: string, pin_encrypted: string}
     */
    public function attributesFor(Ride $ride, ?string $pin = null): array
    {
        $pin ??= str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);

        return ['pin_hash' => $this->hash($ride->id, $pin), 'pin_encrypted' => $pin];
    }

    public function hash(int $rideId, string $pin): string
    {
        return hash_hmac('sha256', $rideId.':'.$pin, (string) config('app.key'));
    }

    public function required(Ride $ride): bool
    {
        return $ride->pin_hash !== null && (bool) config('ehail_dispatch.pin.required');
    }

    /**
     * Check an entered PIN. Wrong attempts are counted and audited in their own
     * committed transaction, so they survive the refused transition.
     *
     * @throws DispatchRefused when the PIN is locked or wrong
     */
    public function verify(Ride $ride, User $driver, ?string $entered): bool
    {
        if (! $this->required($ride)) {
            return true;
        }

        return DB::transaction(function () use ($ride, $driver, $entered) {
            $locked = Ride::whereKey($ride->id)->lockForUpdate()->firstOrFail();

            if ($locked->pin_locked_until !== null && $locked->pin_locked_until->isFuture()) {
                throw new DispatchRefused('pin_locked', 'Too many wrong PINs. Try again in a few minutes, or contact support.', 429);
            }

            $ok = is_string($entered) && preg_match('/^\d{4}$/', $entered) === 1
                && hash_equals((string) $locked->pin_hash, $this->hash($locked->id, $entered));

            if ($ok) {
                $locked->forceFill(['pin_attempts' => 0, 'pin_locked_until' => null])->save();

                return true;
            }

            $attempts = $locked->pin_attempts + 1;
            $lock = $attempts >= (int) config('ehail_dispatch.pin.max_attempts');

            $locked->forceFill([
                'pin_attempts' => $lock ? 0 : $attempts,
                'pin_locked_until' => $lock ? now()->addMinutes((int) config('ehail_dispatch.pin.lock_minutes')) : null,
            ])->save();

            app(RideTimeline::class)->append($locked, 'pin_rejected', RideActor::Driver, $driver->id,
                $lock ? 'pin_locked' : 'pin_wrong', ['attempt' => $attempts]);
            AuditLog::record('ride.pin_rejected', $driver, $locked, ['attempt' => $attempts, 'locked' => $lock]);

            return false;
        }, 3) ?: throw new DispatchRefused('pin_wrong', 'That PIN is not right. Ask the passenger for the 4-digit PIN in their app.');
    }
}
