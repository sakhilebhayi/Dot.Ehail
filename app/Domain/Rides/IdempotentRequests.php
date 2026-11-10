<?php

namespace App\Domain\Rides;

use App\Domain\Dispatch\Exceptions\DispatchRefused;
use App\Domain\Rides\Models\IdempotencyKey;
use App\Models\User;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Idempotency-Key handling for state-creating requests (ride requests,
 * scheduled bookings). The first request with a key reserves it (a unique
 * row per user, scope and key); its response is stored for
 * ehail_dispatch.idempotency_hours and replayed to any retry with the same
 * key and the same body. The same key with a different body is refused,
 * and a retry while the first is still running gets 409 with Retry-After.
 * Server errors release the key so the client can retry.
 */
class IdempotentRequests
{
    /**
     * @param  Closure(): array{0: int, 1: array<string, mixed>, 2?: int|null}  $handler  returns [status, body, ride id]
     * @return array{status: int, body: array<string, mixed>, replayed: bool}
     */
    public function run(User $user, string $scope, ?string $key, array $payload, Closure $handler): array
    {
        if ($key === null || $key === '') {
            [$status, $body] = $handler();

            return ['status' => $status, 'body' => $body, 'replayed' => false];
        }

        if (preg_match('/^[A-Za-z0-9_\-:.]{8,100}$/', $key) !== 1) {
            throw new DispatchRefused('idempotency_key_invalid', 'Idempotency-Key must be 8 to 100 letters, digits or - _ : .', 400);
        }

        ksort($payload);
        $hash = hash('sha256', json_encode($payload));

        IdempotencyKey::where('user_id', $user->id)->where('scope', $scope)->where('key', $key)
            ->where('expires_at', '<', now())->delete();

        try {
            $row = IdempotencyKey::create([
                'user_id' => $user->id, 'scope' => $scope, 'key' => $key, 'request_hash' => $hash,
                'expires_at' => now()->addHours((int) config('ehail_dispatch.idempotency_hours')),
            ]);
        } catch (UniqueConstraintViolationException) {
            $existing = IdempotencyKey::where('user_id', $user->id)->where('scope', $scope)->where('key', $key)->firstOrFail();

            if (! hash_equals($existing->request_hash, $hash)) {
                throw new DispatchRefused('idempotency_key_reused', 'This Idempotency-Key was already used for a different request.', 422);
            }

            if ($existing->response_status === null) {
                throw new DispatchRefused('request_in_progress', 'The first request with this Idempotency-Key is still being processed.', 409);
            }

            return ['status' => $existing->response_status, 'body' => $existing->response_body ?? [], 'replayed' => true];
        }

        try {
            $result = $handler();
        } catch (DispatchRefused $e) {
            if ($e->status >= 500) {
                $row->delete();
            } else {
                $row->forceFill(['response_status' => $e->status, 'response_body' => ['error' => $e->reason, 'message' => $e->getMessage()]])->save();
            }

            throw $e;
        } catch (\Throwable $e) {
            $row->delete();

            throw $e;
        }

        [$status, $body] = $result;
        $row->forceFill(['response_status' => $status, 'response_body' => $body, 'ride_id' => $result[2] ?? null])->save();

        return ['status' => $status, 'body' => $body, 'replayed' => false];
    }

    public function purgeExpired(): int
    {
        return IdempotencyKey::where('expires_at', '<', now())->delete();
    }
}
