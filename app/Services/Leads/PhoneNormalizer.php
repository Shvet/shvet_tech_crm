<?php

namespace App\Services\Leads;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

/** Normalizes user-entered phone numbers to E.164 (default region IN). Returns null for anything invalid. */
class PhoneNormalizer
{
    public static function normalize(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }

        $util = PhoneNumberUtil::getInstance();

        try {
            $number = $util->parse($raw, (string) config('crm.default_region', 'IN'));
        } catch (NumberParseException) {
            return null;
        }

        return $util->isValidNumber($number) ? $util->format($number, PhoneNumberFormat::E164) : null;
    }
}
