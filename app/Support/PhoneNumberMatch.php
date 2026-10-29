<?php

namespace App\Support;

final class PhoneNumberMatch
{
    public static function normalized(string $phone, ?string $callingCode = null): string
    {
        $digits = str_replace(['+', '-', ' ', '(', ')', '.', '/'], '', $phone);
        $digits = str_starts_with($digits, '00') ? substr($digits, 2) : $digits;
        $callingCode = preg_replace('/\D+/', '', (string) $callingCode);
        $callingCode = strlen($callingCode) <= 3 ? $callingCode : '';

        return $callingCode && str_starts_with($digits, '0') && strlen($digits) > 1
            ? $callingCode.substr($digits, 1)
            : $digits;
    }

    public static function sqlExpression(string $column, ?string $callingCode = null): string
    {
        $digits = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE({$column}, '+', ''), '-', ''), ' ', ''), '(', ''), ')', ''), '.', ''), '/', '')";
        $normalized = "CASE WHEN {$digits} LIKE '00%' THEN SUBSTR({$digits}, 3) ELSE {$digits} END";
        $callingCode = preg_replace('/\D+/', '', (string) $callingCode);
        $callingCode = strlen($callingCode) <= 3 ? $callingCode : '';

        return $callingCode
            ? "CASE WHEN ({$normalized}) LIKE '0%' AND LENGTH({$normalized}) > 1 THEN '{$callingCode}' || SUBSTR({$normalized}, 2) ELSE ({$normalized}) END"
            : $normalized;
    }
}
