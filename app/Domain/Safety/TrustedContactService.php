<?php

namespace App\Domain\Safety;

use App\Domain\Safety\Mail\TrustedContactConfirmationMail;
use App\Domain\Safety\Models\TrustedContact;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Up to five trusted contacts per user. A contact is only used after confirming through an
 * emailed link (so nobody is signed up to receive alerts without agreeing) and is never used after
 * opting out. The confirmation token is single-use, stored hashed, and expires.
 */
class TrustedContactService
{
    /**
     * @return array{contact: TrustedContact, confirmation_sent: bool}
     */
    public function add(User $owner, string $name, string $email, ?string $phone = null, ?string $relationship = null): array
    {
        $email = mb_strtolower(trim($email));

        if ($email === mb_strtolower((string) $owner->email)) {
            throw ValidationException::withMessages(['email' => 'A trusted contact must be someone other than you.']);
        }

        $phone = $this->normalisePhone($phone);

        $contact = DB::transaction(function () use ($owner, $name, $email, $phone, $relationship) {
            // Serialise per owner so two adds at once cannot pass the limit.
            User::query()->whereKey($owner->id)->lockForUpdate()->first();

            if (TrustedContact::query()->where('user_id', $owner->id)->count() >= (int) config('ehail_safety.trusted_contacts.max', 5)) {
                throw ValidationException::withMessages(['email' => 'You can have up to '.config('ehail_safety.trusted_contacts.max', 5).' trusted contacts. Remove one first.']);
            }

            if (TrustedContact::query()->where('user_id', $owner->id)->where('email_hash', TrustedContact::emailHash($email))->exists()) {
                throw ValidationException::withMessages(['email' => 'This person is already one of your trusted contacts.']);
            }

            return TrustedContact::create([
                'user_id' => $owner->id,
                'name' => trim($name),
                'email' => $email,
                'email_hash' => TrustedContact::emailHash($email),
                'phone' => $phone,
                'relationship' => $relationship !== null ? mb_substr(trim($relationship), 0, 40) : null,
            ]);
        });

        return ['contact' => $contact, 'confirmation_sent' => $this->sendConfirmation($contact, $owner)];
    }

    public function resend(TrustedContact $contact): bool
    {
        if ($contact->confirmed_at !== null || $contact->opted_out_at !== null) {
            throw ValidationException::withMessages(['contact' => 'This contact has already answered.']);
        }

        if ($contact->confirmation_sent_at !== null && $contact->confirmation_sent_at->gt(now()->subMinutes(5))) {
            throw ValidationException::withMessages(['contact' => 'A confirmation email was sent a few minutes ago. Please wait before sending another.']);
        }

        return $this->sendConfirmation($contact, $contact->user);
    }

    public function findByToken(string $token): ?TrustedContact
    {
        if (strlen($token) < 40 || strlen($token) > 80) {
            return null;
        }

        $contact = TrustedContact::query()->where('confirm_token_hash', hash('sha256', $token))->first();

        if ($contact === null || $contact->confirmation_sent_at === null) {
            return null;
        }

        return $contact;
    }

    public function confirm(string $token): ?TrustedContact
    {
        $contact = $this->findByToken($token);

        if ($contact === null || $contact->opted_out_at !== null) {
            return null;
        }

        if ($contact->confirmed_at === null && $contact->confirmation_sent_at->lt(now()->subHours((int) config('ehail_safety.trusted_contacts.confirmation_valid_hours', 72)))) {
            return null;
        }

        if ($contact->confirmed_at === null) {
            $contact->forceFill(['confirmed_at' => now()])->save();
        }

        return $contact;
    }

    /**
     * The contact declines or stops alerts. The token keeps working for opting out (an alert email
     * carries the same link), never for confirming again.
     */
    public function optOut(string $token): ?TrustedContact
    {
        $contact = $this->findByToken($token);

        if ($contact === null) {
            return null;
        }

        if ($contact->opted_out_at === null) {
            $contact->forceFill(['opted_out_at' => now()])->save();
        }

        return $contact;
    }

    /**
     * Opt-out link for an alert or share email: a signed URL that never expires and needs no
     * token rotation, so every email's link keeps working.
     */
    public function optOutUrl(TrustedContact $contact): string
    {
        return URL::signedRoute('safety.contacts.unsubscribe', ['contact' => $contact->id]);
    }

    public function unsubscribe(TrustedContact $contact): void
    {
        if ($contact->opted_out_at === null) {
            $contact->forceFill(['opted_out_at' => now()])->save();
        }
    }

    private function sendConfirmation(TrustedContact $contact, User $owner): bool
    {
        $token = Str::random(64);
        $contact->forceFill(['confirm_token_hash' => hash('sha256', $token), 'confirmation_sent_at' => now()])->save();

        try {
            Mail::to((string) $contact->email)->send(new TrustedContactConfirmationMail(
                (string) $contact->name,
                RideSafety::firstName($owner->name, 'Someone'),
                route('safety.contacts.confirm.show', $token),
                route('safety.contacts.opt-out.show', $token),
            ));

            return true;
        } catch (Throwable $e) {
            Log::warning('Trusted contact confirmation email failed.', ['contact_id' => $contact->id, 'error' => class_basename($e)]);

            return false;
        }
    }

    private function normalisePhone(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }

        $digits = (string) preg_replace('/[^\d+]/', '', $phone);

        // South African local format 0XX XXX XXXX becomes +27XXXXXXXXX.
        if (preg_match('/^0\d{9}$/', $digits)) {
            $digits = '+27'.substr($digits, 1);
        }

        if (! preg_match('/^\+[1-9]\d{7,14}$/', $digits)) {
            throw ValidationException::withMessages(['phone' => 'Enter a mobile number like 082 123 4567 or +27 82 123 4567.']);
        }

        return $digits;
    }
}
