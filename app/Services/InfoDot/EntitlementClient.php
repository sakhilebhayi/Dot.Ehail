<?php

namespace App\Services\InfoDot;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asks InfoDot whether a company still holds this platform (R-50).
 *
 * One question, about one company, answered from InfoDot's read endpoint with this platform's own service secret.
 * The answer (does the company hold the platform, and R-61: has InfoDot switched the platform off, with its message)
 * is kept for sixty seconds per company, so a switch made at InfoDot takes effect within about a minute; a failure
 * for one minute. Every clear answer is also remembered for six hours: when InfoDot gives no clear answer (down,
 * slow, refusing, a body this app cannot read, no secret configured) the last clear answer under six hours old is
 * used. With none, the answer is "unknown", and unknown never locks a company out: the caller carries on as if the
 * check did not exist. A failure is logged once per window.
 */
class EntitlementClient
{
    public const ANSWER_TTL = 60;

    public const FAILURE_TTL = 60;

    /** How long the last clear answer is used when InfoDot cannot be reached. */
    public const LAST_KNOWN_TTL = 21600;

    public function enabled(): bool
    {
        return (bool) config('ecosystem.infodot.entitlement_check', true);
    }

    /**
     * @return array{entitled: bool, status: string, available: bool, message: string|null}|null null when InfoDot gave no usable answer
     */
    public function forCompany(string $uuid): ?array
    {
        $key = 'infodot:entitlement:'.$this->platform().':'.$uuid;

        try {
            $cached = Cache::get($key);
        } catch (Throwable) {
            $cached = null;
        }

        if (is_array($cached)) {
            return $this->shape($cached);
        }

        $answer = $this->ask($uuid);

        if (is_string($answer)) {
            Log::warning('InfoDot entitlement check gave no answer; carrying on without it.', ['company' => $uuid, 'reason' => $answer]);

            // No clear answer: the last clear one under six hours old stands, so a platform last told "unavailable"
            // stays unavailable through an outage. With none, unknown, which is never a refusal.
            try {
                $last = Cache::get($key.':last');
            } catch (Throwable) {
                $last = null;
            }

            $shaped = is_array($last) && is_array($last['answer'] ?? null) && time() - (int) ($last['at'] ?? 0) < self::LAST_KNOWN_TTL
                ? $this->shape($last['answer'])
                : null;

            $this->remember($key, $shaped ?? ['unknown' => true], self::FAILURE_TTL);

            return $shaped;
        }

        $this->remember($key, $answer, self::ANSWER_TTL);
        $this->remember($key.':last', ['answer' => $answer, 'at' => time()], self::LAST_KNOWN_TTL);

        return $answer;
    }

    /**
     * @param  array<string, mixed>  $cached
     * @return array{entitled: bool, status: string, available: bool, message: string|null}|null
     */
    private function shape(array $cached): ?array
    {
        if (! isset($cached['entitled'])) {
            return null;
        }

        return [
            'entitled' => (bool) $cached['entitled'],
            'status' => (string) ($cached['status'] ?? ''),
            'available' => ($cached['available'] ?? true) !== false,
            'message' => is_string($cached['message'] ?? null) && $cached['message'] !== '' ? $cached['message'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function remember(string $key, array $value, int $seconds): void
    {
        try {
            Cache::put($key, $value, $seconds);
        } catch (Throwable) {
            // A cache that cannot be written only means the next request asks again.
        }
    }

    /**
     * @return array{entitled: bool, status: string, available: bool, message: string|null}|string the answer, or why there is none
     */
    private function ask(string $uuid): array|string
    {
        $platform = $this->platform();
        $credential = (string) config('ecosystem.infodot.secret');
        $url = $this->url($uuid);

        if ($credential === '' || $platform === '' || $url === null) {
            return 'not configured';
        }

        try {
            $response = Http::withToken($credential)
                ->withHeaders(['X-Dot-Platform' => $platform])
                ->acceptJson()
                ->connectTimeout(2)
                ->timeout(max(1, min(3, (int) config('ecosystem.infodot.timeout', 5))))
                ->get($url, ['platform' => $platform]);
        } catch (Throwable $e) {
            return 'unreachable ('.class_basename($e).')';
        }

        if (! $response->successful()) {
            return 'status '.$response->status();
        }

        $body = $response->json();

        if (! is_array($body) || ($body['platform'] ?? null) !== $platform || ! is_array($body['platforms'] ?? null)) {
            return 'malformed body';
        }

        foreach ($body['platforms'] as $row) {
            if (is_array($row) && ($row['key'] ?? null) === $platform) {
                if (! is_bool($row['entitled'] ?? null)) {
                    return 'malformed body';
                }

                return [
                    'entitled' => $row['entitled'],
                    'status' => is_string($row['status'] ?? null) ? $row['status'] : '',
                    // R-61: an InfoDot from before contract 2 gives no `available`, which is not a switch-off.
                    'available' => ($row['available'] ?? true) !== false,
                    'message' => is_string($row['message'] ?? null) && $row['message'] !== '' ? mb_substr($row['message'], 0, 400) : null,
                ];
            }
        }

        return 'own row missing';
    }

    private function platform(): string
    {
        return (string) config('ecosystem.infodot.platform');
    }

    /** The read endpoint sits beside the redeem endpoint: .../api/ecosystem/companies/{uuid}/platforms. */
    private function url(string $uuid): ?string
    {
        $redeem = (string) config('ecosystem.infodot.redeem_url');

        if ($redeem === '' || ! preg_match('#/redeem/?$#', $redeem)) {
            return null;
        }

        return preg_replace('#/redeem/?$#', '', $redeem).'/companies/'.rawurlencode($uuid).'/platforms';
    }
}
