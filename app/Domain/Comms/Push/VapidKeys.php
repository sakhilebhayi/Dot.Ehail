<?php

namespace App\Domain\Comms\Push;

use OpenSSLAsymmetricKey;
use Throwable;

/**
 * The server's VAPID identity (RFC 8292) from EHAIL_VAPID_PUBLIC_KEY / EHAIL_VAPID_PRIVATE_KEY
 * (base64url raw keys; `php artisan ehail:comms-vapid-keys` makes a pair). Without both, push is
 * "not configured" everywhere: nothing is queued and the screen says so.
 */
final class VapidKeys
{
    public function configured(): bool
    {
        return $this->publicKey() !== null && $this->privateKey() !== null;
    }

    public function publicKeyBase64(): ?string
    {
        return $this->configured() ? (string) config('ehail_comms.push.public_key') : null;
    }

    public function subject(): string
    {
        return (string) config('ehail_comms.push.subject', 'mailto:no-reply@infodot.co.za');
    }

    /** Authorization header value for a push service origin ("https://fcm.googleapis.com"). */
    public function authorization(string $audience, ?int $expiresAt = null): string
    {
        $header = Base64Url::encode((string) json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $claims = Base64Url::encode((string) json_encode([
            'aud' => $audience,
            'exp' => $expiresAt ?? now()->addHours(12)->timestamp,
            'sub' => $this->subject(),
        ], JSON_UNESCAPED_SLASHES));
        $signature = Base64Url::encode(EcKeys::signEs256($header.'.'.$claims, $this->key()));

        return 'vapid t='.$header.'.'.$claims.'.'.$signature.', k='.config('ehail_comms.push.public_key');
    }

    private function key(): OpenSSLAsymmetricKey
    {
        return EcKeys::privateKey((string) $this->privateKey(), (string) $this->publicKey());
    }

    private function publicKey(): ?string
    {
        $raw = Base64Url::decode((string) config('ehail_comms.push.public_key'));

        return strlen($raw) === 65 && $raw[0] === "\x04" ? $raw : null;
    }

    private function privateKey(): ?string
    {
        $raw = Base64Url::decode((string) config('ehail_comms.push.private_key'));

        if (strlen($raw) !== 32 || $this->publicKey() === null) {
            return null;
        }

        try {
            EcKeys::privateKey($raw, (string) $this->publicKey());
        } catch (Throwable) {
            return null;
        }

        return $raw;
    }
}
