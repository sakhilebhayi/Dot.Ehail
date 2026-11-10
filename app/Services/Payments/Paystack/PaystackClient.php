<?php

namespace App\Services\Payments\Paystack;

use App\Exceptions\Payments\PaymentGatewayException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The only code that talks to api.paystack.co. Server to server, bearer auth with the secret key, a 10
 * second timeout, no redirects followed, no retries, base URL pinned to https. It returns typed results and
 * turns every failure into a PaymentGatewayException whose message is safe to show a person.
 *
 * Logging: a failure logs the endpoint name, the HTTP status and our own reference. It never logs the key,
 * the request body (payer email, callback address), the response body, the checkout address or the access code.
 */
class PaystackClient
{
    public function __construct(private PaystackConfig $config) {}

    /**
     * POST /transaction/initialize. The caller supplies the amount (integer ZAR cents, from the stored
     * fare), our unique reference and the page Paystack returns the payer to. `$metadata` is sent as
     * stringified JSON and must hold ids only.
     *
     * @param  array<string, scalar>  $metadata
     *
     * @throws PaymentGatewayException
     */
    public function initializeTransaction(string $payerEmail, int $amountCents, string $currency, string $reference, string $callbackUrl, array $metadata, ?array $channels = null): PaystackInitialization
    {
        $body = [
            'email' => $payerEmail,
            'amount' => $amountCents,
            'currency' => $currency,
            'reference' => $reference,
            'callback_url' => $callbackUrl,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
        ];

        if ($channels !== null) {
            $body['channels'] = array_values($channels);
        }

        $data = $this->send('initialize', $reference, fn (PendingRequest $http) => $http->post('/transaction/initialize', $body));

        $url = $data['authorization_url'] ?? null;

        if (! is_string($url) || ! $this->isPaystackCheckoutUrl($url) || ! is_string($data['access_code'] ?? null) || ($data['reference'] ?? null) !== $reference) {
            Log::warning('paystack.invalid_response', ['endpoint' => 'initialize', 'reference' => $reference]);

            throw PaymentGatewayException::because('invalid_response', 'Paystack sent an answer we could not use. Nothing was charged; please try again.');
        }

        return new PaystackInitialization($url, $reference);
    }

    /**
     * GET /transaction/verify/{reference}.
     *
     * @throws PaymentGatewayException
     */
    public function verifyTransaction(string $reference): PaystackTransaction
    {
        $data = $this->send('verify', $reference, fn (PendingRequest $http) => $http->get('/transaction/verify/'.rawurlencode($reference)));

        try {
            return PaystackTransaction::fromData($data);
        } catch (PaymentGatewayException $e) {
            Log::warning('paystack.invalid_response', ['endpoint' => 'verify', 'reference' => $reference]);

            throw $e;
        }
    }

    /**
     * POST /transaction/charge_authorization: charge a saved, reusable card authorisation server side.
     * Paystack answers 200 with data.status "success" or "failed" (or another non-final status); a 4xx
     * means it refused the request (bad or inactive authorisation, duplicate reference).
     *
     * @param  array<string, scalar>  $metadata
     *
     * @throws PaymentGatewayException
     */
    public function chargeAuthorization(string $payerEmail, int $amountMinor, string $currency, string $authorizationCode, string $reference, array $metadata): PaystackTransaction
    {
        $data = $this->send('charge_authorization', $reference, fn (PendingRequest $http) => $http->post('/transaction/charge_authorization', [
            'email' => $payerEmail,
            'amount' => $amountMinor,
            'currency' => $currency,
            'authorization_code' => $authorizationCode,
            'reference' => $reference,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
        ]));

        return PaystackTransaction::fromData($data);
    }

    /**
     * POST /refund for a completed transaction (by our reference). Amount in minor units; omit for full.
     *
     * @throws PaymentGatewayException
     */
    public function createRefund(string $transactionReference, int $amountMinor, string $currency, string $merchantNote): PaystackRefund
    {
        $data = $this->send('refund', $transactionReference, fn (PendingRequest $http) => $http->post('/refund', [
            'transaction' => $transactionReference,
            'amount' => $amountMinor,
            'currency' => $currency,
            'merchant_note' => mb_substr($merchantNote, 0, 200),
        ]));

        return PaystackRefund::fromData($data);
    }

    /**
     * GET /refund/{id}.
     *
     * @throws PaymentGatewayException
     */
    public function fetchRefund(string $refundId): PaystackRefund
    {
        $data = $this->send('refund_fetch', $refundId, fn (PendingRequest $http) => $http->get('/refund/'.rawurlencode($refundId)));

        return PaystackRefund::fromData($data);
    }

