<?php

namespace App\Casts;

use App\Models\Staff;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class EncryptedStaffPaymentValue implements CastsAttributes
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

        $value = (string) $value;
        if (str_starts_with($value, self::PREFIX) || ! $this->isStaff($attributes['payable_type'] ?? null)) {
            return $value;
        }

        return self::PREFIX.Crypt::encryptString($value);
    }

    private function isStaff(?string $payableType): bool
    {
        return in_array($payableType, ['staff', Staff::class], true);
    }
}
