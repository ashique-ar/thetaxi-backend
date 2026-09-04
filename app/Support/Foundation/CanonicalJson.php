<?php

namespace App\Support\Foundation;

class CanonicalJson
{
    /**
     * Encode a value as deterministic JSON: object keys are recursively sorted
     * so two logically-identical payloads always produce the same bytes, and
     * therefore the same checksum, regardless of PHP array insertion order.
     */
    public static function encode(mixed $value): string
    {
        return json_encode(
            self::normalize($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    private static function normalize(mixed $value): mixed
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map(self::normalize(...), $value);
            }

            ksort($value, SORT_STRING);

            return array_map(self::normalize(...), $value);
        }

        return $value;
    }
}