    /**
     * POST /bank/validate (South Africa): confirms the account exists, is open and belongs to the holder.
     * The identity number is sent to Paystack for this one call and never stored here.
     *
     * @return array{verified: bool, message: string}
     *
     * @throws PaymentGatewayException
     */
    public function validateBankAccount(string $accountName, string $accountNumber, string $accountType, string $bankCode, string $countryCode, string $documentType, string $documentNumber): array
    {
        $data = $this->send('bank_validate', 'bank-validate', fn (PendingRequest $http) => $http->post('/bank/validate', [
            'account_name' => $accountName,
            'account_number' => $accountNumber,
            'account_type' => $accountType,
            'bank_code' => $bankCode,
            'country_code' => $countryCode,
            'document_type' => $documentType,
            'document_number' => $documentNumber,
        ]));

        return [
            'verified' => ($data['verified'] ?? false) === true,
            'message' => is_string($data['verificationMessage'] ?? null) ? mb_substr($data['verificationMessage'], 0, 200) : '',
        ];
    }

    /**
     * POST /transferrecipient (type "basa" for a South African bank account). Returns the recipient code.
     *
     * @throws PaymentGatewayException
     */
    public function createTransferRecipient(string $name, string $accountNumber, string $bankCode, string $currency): string
    {
        $data = $this->send('transfer_recipient', 'recipient', fn (PendingRequest $http) => $http->post('/transferrecipient', [
            'type' => $currency === 'ZAR' ? 'basa' : 'nuban',
            'name' => $name,
            'account_number' => $accountNumber,
            'bank_code' => $bankCode,
            'currency' => $currency,
        ]));

        $code = $data['recipient_code'] ?? null;

        if (! is_string($code) || $code === '') {
            throw PaymentGatewayException::because('invalid_response', 'Paystack sent a recipient we could not read.');
        }

        return $code;
    }

    /**
     * POST /transfer from the Paystack balance. The reference makes a repeat of the same request
     * idempotent on Paystack's side (lowercase alphanumeric, dashes and underscores, 16+ chars).
     *
     * @throws PaymentGatewayException
     */
    public function initiateTransfer(int $amountMinor, string $recipientCode, string $reference, string $reason, string $currency): PaystackTransfer
    {
        $data = $this->send('transfer', $reference, fn (PendingRequest $http) => $http->post('/transfer', [
            'source' => 'balance',
            'amount' => $amountMinor,
            'recipient' => $recipientCode,
            'reference' => $reference,
            'reason' => mb_substr($reason, 0, 100),
            'currency' => $currency,
        ]));

        return PaystackTransfer::fromData($data);
    }

    /**
     * GET /transfer/verify/{reference}.
     *
     * @throws PaymentGatewayException
     */
    public function verifyTransfer(string $reference): PaystackTransfer
    {
        $data = $this->send('transfer_verify', $reference, fn (PendingRequest $http) => $http->get('/transfer/verify/'.rawurlencode($reference)));

        return PaystackTransfer::fromData($data);
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     * @return array<string, mixed> Paystack's `data` object
     */
    private function send(string $endpoint, string $reference, callable $call): array
    {
        $http = Http::baseUrl($this->config->baseUrl())
            ->withToken($this->config->secretKey())
            ->acceptJson()
            ->asJson()
            ->connectTimeout($this->config->timeout())
            ->timeout($this->config->timeout())
            ->withoutRedirecting();

        try {
            $response = $call($http);
        } catch (ConnectionException) {
            Log::warning('paystack.unreachable', ['endpoint' => $endpoint, 'reference' => $reference]);

            throw PaymentGatewayException::because('unavailable', 'Paystack could not be reached just now. Nothing was charged; please try again in a moment.');
        } catch (RequestException) {
            Log::warning('paystack.request_exception', ['endpoint' => $endpoint, 'reference' => $reference]);

            throw PaymentGatewayException::because('unavailable', 'Paystack could not be reached just now. Nothing was charged; please try again in a moment.');
        }

        $status = $response->status();

        if ($status >= 500 || $status === 429) {
            Log::warning('paystack.unavailable', ['endpoint' => $endpoint, 'http_status' => $status, 'reference' => $reference]);

            throw PaymentGatewayException::because('unavailable', 'Paystack is having trouble just now. Nothing was charged; please try again in a moment.');
        }

        if ($status >= 300) {
            Log::warning('paystack.rejected', ['endpoint' => $endpoint, 'http_status' => $status, 'reference' => $reference]);

            throw PaymentGatewayException::because($status >= 400 ? 'rejected' : 'invalid_response', 'Paystack did not accept that request. Nothing was charged.');
        }

        $json = $response->json();

        if (! is_array($json) || ($json['status'] ?? null) !== true || ! is_array($json['data'] ?? null)) {
            Log::warning('paystack.invalid_response', ['endpoint' => $endpoint, 'http_status' => $status, 'reference' => $reference]);

            throw PaymentGatewayException::because('invalid_response', 'Paystack sent an answer we could not read.');
        }

        return $json['data'];
    }

    /**
     * Where the payer is sent. Only Paystack's own checkout hosts, so a tampered base URL or response
     * cannot turn "Pay now" into a redirect to someone else's page.
     */
    private function isPaystackCheckoutUrl(string $url): bool
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));

        return ($parts['scheme'] ?? '') === 'https'
            && ($host === 'paystack.com' || str_ends_with($host, '.paystack.com') || $host === 'paystack.co' || str_ends_with($host, '.paystack.co'));
    }
}
