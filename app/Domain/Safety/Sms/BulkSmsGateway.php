<?php

namespace App\Domain\Safety\Sms;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * BulkSMS JSON REST API v1 (https://www.bulksms.com/developer/json/v1/): POST /v1/messages with
 * HTTP Basic auth (API token id and secret), body {"to": "+27...", "body": "..."}; a 201 answer
 * carries an array of message objects with an "id". OFF unless EHAIL_SMS_DRIVER=bulksms and both
 * token values are set. Short timeout; logs never contain the number or the text.
 */
class BulkSmsGateway implements SmsGateway
{
    /** @param array{endpoint?: string, token_id?: ?string, token_secret?: ?string, sender?: ?string} $config */
    public function __construct(private readonly array $config, private readonly int $timeout = 5) {}

    public function isConfigured(): bool
    {
        return filled($this->config['token_id'] ?? null) && filled($this->config['token_secret'] ?? null);
    }

    public function name(): string
    {
        return 'bulksms';
    }

    public function send(string $to, string $message): SmsResult
    {
        if (! $this->isConfigured()) {
            return SmsResult::notConfigured();
        }

        if (! preg_match('/^\+[1-9]\d{7,14}$/', $to)) {
            return SmsResult::failed('invalid_number');
        }

        $body = ['to' => $to, 'body' => mb_substr($message, 0, 459), 'encoding' => 'UNICODE'];

        if (filled($this->config['sender'] ?? null)) {
            $body['from'] = (string) $this->config['sender'];
        }

        try {
            $response = Http::timeout($this->timeout)
                ->connectTimeout($this->timeout)
                ->withBasicAuth((string) $this->config['token_id'], (string) $this->config['token_secret'])
                ->acceptJson()
                ->post((string) ($this->config['endpoint'] ?? 'https://api.bulksms.com/v1/messages'), $body);
        } catch (ConnectionException) {
            Log::warning('BulkSMS unreachable.');

            return SmsResult::failed('provider_unreachable');
        } catch (Throwable $e) {
            Log::warning('BulkSMS call failed.', ['error' => class_basename($e)]);

            return SmsResult::failed('provider_error');
        }

        if ($response->status() === 201 || $response->successful()) {
            $first = $response->json('0') ?? $response->json();

            return SmsResult::sent(is_array($first) && isset($first['id']) ? (string) $first['id'] : null);
        }

        Log::warning('BulkSMS refused the message.', ['status' => $response->status()]);

        return SmsResult::failed('provider_http_'.$response->status());
    }
}
