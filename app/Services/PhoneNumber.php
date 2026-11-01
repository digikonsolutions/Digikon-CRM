<?php

namespace App\Services;

use App\Models\Setting;

class PhoneNumber
{
    /**
     * Normalise a phone number to international digits-only format
     * e.g. 0300-1234567 -> 923001234567 (WhatsApp Cloud API format).
     */
    public static function normalize(?string $phone): ?string
    {
        if (! $phone) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone);
        if ($digits === '') {
            return null;
        }

        $cc = preg_replace('/\D+/', '', (string) Setting::get('default_country_code', '92'));

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        } elseif (str_starts_with($digits, '0')) {
            $digits = $cc.substr($digits, 1);
        } elseif (strlen($digits) <= 10 && ! str_starts_with($phone, '+')) {
            $digits = $cc.$digits;
        }

        return $digits;
    }

    /** Last 10 digits – used to match the same number written differently. */
    public static function tail(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        return $digits === '' ? null : substr($digits, -10);
    }
}
