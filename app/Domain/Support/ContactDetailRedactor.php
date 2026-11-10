<?php

namespace App\Domain\Support;

/**
 * Masks phone numbers and email addresses in messages relayed between a passenger and a driver
 * (lost property), so neither side's contact details are revealed through the platform.
 */
class ContactDetailRedactor
{
    public function redact(string $text): string
    {
        $text = (string) preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[email hidden]', $text);

        // 9+ digits with optional spaces, dashes, dots, brackets or a leading +: phone-like.
        return (string) preg_replace('/(?<![\w])\+?\(?\d(?:[\s().-]*\d){8,}(?![\w])/', '[number hidden]', $text);
    }
}
