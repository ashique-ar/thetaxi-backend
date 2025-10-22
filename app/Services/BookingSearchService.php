<?php

namespace App\Services;

use App\Models\BookingSearch;
use App\Models\Vehicle\VehicleGroup;
use App\Models\Vehicle\VehiclePricing\VehicleGroupPricing;
use App\Models\Vehicle\VehiclePricing\VehiclePricingSlabDefinition;
use App\Models\ServiceType;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class BookingSearchService
{
    /**
     * Store a new booking search
     */
    public function storeSearch(array $data, string $sessionId): BookingSearch
    {
        $serviceType = $data['service_type'];
        
        // Get the service type code for processing
        $serviceTypeCode = $this->getServiceTypeCode($serviceType);
        
        // Parse dates based on service type
        $dates = $this->parseDates($data, $serviceTypeCode);
        
        // Extract locations
        $locations = $this->extractLocations($data, $serviceTypeCode);
        
        $search = BookingSearch::create([
            'session_id' => $sessionId,
            'service_type' => $serviceType,
            'search_data' => $data,
            'pickup_date' => $dates['pickup_date'],
            'dropoff_date' => $dates['dropoff_date'] ?? null,
            'pickup_location' => $locations['pickup'],
            'dropoff_location' => $locations['dropoff'],
            'customer_id' => Auth::check() ? Auth::id() : null,
            'ip_address' => request()->ip(),
        ]);
        
        $search->calculateDuration();
        $search->save();
        
        return $search;
    }
    
    /**
     * Search for available vehicle groups
     */
    public function searchVehicleGroups(BookingSearch $search): array
    {
        $serviceType = ServiceType::where('code', $search->service_type)->first();
        
        if (!$serviceType) {
            return [];
        }
        
        // Get all active vehicle groups with pricing
        $vehicleGroups = VehicleGroup::with([
            'grade',
            'make',
            'model',
            'transmission',
            'fuelType',
            'category',
            'class'
        ])
        ->where('is_active', true)
        ->get();
        
        $results = [];
        
        foreach ($vehicleGroups as $group) {
            $pricing = $this->calculatePricingForGroup($group, $search, $serviceType);
            
            if ($pricing) {
                $results[] = [
                    'vehicle_group' => $group,
                    'pricing' => $pricing,
                    'availability' => $this->checkAvailability($group, $search),
                ];
            }
        }
        
        // Sort by price
        usort($results, function($a, $b) {
            return $a['pricing']['total_amount'] <=> $b['pricing']['total_amount'];
        });
        
        return $results;
    }
    
    /**
     * Calculate pricing for a vehicle group
     */
    protected function calculatePricingForGroup(VehicleGroup $group, BookingSearch $search, ServiceType $serviceType): ?array
    {
        $durationHours = $search->duration_hours ?? 24;
        $durationDays = $search->duration_days ?? 1;
        
        // Find applicable pricing slab
        $slabDefinition = VehiclePricingSlabDefinition::where('service_type_id', $serviceType->id)
            ->where('is_active', true)
            ->where(function($query) use ($durationHours, $durationDays) {
                $query->where(function($q) use ($durationHours) {
                    $q->whereNotNull('min_hours')
                      ->whereNotNull('max_hours')
                      ->where('min_hours', '<=', $durationHours)
                      ->where('max_hours', '>=', $durationHours);
                })
                ->orWhere(function($q) use ($durationDays) {
                    $q->whereNotNull('min_days')
                      ->whereNotNull('max_days')
                      ->where('min_days', '<=', $durationDays)
                      ->where('max_days', '>=', $durationDays);
                });
            })
            ->orderBy('sort_order')
            ->first();
        
        if (!$slabDefinition) {
            return null;
        }
        
        // Get vehicle group pricing for this slab
        $groupPricing = VehicleGroupPricing::where('vehicle_group_id', $group->id)
            ->where('slab_definition_id', $slabDefinition->id)
            ->where('is_active', true)
            ->first();
        
        if (!$groupPricing) {
            return null;
        }
        
        // Calculate base amount
        $baseAmount = $this->calculateBaseAmount(
            (float) $groupPricing->rate,
            $groupPricing->rate_type,
            $durationHours,
            $durationDays,
            $groupPricing->minimum_charge ? (float) $groupPricing->minimum_charge : null
        );
        
        // Calculate common rates (driver allowance, delivery charges, etc.)
        $commonRates = $this->calculateCommonRates($group, $serviceType, $baseAmount, $search);
        
        // Calculate total
        $totalAmount = $baseAmount + array_sum(array_column($commonRates, 'amount'));
        
        return [
            'slab_definition' => $slabDefinition,
            'group_pricing' => $groupPricing,
            'base_amount' => round($baseAmount, 2),
            'common_rates' => $commonRates,
            'total_amount' => round($totalAmount, 2),
            'rate_type' => $groupPricing->rate_type,
            'includes_fuel' => $groupPricing->includes_fuel,
            'includes_driver' => $groupPricing->includes_driver,
            'duration' => [
                'hours' => $durationHours,
                'days' => $durationDays,
            ],
            'currency' => 'LKR',
        ];
    }
    
    /**
     * Calculate base amount based on rate type
     */
    protected function calculateBaseAmount(float $rate, string $rateType, int $hours, int $days, ?float $minimumCharge): float
    {
        $amount = 0;
        
        switch ($rateType) {
            case 'per_hour':
                $amount = $rate * $hours;
                break;
            case 'per_day':
                $amount = $rate * $days;
                break;
            case 'flat_rate':
                $amount = $rate;
                break;
        }
        
        if ($minimumCharge && $amount < $minimumCharge) {
            $amount = $minimumCharge;
        }
        
        return $amount;
    }
    
    /**
     * Calculate common rates for a vehicle group
     */
    protected function calculateCommonRates(VehicleGroup $group, ServiceType $serviceType, float $baseAmount, BookingSearch $search): array
    {
        $commonRates = [];
        
        // Get vehicle group common rate pricing
        $groupCommonRates = DB::table('vehicle_group_common_rate_pricings')
            ->join('vehicle_pricing_common_rate_definitions', 'vehicle_group_common_rate_pricings.common_rate_definition_id', '=', 'vehicle_pricing_common_rate_definitions.id')
            ->where('vehicle_group_common_rate_pricings.vehicle_group_id', $group->id)
            ->where('vehicle_pricing_common_rate_definitions.service_type_id', $serviceType->id)
            ->where('vehicle_pricing_common_rate_definitions.is_active', true)
            ->select(
                'vehicle_pricing_common_rate_definitions.*',
                'vehicle_group_common_rate_pricings.value as group_value'
            )
            ->get();
        
        foreach ($groupCommonRates as $rate) {
            $amount = $this->calculateCommonRateAmount($rate, $baseAmount, $search);
            
            if ($amount > 0) {
                $commonRates[] = [
                    'id' => $rate->id,
                    'name' => $rate->name,
                    'type' => $rate->addon_type,
                    'amount' => round($amount, 2),
                    'is_mandatory' => $rate->is_mandatory,
                ];
            }
        }
        
        return $commonRates;
    }
    
    /**
     * Calculate individual common rate amount
     */
    protected function calculateCommonRateAmount($rate, float $baseAmount, BookingSearch $search): float
    {
        $value = $rate->group_value ?? $rate->value;
        
        switch ($rate->addon_type) {
            case 'percentage':
                return ($baseAmount * $value) / 100;
            case 'per_hour':
                return $value * ($search->duration_hours ?? 24);
            case 'per_day':
                return $value * ($search->duration_days ?? 1);
            case 'per_km':
                return $value * ($search->estimated_distance ?? 0);
            case 'fixed_amount':
                return $value;
            default:
                return 0;
        }
    }
    
    /**
     * Check vehicle group availability
     */
    protected function checkAvailability(VehicleGroup $group, BookingSearch $search): array
    {
        // Count total vehicles in the group
        $totalVehicles = $group->vehicles()->where('is_active', true)->count();
        
        // Count booked vehicles in the date range
        $bookedVehicles = DB::table('bookings')
            ->where('vehicle_group_id', $group->id)
            ->where(function($query) use ($search) {
                $query->whereBetween('from_date', [$search->pickup_date, $search->dropoff_date ?? $search->pickup_date])
                    ->orWhereBetween('to_date', [$search->pickup_date, $search->dropoff_date ?? $search->pickup_date])
                    ->orWhere(function($q) use ($search) {
                        $q->where('from_date', '<=', $search->pickup_date)
                          ->where('to_date', '>=', $search->dropoff_date ?? $search->pickup_date);
                    });
            })
            ->whereNotIn('status', ['cancelled', 'rejected'])
            ->count();
        
        $availableCount = $totalVehicles - $bookedVehicles;
        
        return [
            'total' => $totalVehicles,
            'available' => max(0, $availableCount),
            'is_available' => $availableCount > 0,
        ];
    }
    
    /**
     * Parse dates from search data
     */
    protected function parseDates(array $data, string $serviceType): array
    {
        $pickupDate = null;
        $dropoffDate = null;
        
        switch ($serviceType) {
            case 'airport-transfer':
                $pickupDate = $this->parseDateTime($data['date'], $data['time'] ?? '00:00');
                break;
                
            case 'drop-pickup':
                $pickupDate = $this->parseDateTime($data['date'], $data['time'] ?? '00:00');
                if (!empty($data['need_return']) && !empty($data['return_date'])) {
                    $dropoffDate = $this->parseDateTime($data['return_date'], $data['return_time'] ?? '00:00');
                }
                break;
                
            case 'rental-packages':
                $pickupDate = $this->parseDateTime($data['pickup_date'], $data['pickup_time'] ?? '00:00');
                $dropoffDate = $this->parseDateTime($data['dropoff_date'], $data['dropoff_time'] ?? '00:00');
                break;
                
            case 'custom-tour':
                $pickupDate = $this->parseDateTime($data['pickup_date'], '00:00');
                // For custom tour, dropoff might be calculated from destinations
                break;
        }
        
        return [
            'pickup_date' => $pickupDate,
            'dropoff_date' => $dropoffDate,
        ];
    }
    
    /**
     * Parse date and time into Carbon instance
     */
    protected function parseDateTime(?string $date, string $time): ?Carbon
    {
        if (!$date) {
            return null;
        }
        
        try {
            // Try different date formats
            $formats = [
                'm/d/Y H:i',  // MM/DD/YYYY (US format)
                'd/m/Y H:i',  // DD/MM/YYYY (EU format)
                'Y-m-d H:i',  // YYYY-MM-DD (ISO format)
            ];
            
            foreach ($formats as $format) {
                try {
                    $parsed = Carbon::createFromFormat($format, $date . ' ' . $time);
                    return $parsed;
                } catch (\Exception $e) {
                    continue;
                }
            }
            
            // If all formats fail, try to parse with Carbon's intelligent parsing
            return Carbon::parse($date . ' ' . $time);
            
        } catch (\Exception $e) {
            // Log the error for debugging
            Log::warning('Failed to parse date/time', [
                'date' => $date,
                'time' => $time,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
    
    /**
     * Extract locations from search data
     */
    protected function extractLocations(array $data, string $serviceType): array
    {
        $pickup = null;
        $dropoff = null;
        
        switch ($serviceType) {
            case 'airport-transfer':
                $pickup = $data['from'] ?? null;
                $dropoff = $data['to'] ?? null;
                break;
                
            case 'drop-pickup':
                $pickup = $data['pickup'] ?? null;
                $dropoff = $data['dropoff'] ?? null;
                break;
                
            case 'rental-packages':
                $pickup = $data['pickup'] ?? null;
                $dropoff = $data['dropoff'] ?? null;
                break;
                
            case 'custom-tour':
                $pickup = $data['starting_location'] ?? null;
                break;
        }
        
        return [
            'pickup' => $pickup,
            'dropoff' => $dropoff,
        ];
    }
    
    /**
     * Get search by ID
     */
    public function getSearch(string $searchId): ?BookingSearch
    {
        return BookingSearch::find($searchId);
    }
    
    /**
     * Get search by session ID
     */
    public function getSearchBySession(string $sessionId): ?BookingSearch
    {
        return BookingSearch::where('session_id', $sessionId)
            ->latest()
            ->first();
    }

    /**
     * Get service type code from UUID or string
     */
    protected function getServiceTypeCode(string $serviceType): string
    {
        // If it's already a code (not UUID), return as is
        if (!$this->isValidUuid($serviceType)) {
            return $serviceType;
        }
        
        // Get service type by UUID
        $serviceTypeModel = ServiceType::find($serviceType);
        if ($serviceTypeModel) {
            // Convert database code to form format
            return strtolower(str_replace('_', '-', $serviceTypeModel->code));
        }
        
        // Fallback
        return 'airport-transfer';
    }

    /**
     * Check if a string is a valid UUID
     */
    protected function isValidUuid(string $uuid): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid) === 1;
    }
}
