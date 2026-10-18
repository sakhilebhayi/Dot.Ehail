<?php

namespace App\Http\Controllers\Ehail;

use App\Exceptions\Payments\PaymentGatewayException;
use App\Http\Controllers\Controller;
use App\Services\Ehail\RidePayments;
use App\Services\Payments\Paystack\PaystackConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /webhooks/paystack. No session, no CSRF token (Paystack has neither): authenticity is the
 * x-paystack-signature header, a lowercase hex HMAC-SHA512 of the RAW body under the secret key,
 * compared in constant time BEFORE the body is parsed. Every failed check (no usable key, no header, a
 * wrong signature) gets the same bare 401. A signed but unreadable body is a 400. Everything else is
 * acknowledged with 200 so Paystack stops retrying: events we do not use, repeats, and charges we
 * cannot match (those are recorded for the operator desk).
 */
class PaystackWebhookController extends Controller
{
    public function __invoke(Request $request, PaystackConfig $config, RidePayments $payments): JsonResponse
    {
        $raw = $request->getContent();
        $signature = strtolower(trim((string) $request->header('x-paystack-signature')));

        try {
            $secret = $config->secretKey();
        } catch (PaymentGatewayException) {
            return $this->unauthorised();
        }

        if ($signature === '' || ! hash_equals(hash_hmac('sha512', $raw, $secret), $signature)) {
            return $this->unauthorised();
        }

        $payload = json_decode($raw, true);

        if (! is_array($payload)) {
            return response()->json(['message' => 'Invalid payload'], 400);
        }

        $payments->handleWebhook($payload);

        return response()->json(['status' => 'ok']);
    }

    private function unauthorised(): JsonResponse
    {
        return response()->json(['message' => 'Unauthorized'], 401);
    }
}
