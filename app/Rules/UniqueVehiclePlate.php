<?php

namespace App\Rules;

use App\Models\Vehicle\Vehicle;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class UniqueVehiclePlate implements ValidationRule
{
    public function __construct(private readonly ?string $ignoreVehicleId = null)
    {
    }

    public static function normalize(mixed $value): string
    {
        return mb_strtoupper((string) preg_replace('/[\s-]+/u', '', trim((string) $value)));
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (trim((string) $value) === '') {
            return;
        }

        $normalized = self::normalize($value);
        $query = Vehicle::query()
            ->when($this->ignoreVehicleId, fn ($query) => $query->where('id', '!=', $this->ignoreVehicleId))
            ->where(function ($query) use ($normalized) {
                foreach (['license_plate', 'registration_no'] as $column) {
                    $query->orWhereRaw(
                        "UPPER(REPLACE(REPLACE({$column}, '-', ''), ' ', '')) = ?",
                        [$normalized]
                    );
                }
            });

        if ($query->exists()) {
            $fail('This vehicle plate number already exists in the system.');
        }
    }
}
