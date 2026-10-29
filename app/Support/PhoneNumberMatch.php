<?php

namespace App\Support;

final class PhoneNumberMatch
{
    public static function normalized(string $phone): string
    {
        $digits = str_replace(['+', '-', ' ', '(', ')', '.', '/'], '', $phone);

        return str_starts_with($digits, '00') ? substr($digits, 2) : $digits;
    }

    public static function sqlExpression(string $column): string
    {
        $digits = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE({$column}, '+', ''), '-', ''), ' ', ''), '(', ''), ')', ''), '.', ''), '/', '')";

        return "CASE WHEN {$digits} LIKE '00%' THEN SUBSTR({$digits}, 3) ELSE {$digits} END";
    }
}
