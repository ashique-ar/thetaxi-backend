<?php

namespace App\Support;

use Carbon\CarbonImmutable;

final class SriLankanNic
{
    public static function dateOfBirth(?string $nic): ?string
    {
        $nic = strtoupper(trim((string) $nic));
        if (preg_match('/^\d{9}[VX]$/', $nic)) {
            $year = 1900 + (int) substr($nic, 0, 2);
            $day = (int) substr($nic, 2, 3);
        } elseif (preg_match('/^\d{12}$/', $nic)) {
            $year = (int) substr($nic, 0, 4);
            $day = (int) substr($nic, 4, 3);
        } else {
            return null;
        }

        if ($day > 500) $day -= 500;
        if ($day < 1 || $day > 366) return null;

        foreach ([31, 29, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31] as $month => $days) {
            if ($day > $days) {
                $day -= $days;
                continue;
            }

            if (! checkdate($month + 1, $day, $year)) return null;
            return CarbonImmutable::create($year, $month + 1, $day)->toDateString();
        }

        return null;
    }
}
