<?php

namespace App\Support;

final class PhoneNumberMatch
{
    // shortcut: remove display punctuation only; use a server-side numbering plan parser to match local and international forms.
    public static function normalized(string $phone): string
    {
        return str_replace(['+', '-', ' ', '(', ')', '.', '/'], '', $phone);
    }

    public static function sqlExpression(string $column): string
    {
        return "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE({$column}, '+', ''), '-', ''), ' ', ''), '(', ''), ')', ''), '.', ''), '/', '')";
    }
}
