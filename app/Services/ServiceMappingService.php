<?php

namespace App\Services;

use App\Models\ServiceType;
use App\Models\Vehicle\VehiclePricing\VehiclePricingSlabDefinition;
use App\Models\Vehicle\VehiclePricing\VehiclePricingCommonRateDefinition;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ServiceMappingService
{
    /**
     * Map frontend service types to backend service codes
     */
    public const FRONTEND_TO_BACKEND_MAPPING = [
        // Frontend form service => Backend service code
        'airport-transfer' => ['airport_drop', 'airport_pickup'], // Both depending on transfer_type
        'drop-pickup' => ['transfers'], // Point to point transfers
        'rental-packages' => ['chauffeur_driven', 'self_driven'], // Based on driver requirement
        'custom-tour' => ['chauffeur_driven'], // Usually with driver
        'corporate-transport' => ['corporate', 'corporate_self'], // Based on self-drive option
    ];

    /**
     * Detailed service type descriptions for frontend
     */
    public const SERVICE_DESCRIPTIONS = [
        'airport-transfer' => [
            'name' => 'Airport Transfer',
            'description' => 'Direct transfers to/from airport with professional service',
            'icon' => 'plane',
            'features' => ['Professional Driver', 'Flight Tracking', 'Meet & Greet', 'Fixed Pricing']
        ],
        'drop-pickup' => [
            'name' => 'Drop & Pickup',
            'description' => 'Point-to-point transportation with optional return journey',
            'icon' => 'map-pin',
            'features' => ['Flexible Timing', 'Multiple Stops', 'Return Option', 'Real-time Tracking']
        ],
        'rental-packages' => [
            'name' => 'Rental Packages',
            'description' => 'Long-term vehicle rental with flexible duration options',
            'icon' => 'calendar',
            'features' => ['Daily/Weekly/Monthly', 'Self-Drive Available', 'Unlimited KM Options', 'Insurance Included']
        ],
        'custom-tour' => [
            'name' => 'Custom Tour',
            'description' => 'Personalized tours with multiple destinations and flexible itinerary',
            'icon' => 'route',
            'features' => ['Custom Itinerary', 'Multiple Destinations', 'Local Guide', 'Flexible Duration']
        ],
        'corporate-transport' => [
            'name' => 'Corporate Transport',
            'description' => 'Professional business transportation solutions',
            'icon' => 'briefcase',
            'features' => ['Bulk Booking', 'Account Management', 'Priority Support', 'Flexible Payment']
        ]
    ];

    /**
     * Get backend service type based on frontend form data
     */
    public function getBackendServiceType(array $formData): ?ServiceType
    {
        $frontendService = $formData['service_type'] ?? null;
        
        if (!$frontendService || !isset(self::FRONTEND_TO_BACKEND_MAPPING[$frontendService])) {
            return null;
        }

        $backendCodes = self::FRONTEND_TO_BACKEND_MAPPING[$frontendService];
        
        // Handle specific logic for different services
        switch ($frontendService) {
            case 'airport-transfer':
                $transferType = $formData['transfer_type'] ?? 'from-airport';
                $code = $transferType === 'to-airport' ? 'airport_drop' : 'airport_pickup';
                break;
                
            case 'rental-packages':
                // Check if self-drive is requested (this would come from additional form logic)
                $selfDrive = $formData['self_drive'] ?? false;
                $code = $selfDrive ? 'self_driven' : 'chauffeur_driven';
                break;
                
            case 'corporate-transport':
                // Check if self-drive corporate
                $selfDrive = $formData['self_drive'] ?? false;
                $code = $selfDrive ? 'corporate_self' : 'corporate';
                break;
                
            default:
                $code = $backendCodes[0]; // Use first available
        }

        return ServiceType::where('code', $code)->first();
    }

    /**
     * Get all available slabs for a service type
     */
    public function getServiceSlabs(string $serviceCode): Collection
    {
        return Cache::remember("service_slabs_{$serviceCode}", 3600, function () use ($serviceCode) {
            $serviceType = ServiceType::where('code', $serviceCode)->first();
            
            if (!$serviceType) {
                return collect();
            }

            return VehiclePricingSlabDefinition::where('service_type_id', $serviceType->id)
                ->orderBy('sort_order')
                ->get();
        });
    }

    /**
     * Get appropriate slab based on duration
     */
    public function getApplicableSlab(string $serviceCode, int $days, int $hours = 0): ?VehiclePricingSlabDefinition
    {
        $slabs = $this->getServiceSlabs($serviceCode);
        
        foreach ($slabs as $slab) {
            if ($this->doesSlabApply($slab, $days, $hours)) {
                return $slab;
            }
        }
        
        return null;
    }

    /**
     * Check if a slab applies to given duration
     */
    protected function doesSlabApply(VehiclePricingSlabDefinition $slab, int $days, int $hours): bool
    {
        // For day-based slabs
        if ($slab->min_days && $slab->max_days) {
            return $days >= $slab->min_days && $days <= $slab->max_days;
        }
        
        // For hour-based slabs
        if ($slab->min_hours && $slab->max_hours) {
            return $hours >= $slab->min_hours && $hours <= $slab->max_hours;
        }
        
        // For mixed duration slabs
        if ($slab->min_days && $slab->max_hours) {
            $totalHours = ($days * 24) + $hours;
            return $totalHours >= ($slab->min_days * 24) && $totalHours <= $slab->max_hours;
        }
        
        return false;
    }

    /**
     * Get common rates for a service type
     */
    public function getServiceCommonRates(string $serviceCode): Collection
    {
        return Cache::remember("service_rates_{$serviceCode}", 3600, function () use ($serviceCode) {
            $serviceType = ServiceType::where('code', $serviceCode)->first();
            
            if (!$serviceType) {
                return collect();
            }

            return VehiclePricingCommonRateDefinition::where('service_type_id', $serviceType->id)
                ->orderBy('sort_order')
                ->get();
        });
    }

    /**
     * Calculate duration from dates
     */
    public function calculateDuration(string $startDate, string $endDate, ?string $startTime = null, ?string $endTime = null): array
    {
        try {
            $start = \Carbon\Carbon::parse($startDate . ' ' . ($startTime ?? '00:00'));
            $end = \Carbon\Carbon::parse($endDate . ' ' . ($endTime ?? '23:59'));
            
            $totalHours = $end->diffInHours($start);
            $days = $end->diffInDays($start);
            
            // Minimum 1 day for day-based services
            if ($days == 0 && $totalHours > 4) {
                $days = 1;
            }
            
            return [
                'days' => max(1, $days),
                'hours' => $totalHours,
                'total_hours' => $totalHours
            ];
        } catch (\Exception $e) {
            Log::error('Error calculating duration', [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'error' => $e->getMessage()
            ]);
            
            return ['days' => 1, 'hours' => 24, 'total_hours' => 24];
        }
    }

    /**
     * Get service pricing context for frontend display
     */
    public function getServicePricingContext(string $frontendService, array $formData): array
    {
        $serviceType = $this->getBackendServiceType($formData);
        
        if (!$serviceType) {
            return [];
        }

        $duration = $this->calculateDurationFromForm($formData);
        $slab = $this->getApplicableSlab($serviceType->code, $duration['days'], $duration['hours']);
        $commonRates = $this->getServiceCommonRates($serviceType->code);
        
        return [
            'service_type' => $serviceType,
            'frontend_service' => $frontendService,
            'duration' => $duration,
            'applicable_slab' => $slab,
            'common_rates' => $commonRates,
            'pricing_type' => $slab ? $slab->type : 'per_day',
            'service_description' => self::SERVICE_DESCRIPTIONS[$frontendService] ?? []
        ];
    }

    /**
     * Calculate duration from form data
     */
    protected function calculateDurationFromForm(array $formData): array
    {
        // Handle different form structures
        if (isset($formData['pickup_date']) && isset($formData['dropoff_date'])) {
            return $this->calculateDuration(
                $formData['pickup_date'],
                $formData['dropoff_date'],
                $formData['pickup_time'] ?? null,
                $formData['dropoff_time'] ?? null
            );
        }
        
        if (isset($formData['date'])) {
            // Single day service
            return ['days' => 1, 'hours' => 8, 'total_hours' => 8];
        }
        
        return ['days' => 1, 'hours' => 24, 'total_hours' => 24];
    }

    /**
     * Get all frontend services with their descriptions
     */
    public function getAllFrontendServices(): array
    {
        return self::SERVICE_DESCRIPTIONS;
    }

    /**
     * Get service type recommendations based on form data
     */
    public function getServiceRecommendations(array $formData): array
    {
        $recommendations = [];
        $duration = $this->calculateDurationFromForm($formData);
        
        // Recommend based on duration
        if ($duration['days'] >= 7) {
            $recommendations[] = 'rental-packages';
        }
        
        if (isset($formData['destinations']) && count($formData['destinations']) > 2) {
            $recommendations[] = 'custom-tour';
        }
        
        if (isset($formData['from']) && (strpos(strtolower($formData['from']), 'airport') !== false)) {
            $recommendations[] = 'airport-transfer';
        }
        
        return array_unique($recommendations);
    }
}