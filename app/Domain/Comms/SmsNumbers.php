<?php

namespace App\Domain\Comms;

use App\Domain\Comms\Models\SmsNumber;
use App\Domain\Safety\Sms\SmsGateway;
use App\Models\User;
use InvalidArgumentException;

/**
 * A number for safety SMS, confirmed with a 6-digit code sent to it (so nobody can point alerts at
 * someone else's phone). Only possible while an SMS provider is configured. The code is stored as
 * an HMAC, expires, and allows a few attempts.
 */
final class SmsNumbers
{
    public function __construct(private readonly SmsGateway $sms) {}

    public static function normalise(string $input): ?string
    {
        $digits = preg_replace('/[^\d+]/', '', $input) ?? '';

        if (str_starts_with($digits, '00')) {
            $digits = '+'.substr($digits, 2);
        } elseif (preg_match('/^0\d{9}$/', $digits)) {
            $digits = '+27'.substr($digits, 1); // South African national format
        }

        return preg_match('/^\+[1-9]\d{7,14}$/', $digits) ? $digits : null;
    }

    /** @throws InvalidArgumentException */
    public function start(User $user, string $input): SmsNumber
    {
        if (! $this->sms->isConfigured()) {
            throw new InvalidArgumentException('SMS is not available on this service.');
        }

        $number = self::normalise($input);

        if ($number === null) {
            throw new InvalidArgumentException('Enter a mobile number like 082 123 4567 or +27 82 123 4567.');
        }

        $existing = SmsNumber::where('user_id', $user->id)->first();

        if ($existing?->code_sent_at !== null && $existing->code_sent_at->gt(now()->subMinute())) {
            throw new InvalidArgumentException('A code was sent less than a minute ago. Wait a moment, then try again.');
        }

        $code = (string) random_int(100000, 999999);
        $result = $this->sms->send($number, "Dot.Ehail: your code is {$code}. It confirms this number for safety messages. Ignore this if you did not ask for it.");

        if (! $result->wasSent()) {
            throw new InvalidArgumentException('We could not send a code to that number. Check it and try again.');
        }

        return SmsNumber::updateOrCreate(['user_id' => $user->id], [
            'number' => $number,
            'verified_at' => null,
            'code_hash' => $this->hash($user, $code),
            'code_expires_at' => now()->addMinutes((int) config('ehail_comms.sms.code_ttl_minutes', 10)),
            'code_attempts' => 0,
            'code_sent_at' => now(),
        ]);
    }

    /** @throws InvalidArgumentException */
    public function confirm(User $user, string $code): SmsNumber
    {
        $row = SmsNumber::where('user_id', $user->id)->first();

        if ($row === null || $row->code_hash === null) {
            throw new InvalidArgumentException('Ask for a code first.');
        }

        if ($row->code_expires_at === null || $row->code_expires_at->isPast() || $row->code_attempts >= (int) config('ehail_comms.sms.code_max_attempts', 5)) {
            throw new InvalidArgumentException('That code has expired. Ask for a new one.');
        }

        $row->increment('code_attempts');

        if (! hash_equals((string) $row->code_hash, $this->hash($user, trim($code)))) {
            throw new InvalidArgumentException('That code is not right.');
        }

        $row->forceFill(['verified_at' => now(), 'code_hash' => null, 'code_expires_at' => null, 'code_attempts' => 0])->save();

        return $row;
    }

    public function remove(User $user): void
    {
        SmsNumber::where('user_id', $user->id)->delete();
    }

    private function hash(User $user, string $code): string
    {
        return hash_hmac('sha256', $user->id.'|'.$code, (string) config('app.key'));
    }
}
