<?php

namespace App\Services;

use App\Models\Booking\BookingVariableCustomization;
use App\Models\Vehicle\VehiclePricing\VehiclePricingCalculationDefinition;
use App\Models\Vehicle\VehiclePricing\VehicleGroupCommonRatePricing;
use App\Models\Vehicle\VehiclePricing\VehicleGroupPricing;
use App\Models\Vehicle\VehicleAddon;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class PricingVariableService
{
    /**
     * Apply variable customizations to calculation inputs
     */
    public function applyVariableCustomizations(array $calculationInputs, array $customizations, string $context = 'base_pricing'): array
    {
        foreach ($customizations as $customization) {
            if ($customization['context'] === $context) {
                // Override the variable value in calculation inputs
                $calculationInputs[$customization['variable_name']] = $customization['custom_value'];
            }
        }

        return $calculationInputs;
    }

    /**
     * Get available variables for customization based on service type and vehicle group
     */
    public function getCustomizableVariables(string $serviceTypeId, string $vehicleGroupId): array
    {
        // Get calculation definition for the service type
        // $calculationDefinition = VehiclePricingCalculationDefinition::all();
        $calculationDefinition = VehiclePricingCalculationDefinition::where('service_type_id', $serviceTypeId)
            ->where('status', 'active')
            ->first();

        if (!$calculationDefinition) {
            return [];
        }

        $variables = [];

        // Process each variable from the calculation definition
        foreach ($calculationDefinition->variables ?? [] as $variable) {
            $variableInfo = $this->getVariableInfo($variable, $serviceTypeId, $vehicleGroupId);

            if ($variableInfo && $variableInfo['customizable']) {
                $variables[] = $variableInfo;
            }
        }

        return $variables;
    }

    /**
     * Get detailed information about a specific variable
     */
    private function getVariableInfo(array $variable, string $serviceTypeId, string $vehicleGroupId): ?array
    {
        $varName = $variable['name'];
        $varType = $variable['type'];

        $info = [
            'name' => $varName,
            'type' => $varType,
            'description' => $variable['description'] ?? $this->getVariableDescription($varName),
            'category' => $variable['category'] ?? $this->getVariableCategory($varName),
            'customizable' => $this->isVariableCustomizable($varName, $varType),
            'current_value' => null,
            'unit' => $this->getVariableUnit($varName),
            'min_value' => $this->getVariableMinValue($varName),
            'max_value' => $this->getVariableMaxValue($varName),
        ];

        // Get current value based on variable type
        switch ($varType) {
            case 'slab_rate':
                $slabPricing = VehicleGroupPricing::where('vehicle_group_id', $vehicleGroupId)
                    ->whereHas('slabDefinition', function ($query) use ($serviceTypeId) {
                        $query->where('service_type_id', $serviceTypeId);
                    })
                    ->first();

                $info['current_value'] = $slabPricing ? $slabPricing->rate : 0;
                break;

            case 'common_rate':
                $commonRate = VehicleGroupCommonRatePricing::where('vehicle_group_id', $vehicleGroupId)
                    ->whereHas('commonRateDefinition', function ($query) use ($serviceTypeId, $varName) {
                        $query->where('service_type_id', $serviceTypeId)
                            ->where('code', $varName);
                    })
                    ->first();
                $info['current_value'] = $commonRate ? $commonRate->value : 0;
                break;

            case 'fixed_value':
                $info['current_value'] = $variable['default_value'] ?? 0;
                break;

            case 'duration':
            case 'distance':
                // These are typically calculated from booking parameters
                $info['customizable'] = false;
                break;
        }

        return $info;
    }

    /**
     * Calculate addon pricing with proper billing type handling
     */
    public function calculateAddonPricingWithBillingType(VehicleAddon $addon, int $quantity, int $durationDays, int $durationHours, ?float $customPrice = null): array
    {
        $unitPrice = $customPrice ?? $addon->amount;

        switch ($addon->billing_type) {
            case 'per_package':
                $billableUnits = 1; // Fixed price regardless of duration
                $billingDescription = 'Package rate';
                break;

            case 'per_hour':
                $billableUnits = max(1, $durationHours);
                $billingDescription = "{$billableUnits} hour(s)";
                break;

            case 'per_day':
            default:
                $billableUnits = max(1, $durationDays);
                $billingDescription = "{$billableUnits} day(s)";
                break;
        }

        $totalPrice = $unitPrice * $quantity * $billableUnits;

        return [
            'id' => $addon->id,
            'name' => $addon->name,
            'unit_price' => $unitPrice,
            'quantity' => $quantity,
            'billing_type' => $addon->billing_type,
            'billable_units' => $billableUnits,
            'billing_description' => $billingDescription,
            'total_price' => $totalPrice,
            'is_custom_price' => $customPrice !== null,
            'original_price' => $customPrice !== null ? $addon->amount : null,
        ];
    }

    /**
     * Store variable customizations for a booking session
     * Session-scoped design: Support both session-only and booking persistence
     */
    public function storeVariableCustomizations(array $customizations, ?string $bookingId = null, ?string $sessionId = null): array
    {
        $stored = [];

        foreach ($customizations as $customization) {
            // Handle session-to-booking migration: if we have both session_id and booking_id,
            // this is a persistence operation where we're finalizing session data
            $createData = [
                'booking_id' => $bookingId,
                'session_id' => $sessionId ?? Str::uuid(),
                'vehicle_group_id' => $customization['vehicle_group_id'] ?? null,
                'variable_name' => $customization['variable_name'],
                'variable_type' => $customization['variable_type'],
                'original_value' => $customization['original_value'],
                'custom_value' => $customization['custom_value'],
                'customization_reason' => $customization['reason'] ?? null,
                'context' => $customization['context'] ?? 'base_pricing',
                'metadata' => $customization['metadata'] ?? null,
                'created_user_id' => Auth::id(),
            ];

            // Session-scoped design: If we have a booking_id, this is persistence
            if ($bookingId) {
                // Clean up any existing session-only records for this variable and vehicle group
                if ($sessionId) {
                    BookingVariableCustomization::where('session_id', $sessionId)
                        ->where('variable_name', $customization['variable_name'])
                        ->where('vehicle_group_id', $customization['vehicle_group_id'] ?? null)
                        ->whereNull('booking_id')
                        ->delete();
                }
                
            } else {
                Log::info('🔍 Session-only variable customization (preview mode)', [
                    'session_id' => $sessionId,
                    'variable_name' => $customization['variable_name'],
                    'vehicle_group_id' => $customization['vehicle_group_id'] ?? null
                ]);
            }

            $record = BookingVariableCustomization::create($createData);
            $stored[] = $record;
        }

        return $stored;
    }

    /**
     * Get existing variable customizations
     * Session-scoped design: Support both session and booking queries
     */
    public function getVariableCustomizations(?string $bookingId = null, ?string $sessionId = null): array
    {
        $query = BookingVariableCustomization::query();

        if ($bookingId) {
            // Booking mode: Get persisted customizations
            $query->where('booking_id', $bookingId);
        } elseif ($sessionId) {
            // Session mode: Get session-only customizations
            $query->where('session_id', $sessionId)
                  ->whereNull('booking_id'); // Only session-scoped, not yet persisted
        } else {
            return [];
        }

        return $query->get()->toArray();
    }

    /**
     * Clean up session-scoped customizations
     * Called when session is completed or reset
     */
    public function cleanupSessionCustomizations(string $sessionId): int
    {
        $deleted = BookingVariableCustomization::where('session_id', $sessionId)
            ->whereNull('booking_id') // Only delete session-only records
            ->delete();
        
        return $deleted;
    }

    /**
     * Migrate session customizations to booking
     * Called during booking persistence operations
     */
    public function migrateSessionToBooking(string $sessionId, string $bookingId): int
    {
        $updated = BookingVariableCustomization::where('session_id', $sessionId)
            ->whereNull('booking_id')
            ->update([
                'booking_id' => $bookingId,
                'updated_at' => now()
            ]);

        
        return $updated;
    }

    /**
     * Update existing variable customization
     */
    public function updateVariableCustomization(string $customizationId, array $updates): BookingVariableCustomization
    {
        $customization = BookingVariableCustomization::findOrFail($customizationId);
        $customization->update(array_merge($updates, [
            'updated_user_id' => Auth::id(),
        ]));

        return $customization;
    }

    /**
     * Check if variable customizations require approval
     */
    public function requiresApproval(array $customizations): bool
    {
        foreach ($customizations as $customization) {
            $changePercentage = abs($customization['custom_value'] - $customization['original_value'])
                / $customization['original_value'] * 100;

            // Require approval for changes > 20%
            if ($changePercentage > 20) {
                return true;
            }

            // Require approval for sensitive variables
            if (in_array($customization['variable_name'], ['slab_rate', 'driver_allowance'])) {
                if ($changePercentage > 10) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get variable description for UI display
     */
    private function getVariableDescription(string $varName): string
    {
        $descriptions = [
            'slab_rate' => 'Base service rate from pricing slab',
            'driver_allowance' => 'Daily allowance for driver',
            'extra_km_rate' => 'Rate per extra kilometer',
            'extra_hour_rate' => 'Rate per extra hour',
            'vehicle_delivery_rate_per_km' => 'Delivery charge per kilometer',
            'vehicle_pickup_rate_per_km' => 'Pickup charge per kilometer',
            'service_rate_per_km' => 'Service charge per kilometer',
            'stop_charge' => 'Charge per additional stop',
            'waiting_charge_per_hour' => 'Waiting charge per hour',
            'decoration_charge' => 'Vehicle decoration charge',
            'emergency_base_rate' => 'Emergency service base rate',
            'hourly_rate' => 'Hourly service rate',
            'overtime_rate_per_hour' => 'Overtime rate per hour',
        ];

        return $descriptions[$varName] ?? ucfirst(str_replace('_', ' ', $varName));
    }

    /**
     * Get variable category for grouping in UI
     */
    private function getVariableCategory(string $varName): string
    {
        $categories = [
            'slab_rate' => 'base',
            'driver_allowance' => 'base',
            'extra_km_rate' => 'overage',
            'extra_hour_rate' => 'overage',
            'vehicle_delivery_rate_per_km' => 'logistics',
            'vehicle_pickup_rate_per_km' => 'logistics',
            'service_rate_per_km' => 'service',
            'stop_charge' => 'service',
            'waiting_charge_per_hour' => 'service',
            'decoration_charge' => 'special',
            'emergency_base_rate' => 'special',
            'hourly_rate' => 'service',
            'overtime_rate_per_hour' => 'overage',
        ];

        return $categories[$varName] ?? 'other';
    }

    /**
     * Check if a variable can be customized
     */
    private function isVariableCustomizable(string $varName, string $varType): bool
    {
        // System calculated variables that shouldn't be customized
        $nonCustomizable = [
            'duration_hours',
            'duration_days',
            'total_distance',
            'delivery_distance',
            'pickup_distance',
            'extra_km',
            'extra_hours',
            'number_of_days'
        ];

        return !in_array($varName, $nonCustomizable) && in_array($varType, ['slab_rate', 'common_rate', 'fixed_value']);
    }

    /**
     * Get variable unit for display
     */
    private function getVariableUnit(string $varName): string
    {
        $units = [
            'slab_rate' => 'LKR',
            'driver_allowance' => 'LKR/day',
            'extra_km_rate' => 'LKR/km',
            'extra_hour_rate' => 'LKR/hour',
            'vehicle_delivery_rate_per_km' => 'LKR/km',
            'vehicle_pickup_rate_per_km' => 'LKR/km',
            'service_rate_per_km' => 'LKR/km',
            'stop_charge' => 'LKR/stop',
            'waiting_charge_per_hour' => 'LKR/hour',
            'decoration_charge' => 'LKR',
            'emergency_base_rate' => 'LKR',
            'hourly_rate' => 'LKR/hour',
            'overtime_rate_per_hour' => 'LKR/hour',
        ];

        return $units[$varName] ?? 'LKR';
    }

    /**
     * Get minimum allowable value for a variable
     */
    private function getVariableMinValue(string $varName): ?float
    {
        // Most rates shouldn't go below 0
        return 0;
    }

    /**
     * Get maximum allowable value for a variable
     */
    private function getVariableMaxValue(string $varName): ?float
    {
        // Set reasonable maximums to prevent extreme values
        $maximums = [
            'slab_rate' => 1000000, // 1M LKR
            'driver_allowance' => 10000, // 10K LKR per day
            'extra_km_rate' => 1000, // 1K LKR per km
            'extra_hour_rate' => 5000, // 5K LKR per hour
        ];

        return $maximums[$varName] ?? null;
    }

    /**
     * Convert variable customizations to array format for API responses
     */
    public function formatCustomizationsForResponse(array $customizations): array
    {
        return array_map(function ($customization) {
            return [
                'id' => $customization['id'] ?? null,
                'variable_name' => $customization['variable_name'],
                'variable_type' => $customization['variable_type'],
                'display_name' => $this->getVariableDescription($customization['variable_name']),
                'category' => $this->getVariableCategory($customization['variable_name']),
                'unit' => $this->getVariableUnit($customization['variable_name']),
                'original_value' => $customization['original_value'],
                'custom_value' => $customization['custom_value'],
                'change_percentage' => $this->calculateChangePercentage($customization['original_value'], $customization['custom_value']),
                'context' => $customization['context'],
                'reason' => $customization['customization_reason'] ?? null,
            ];
        }, $customizations);
    }

    /**
     * Calculate percentage change between two values
     */
    private function calculateChangePercentage(float $original, float $custom): float
    {
        if ($original == 0) {
            return $custom > 0 ? 100 : 0;
        }

        return round(($custom - $original) / $original * 100, 2);
    }
}
