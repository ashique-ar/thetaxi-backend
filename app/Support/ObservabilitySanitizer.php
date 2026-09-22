<?php

namespace App\Support;

class ObservabilitySanitizer
{
    public static function text(?string $value): ?string
    {
        if ($value === null) return null;

        return preg_replace([
            '/\bBearer\s+[A-Za-z0-9._~+\/-]+=*/i',
            '/([?&](?:access_token|refresh_token|token|api_key|key|password|secret)=)[^&#\s]*/i',
        ], ['$1[REDACTED]', '$1[REDACTED]'], $value);
    }

    public static function strings(array $values): array
    {
        return array_map(fn ($value) => is_string($value) ? self::text($value) : $value, $values);
    }

    public static function values(array $values): array
    {
        return array_map(fn ($value) => match (true) {
            is_string($value) => self::text($value),
            is_array($value) => self::values($value),
            default => $value,
        }, $values);
    }
}
