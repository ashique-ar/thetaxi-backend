<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class EncryptedStaffIdentityValue implements CastsAttributes
{
    private const PREFIX = 'enc:';

    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        if (! str_starts_with((string) $value, self::PREFIX)) {
            return (string) $value;
        }

        return Crypt::decryptString(substr((string) $value, strlen(self::PREFIX)));
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        // Values reaching the cast are application input, never trusted
        // ciphertext. Encrypt even a literal value beginning with "enc:" so a
        // caller cannot smuggle malformed ciphertext into the column.
        return self::PREFIX.Crypt::encryptString((string) $value);
    }
}
