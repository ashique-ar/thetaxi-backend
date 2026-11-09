<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class TaxiMeterPricingService
{
    public function normalize(array $values): array
    {
        $kind = $values['meter_package_kind'] ?? $values['package_kind'] ?? null;
        if (!in_array($kind, ['distance', 'hourly', 'combined', 'open_meter'], true)) {
            throw ValidationException::withMessages(['meter_package_kind' => 'Select a valid meter package.']);
        }

        $needsKm = in_array($kind, ['distance', 'combined'], true);
        $needsHours = in_array($kind, ['hourly', 'combined'], true);
        $includedKm = $needsKm ? $this->number($values['meter_included_km'] ?? $values['included_km'] ?? null, 'meter_included_km', true) : 0.0;
        $includedHours = $needsHours ? $this->number($values['meter_included_hours'] ?? $values['included_hours'] ?? null, 'meter_included_hours', true) : 0.0;
        if ($needsKm && $includedKm <= 0) {
            throw ValidationException::withMessages(['meter_included_km' => 'Included kilometres must be greater than zero.']);
        }
        if ($needsHours && $includedHours <= 0) {
            throw ValidationException::withMessages(['meter_included_hours' => 'Included hours must be greater than zero.']);
        }

        return [
            'package_kind' => $kind,
            'included_km' => $includedKm,
            'included_hours' => $includedHours,
            'fixed_price' => $kind === 'open_meter' ? 0.0 : $this->number($values['meter_fixed_price'] ?? $values['fixed_price'] ?? null, 'meter_fixed_price'),
            'extra_per_km' => in_array($kind, ['distance', 'combined', 'open_meter'], true)
                ? $this->number($values['meter_extra_per_km'] ?? $values['extra_per_km'] ?? null, 'meter_extra_per_km')
                : 0.0,
            'extra_per_hour' => in_array($kind, ['hourly', 'combined', 'open_meter'], true)
                ? $this->number($values['meter_extra_per_hour'] ?? $values['extra_per_hour'] ?? null, 'meter_extra_per_hour')
                : 0.0,
        ];
    }

    public function calculate(array $package, float $actualKm, float $actualHours): array
    {
        if (!is_finite($actualKm) || $actualKm < 0 || !is_finite($actualHours) || $actualHours < 0) {
            throw new \InvalidArgumentException('Meter distance and duration must be finite and non-negative.');
        }

        $kmOver = max(0, $actualKm - (float) ($package['included_km'] ?? 0));
        $hoursOver = max(0, $actualHours - (float) ($package['included_hours'] ?? 0));
        $kmCharge = $kmOver * (float) ($package['extra_per_km'] ?? 0);
        $hourCharge = $hoursOver * (float) ($package['extra_per_hour'] ?? 0);
        $fare = (float) ($package['fixed_price'] ?? 0) + $kmCharge + $hourCharge;

        return [
            'package_kind' => $package['package_kind'] ?? null,
            'actual_km' => round($actualKm, 2),
            'actual_hours' => round($actualHours, 4),
            'included_km' => (float) ($package['included_km'] ?? 0),
            'included_hours' => (float) ($package['included_hours'] ?? 0),
            'extra_km' => round($kmOver, 2),
            'extra_hours' => round($hoursOver, 4),
            'fixed_price' => (float) ($package['fixed_price'] ?? 0),
            'extra_km_charge' => round($kmCharge, 2),
            'extra_hour_charge' => round($hourCharge, 2),
            'total' => round($fare, 2),
        ];
    }

    private function number(mixed $value, string $field, bool $positive = false): float
    {
        if (!is_numeric($value) || !is_finite((float) $value) || (float) $value < 0 || ($positive && (float) $value <= 0)) {
            throw ValidationException::withMessages([$field => 'Enter a valid non-negative value.']);
        }

        return (float) $value;
    }
}
