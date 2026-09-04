<?php

namespace App\Casts;

use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;

class EncryptedStaffDateValue implements CastsAttributes
{
    private const PREFIX = 'enc:';

    public function get(Model $model, string $key, mixed $value, array $attributes): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        $plain = str_starts_with((string) $value, self::PREFIX)
            ? Crypt::decryptString(substr((string) $value, strlen(self::PREFIX)))
            : (string) $value;

        return Carbon::createFromFormat('Y-m-d', $plain)->startOfDay();
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $plain = $value instanceof DateTimeInterface
            ? $value->format('Y-m-d')
            : Carbon::parse((string) $value)->format('Y-m-d');

        return self::PREFIX.Crypt::encryptString($plain);
    }
}
