<?php

namespace App\Domain\Comms\Push;

use App\Domain\Comms\Models\PushSubscription;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Sends one encrypted message to one subscription (RFC 8030 + 8291 + 8292). Only https endpoints
 * on a known push service are called (config ehail_comms.push.allowed_hosts), so a forged
 * subscription cannot make the server call an arbitrary host. Never throws; logs carry no
 * endpoint, key or content.
 */
final class WebPushClient
{
    public function __construct(private readonly VapidKeys $vapid, private readonly PayloadEncrypter $encrypter) {}

    public static function endpointAllowed(string $endpoint): bool
    {
        $parts = parse_url($endpoint);

        if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $host = strtolower($parts['host']);

        foreach ((array) config('ehail_comms.push.allowed_hosts', []) as $allowed) {
            $allowed = strtolower(ltrim((string) $allowed, '.'));
            if ($allowed !== '' && ($host === $allowed || str_ends_with($host, '.'.$allowed))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $payload  title, body, url, tag
     * @param  'very-low'|'low'|'normal'|'high'  $urgency
     */
    public function send(PushSubscription $subscription, array $payload, string $urgency = 'normal', ?string $topic = null): PushResult
    {
        if (! $this->vapid->configured()) {
            return new PushResult(PushResult::FAILED, 'push_not_configured');
        }

        $endpoint = (string) $subscription->endpoint;

        if (! self::endpointAllowed($endpoint)) {
            return new PushResult(PushResult::GONE, 'endpoint_not_allowed');
        }

        try {
            $body = $this->encrypter->encrypt(
                (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                Base64Url::decode((string) $subscription->p256dh),
                Base64Url::decode((string) $subscription->auth_secret),
            );
        } catch (Throwable) {
            return new PushResult(PushResult::GONE, 'subscription_keys_invalid');
        }

        $parts = parse_url($endpoint);
        $audience = 'https://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        $timeout = (int) config('ehail_comms.push.timeout', 5);

        $headers = [
            'TTL' => (string) (int) config('ehail_comms.push.ttl', 3600),
            'Content-Encoding' => 'aes128gcm',
            'Urgency' => $urgency,
            'Authorization' => $this->vapid->authorization($audience),
        ];

        if ($topic !== null) {
            // Topic: up to 32 base64url characters; a newer message with the same topic replaces
            // an undelivered older one at the push service.
            $headers['Topic'] = substr(Base64Url::encode(hash('sha256', $topic, true)), 0, 32);
        }

        try {
            $response = Http::timeout($timeout)->connectTimeout($timeout)
                ->withHeaders($headers)
                ->withBody($body, 'application/octet-stream')
                ->post($endpoint);
        } catch (ConnectionException) {
            return new PushResult(PushResult::RETRY, 'push_service_unreachable');
        } catch (Throwable $e) {
            return new PushResult(PushResult::RETRY, 'push_error_'.strtolower(class_basename($e)));
        }

        $status = $response->status();

        return match (true) {
            $status >= 200 && $status < 300 => new PushResult(PushResult::SENT, null, $this->messageId($response->header('Location'))),
            in_array($status, [404, 410], true) => new PushResult(PushResult::GONE, 'subscription_gone'),
            $status === 429 || $status >= 500 => new PushResult(PushResult::RETRY, 'push_http_'.$status, null, is_numeric($response->header('Retry-After')) ? (int) $response->header('Retry-After') : null),
            default => new PushResult(PushResult::FAILED, 'push_http_'.$status),
        };
    }

    private function messageId(?string $location): ?string
    {
        if ($location === null || $location === '') {
            return null;
        }

        // Keep only the last path segment (the push service's message id), not the URL.
        $path = (string) parse_url($location, PHP_URL_PATH);

        return mb_substr(basename($path), 0, 120) ?: null;
    }
}
