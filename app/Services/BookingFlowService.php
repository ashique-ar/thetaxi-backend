<?php

namespace App\Services;

use App\Models\Booking\Booking;
use App\Models\Booking\BookingApproval;
use App\Models\Booking\BookingAddon;
use App\Models\Booking\BookingItem;
use App\Models\Booking\BookingVariableCustomization;
use App\Models\Vehicle\VehicleAddon;
use App\Models\Vehicle\VehicleGroup;
use App\Models\Vehicle\Vehicle;
use App\Models\Vehicle\VehicleAssignment;
use App\Models\Driver\Driver;
use App\Models\DriverAssignment;
use App\Models\Customer;
use App\Models\Service\ServiceType;
use App\Models\Service\ServicePackage;
use App\Models\Company;
use App\Models\Vehicle\VehiclePricing\VehiclePricingCalculationDefinition;
use App\Models\Vehicle\VehiclePricing\VehiclePricingCommonRateDefinition;
use App\Models\Vehicle\VehiclePricing\VehicleGroupCommonRatePricing;
use App\Models\Vehicle\VehiclePricing\DistrictPricingAdjustment;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use App\Services\CurrencyService;
use App\Services\DiscountService;
use Ramsey\Uuid\Uuid;
use App\Services\PricingVariableService;
use App\Services\AssignmentService;


class BookingFlowService
{
    protected CurrencyService $currencyService;
    protected PricingVariableService $pricingVariableService;
    protected AssignmentService $assignmentService;

    public function __construct(CurrencyService $currencyService, PricingVariableService $pricingVariableService, AssignmentService $assignmentService)
    {
        $this->currencyService = $currencyService;
        $this->pricingVariableService = $pricingVariableService;
        $this->assignmentService = $assignmentService;
    }

    public function buildVehicleSearchQuery(
        Carbon $fromDate,
        Carbon $toDate,
        ?int $excludeBookingId = null,
        bool $isPublic = false
    ) {

        $vehicleAvailabilityConstraint = function ($query) use ($fromDate, $toDate, $excludeBookingId) {
            // Filter for active vehicles only
            $query->where('is_active', true);

            $query->when($excludeBookingId, function ($q) use ($fromDate, $toDate, $excludeBookingId) {
                // Edit mode: ignore current booking
                $q->whereNotExists(function ($subQuery) use ($fromDate, $toDate, $excludeBookingId) {
                    $subQuery->select(DB::raw(1))
                        ->from('booking_items')
                        ->join('bookings', 'booking_items.booking_id', '=', 'bookings.id')
                        ->whereColumn('booking_items.vehicle_id', 'vehicles.id')
                        ->where('bookings.status', '!=', 'cancelled')
                        ->where('bookings.id', '!=', $excludeBookingId)
                        ->where(function ($q) use ($fromDate, $toDate) {
                            $q->whereBetween('booking_items.from_date', [$fromDate, $toDate])
                                ->orWhereBetween('booking_items.to_date', [$fromDate, $toDate])
                                ->orWhere(function ($inner) use ($fromDate, $toDate) {
                                    $inner->where('booking_items.from_date', '<=', $fromDate)
                                        ->where('booking_items.to_date', '>=', $toDate);
                                });
                        });
                });
            }, function ($q) use ($fromDate, $toDate) {
                // Normal mode
                $q->whereNotExists(function ($subQuery) use ($fromDate, $toDate) {
                    $subQuery->select(DB::raw(1))
                        ->from('booking_items')
                        ->join('bookings', 'booking_items.booking_id', '=', 'bookings.id')
                        ->whereColumn('booking_items.vehicle_id', 'vehicles.id')
                        ->where('bookings.status', '!=', 'cancelled')
                        ->where(function ($q) use ($fromDate, $toDate) {
                            $q->whereBetween('booking_items.from_date', [$fromDate, $toDate])
                                ->orWhereBetween('booking_items.to_date', [$fromDate, $toDate])
                                ->orWhere(function ($inner) use ($fromDate, $toDate) {
                                    $inner->where('booking_items.from_date', '<=', $fromDate)
                                        ->where('booking_items.to_date', '>=', $toDate);
                                });
                        });
                });
            });
        };

        $query = VehicleGroup::query();


        if ($isPublic) {
            // public: hide groups without available vehicles
            $query->with([
                'vehicles' => $vehicleAvailabilityConstraint,
            ])->whereHas('vehicles', $vehicleAvailabilityConstraint);
        } else {
            $query->with([
                'grade',
                'make',
                'model',
                'transmission',
                'fuelType',
                'category',
                'class',
                'vehicles' => $vehicleAvailabilityConstraint,
            ]);
        }

        return $query;
    }
    public function getAvailableVehicleGroups(array $params, bool $isPublic = false): array
    {
        $serviceType = $params['service_type'];
        $fromDate = Carbon::parse($params['from_date']);
        $toDate = isset($params['to_date']) ? Carbon::parse($params['to_date']) : $fromDate;
        $fromTime = $params['from_time'];
        $toTime = isset($params['to_time']) ? Carbon::parse($params['to_time']) : null;
        $pickupLocation = $params['pickup_location'] ?? null;
        $dropoffLocation = $params['dropoff_location'] ?? null;
        $customerId = $params['customer_id'] ?? null;
        $excludeBookingId = $params['exclude_booking_id'] ?? null; // For edit mode

        // New filtering and pagination parameters
        $search = isset($params['vehicle_group_filter']['search']) ? trim((string) $params['vehicle_group_filter']['search']) : null;

        $categoryId = $params['vehicle_group_filter']['category_id'] ?? ($params['vehicle_group_filter']['category_filter'] ?? null);
        $makeId = $params['vehicle_group_filter']['make_id'] ?? null;
        $modelId = $params['vehicle_group_filter']['model_id'] ?? null;
        $classId = $params['vehicle_group_filter']['class_id'] ?? null;
        $fuelTypeId = $params['vehicle_group_filter']['fuel_type_id'] ?? null;
        $transmissionId = $params['vehicle_group_filter']['transmission_id'] ?? null;
        $gradeId = $params['vehicle_group_filter']['grade_id'] ?? null;


        $page = (int) ($params['page'] ?? 1);
        $perPage = (int) ($params['per_page'] ?? 20);
        $forceRefresh = $params['force_refresh'] ?? false;


        $baseQuery = $this->buildVehicleSearchQuery($fromDate, $toDate, $excludeBookingId, $isPublic);

        // Apply search filter
        if ($search !== '') {
            $baseQuery->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhereHas('make', fn($qq) => $qq->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('model', fn($qq) => $qq->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('category', fn($qq) => $qq->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('grade', fn($qq) => $qq->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('class', fn($qq) => $qq->where('name', 'like', "%{$search}%"));
            });
        }

        // ----- Exact ID filters (all optional) -----
        if (!empty($categoryId)) {
            $baseQuery->where('category_id', $categoryId);
        }
        if (!empty($makeId)) {
            $baseQuery->where('make_id', $makeId);
        }
        if (!empty($modelId)) {
            $baseQuery->where('model_id', $modelId);
        }
        if (!empty($classId)) {
            $baseQuery->where('class_id', $classId);
        }
        if (!empty($fuelTypeId)) {
            $baseQuery->where('fuel_type_id', $fuelTypeId);
        }
        if (!empty($transmissionId)) {
            $baseQuery->where('transmission_id', $transmissionId);
        }
        if (!empty($gradeId)) {
            $baseQuery->where('grade_id', $gradeId);
        }

        // Get total count for pagination
        $total = $baseQuery->count();

        // Apply pagination
        $vehicleGroups = $baseQuery->orderBy('name', 'asc')
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get();

        $availability = [];

        foreach ($vehicleGroups as $group) {
            // Get detailed vehicle analysis
            $vehicleAnalysis = $this->analyzeVehicleAvailability($group, $fromDate, $toDate, $excludeBookingId);

            $availableCount = $vehicleAnalysis['available_count'];
            $totalCount = $vehicleAnalysis['total_count'];
            $bookedCount = $vehicleAnalysis['booked_count'];
            $conflictCount = $vehicleAnalysis['conflict_count'];

            // Calculate pricing for this vehicle group
            $pricingInfo = null;
            $isPricingConfigured = false;
            $pricingError = null;

            try {
                // Calculate duration
                $durationInfo = $this->calculateDurationInDaysAndHours($fromDate, $toDate);

                // Get service type model to ensure we have correct ID
                $serviceTypeModel = ServiceType::where('id', $serviceType)
                    ->orWhere('code', $serviceType)
                    ->orWhere('name', $serviceType)
                    ->first();

                if ($serviceTypeModel) {
                    // Use dynamic pricing calculation for accurate results
                    $pricingParams = [
                        'service_type_id' => $serviceTypeModel->id,
                        'vehicle_group_id' => $group->id,
                        'duration_hours' => $durationInfo['total_hours'],
                        'duration_days' => $durationInfo['days'],
                        'pickup_location' => $pickupLocation,
                        'dropoff_location' => $dropoffLocation,
                        'package_id' => $params['package_id'] ?? null,
                        'mode' => 'preview'
                    ];


                    $basePricing = $this->calculateDynamicPricing($pricingParams);
                    if ($basePricing && isset($basePricing['total_amount']) && $basePricing['total_amount'] > 0) {
                        $isPricingConfigured = true;
                        $pricingInfo = [
                            'base_amount' => $basePricing['total_amount'],
                            'currency' => 'LKR',
                            'breakdown' => $basePricing['breakdown'] ?? [],
                            'distance_details' => $basePricing['distance_details'] ?? null,
                            'duration_info' => $durationInfo,
                            'pricing_note' => $this->generatePricingNote($basePricing, $durationInfo)
                        ];

                        // Log for debugging distance_details flow
                        Log::debug('GetAvailableVehicleGroups - Pricing info built', [
                            'vehicle_group_id' => $group->id,
                            'has_distance_details' => isset($basePricing['distance_details']),
                            'distance_details' => $basePricing['distance_details'] ?? 'NOT SET',
                            'base_amount' => $basePricing['total_amount'],
                        ]);
                    }
                }
            } catch (\Exception $e) {
                $pricingError = 'Pricing calculation failed: ' . $e->getMessage();
                Log::warning("Pricing calculation failed for vehicle group {$group->id}: " . $e->getMessage());
            }
            $sampleVehicle = $group->vehicles->first();
            $hasLongTermAssignments = false;

            if ($sampleVehicle) {
                // Check for long-term assignments
                $hasLongTermAssignments = $group->vehicles->filter(function ($vehicle) use ($fromDate, $toDate) {
                    return BookingItem::where('booking_items.vehicle_id', $vehicle->id)
                        ->join('bookings', 'booking_items.booking_id', '=', 'bookings.id')
                        ->where('bookings.status', '!=', 'cancelled')
                        ->where(function ($q) use ($fromDate, $toDate) {
                            $q->whereBetween('booking_items.from_date', [$fromDate, $toDate])
                                ->orWhereBetween('booking_items.to_date', [$fromDate, $toDate])
                                ->orWhere(function ($inner) use ($fromDate, $toDate) {
                                    $inner->where('booking_items.from_date', '<=', $fromDate)
                                        ->where('booking_items.to_date', '>=', $toDate);
                                });
                        })->exists();
                })->count() > 0;
            }

            // Check if service type requires inquiry (is_inquiry flag)
            $serviceTypeRequiresInquiry = false;
            if ($serviceTypeModel) {
                $serviceTypeRequiresInquiry = $serviceTypeModel->is_inquiry ?? false;
            }

            // Check if vehicle group is active
            $isGroupActive = $group->is_active ?? true;

            // Check if pricing amount is 0 or not configured
            $hasPricing = $isPricingConfigured &&
                isset($pricingInfo['base_amount']) &&
                $pricingInfo['base_amount'] > 0;

            // Check if any vehicles are available in the group
            $hasAvailableVehicles = $availableCount > 0;

            // Check if vehicle group is inquiry-only (force quotation)
            $isInquiryOnly = $group->is_inquiry_only ?? false;

            // Determine if this vehicle group should show Request Quotation instead of Add to Cart/Book Now
            // Conditions for quotation-only mode:
            // 1. Price is 0 or not configured
            // 2. Vehicle group is not active
            // 3. No vehicles available in the group
            // 4. Vehicle group is marked as inquiry-only
            // 5. Service type requires inquiry
            // 6. Pricing calculation error occurred
            // 7. Force quotation request flag is set
            $quotationOnlyReasons = [];

            if (!$hasPricing) {
                $quotationOnlyReasons[] = 'pricing_not_configured';
            }
            if (!$isGroupActive) {
                $quotationOnlyReasons[] = 'group_inactive';
            }
            if (!$hasAvailableVehicles && $totalCount > 0) {
                $quotationOnlyReasons[] = 'no_vehicles_available';
            }
            if ($isInquiryOnly) {
                $quotationOnlyReasons[] = 'inquiry_only_vehicle';
            }
            if ($serviceTypeRequiresInquiry) {
                $quotationOnlyReasons[] = 'service_requires_inquiry';
            }
            if ($pricingError) {
                $quotationOnlyReasons[] = 'pricing_error';
            }
            if ($group->force_quotation_request ?? false) {
                $quotationOnlyReasons[] = 'force_quotation';
            }

            $isQuotationOnly = !empty($quotationOnlyReasons);
            $allowRequestQuotation = $isQuotationOnly;

            // Determine if booking/cart is allowed (opposite of quotation-only)
            $allowBooking = !$isQuotationOnly && $hasAvailableVehicles && $hasPricing && $isGroupActive;

            // Build availability entry (include all groups, even those without pricing)
            $availabilityEntry = [
                'id' => $group->id,
                'name' => $group->name,
                'category' => $group->category,
                'description' => $group->description,
                'thumbnail' => $group->thumbnail,
                'features' => $group->features ?? [],
                'available_count' => $availableCount,
                'total_count' => $totalCount,
                'booked_count' => $bookedCount,
                'conflict_count' => $conflictCount,
                'vehicle_details' => $vehicleAnalysis['vehicle_details'],
                'has_long_term' => $hasLongTermAssignments,
                'pricing_configured' => $isPricingConfigured,
                'pricing_info' => $pricingInfo,
                'pricing_error' => $pricingError,
                'supports_self_driven' => $group->supports_self_driven ?? false,
                'requires_driver' => $group->requires_driver ?? true,
                'disabled' => false, // Don't disable - show Request Quotation instead
                'disabled_reason' => null,
                'allow_request_quotation' => $allowRequestQuotation,
                'show_request_quotation' => $isQuotationOnly,
                'quotation_only' => $isQuotationOnly,
                'quotation_only_reasons' => $quotationOnlyReasons,
                'allow_booking' => $allowBooking,
                'is_group_active' => $isGroupActive,
                'is_inquiry_only' => $isInquiryOnly,
                'service_requires_inquiry' => $serviceTypeRequiresInquiry,
                'availability_status' => $this->determineGroupAvailabilityStatus($availableCount, $totalCount, $conflictCount),
                'concurrent_bookings_possible' => $vehicleAnalysis['concurrent_possible'],
                'override_options_available' => $vehicleAnalysis['override_available'],
                // Add new vehicle group fields
                'passengers_count' => $group->passengers_count,
                'hand_luggages' => $group->hand_luggages,
                'air_conditioning' => $group->air_conditioning,
                'refundable_deposit' => $group->refundable_deposit,
                // Add make, model, grade for easier access
                'make' => $group->make,
                'model' => $group->model,
                'grade' => $group->grade,
                'transmission' => $group->transmission,
                'fuel_type' => $group->fuelType,
                'class' => $group->class,
            ];

            $availability[] = $availabilityEntry;
        }

        // Calculate total journey distance if locations are provided
        $totalJourneyDistance = null;
        if ($pickupLocation && $dropoffLocation) {
            $distanceData = $this->calculateCompanyDistances($pickupLocation, $dropoffLocation, $serviceType);
            $totalJourneyDistance = $distanceData['journey_distance'] ?? null;
        }

        // Return with pagination if requested
        if (isset($params['page']) || isset($params['per_page'])) {
            return [
                'data' => $availability,
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'last_page' => ceil($total / $perPage),
                    'from' => ($page - 1) * $perPage + 1,
                    'to' => min($page * $perPage, $total),
                ],
                'total_journey_distance_km' => $totalJourneyDistance,
            ];
        }

        return [
            'data' => $availability,
            'total_journey_distance_km' => $totalJourneyDistance,
        ];
    }

    /**
     * Generate a human-readable pricing note
     */
    private function generatePricingNote(array $basePricing, array $durationInfo): string
    {
        $amount = $basePricing['total_amount'] ?? 0;
        $days = $durationInfo['days'] ?? 0;
        $hours = $durationInfo['hours'] ?? 0;

        $note = "Starting from LKR " . number_format($amount, 2);

        if ($days > 0) {
            $note .= " for {$days} day(s)";
            if ($hours > 0) {
                $note .= " and {$hours} hour(s)";
            }
        } else {
            $note .= " for {$hours} hour(s)";
        }

        if (isset($basePricing['distance_details'])) {
            $distance = $basePricing['distance_details']['journey_distance'] ?? 0;
            if ($distance > 0) {
                $note .= " (≈ " . number_format($distance, 1) . " km)";
            }
        }

        return $note;
    }

    /**
     * Get available vehicles in a specific group
     */
    public function getAvailableVehiclesInGroup(array $params): array
    {
        $vehicleGroupId = $params['vehicle_group_id'];
        $serviceType = $params['service_type'];
        $fromDate = Carbon::parse($params['from_date']);
        $toDate = Carbon::parse($params['to_date']);
        $fromTime = $params['from_time'];
        $toTime = $params['to_time'];
        $includeLongTerm = $params['include_long_term'] ?? false;
        $excludeBookingId = $params['exclude_booking_id'] ?? null; // For edit mode

        // Get ALL vehicles in the group instead of filtering out conflicted ones
        // We'll determine availability status for each vehicle individually
        $query = Vehicle::where('vehicle_group_id', $vehicleGroupId)
            ->where('status', 'active');

        // if (!$includeLongTerm) {
        //     $query->where('is_long_term_only', false);
        // }

        $vehicles = $query->with([
            'maintenanceRecords' => function ($q) {
                $q->where('status', 'scheduled')->orWhere('status', 'in_progress');
            }
        ])->get();

        return $vehicles->map(function ($vehicle) use ($serviceType, $fromDate, $toDate, $excludeBookingId) {
            // Get enhanced assignment information
            $enhancedAvailability = $this->assignmentService->getEnhancedVehicleAvailability(
                $vehicle->id,
                $fromDate,
                $toDate,
                $excludeBookingId
            );

            // Legacy conflict detection for backward compatibility
            $conflicts = $this->getVehicleConflictsDetailed($vehicle, $fromDate, $toDate, $excludeBookingId);
            $availabilityStatus = $enhancedAvailability['availability_status'];
            $requiresConfirmation = $availabilityStatus !== 'available';

            return [
                'id' => $vehicle->id,
                'group_id' => $vehicle->vehicle_group_id,
                'name' => $vehicle->title,
                'make' => $vehicle->group->make,
                'model' => $vehicle->group->model,
                'year' => $vehicle->year,
                'license_plate' => $vehicle->license_plate,
                'image_url' => $vehicle->image_url,
                'fuel_type' => $vehicle->fuel_type,
                'seating_capacity' => $vehicle->seating_capacity,
                'transmission' => $vehicle->transmission,
                'mileage' => $vehicle->current_mileage,
                'last_service_date' => $vehicle->last_service_date,
                'next_service_due' => $vehicle->next_service_due,
                'features' => $vehicle->features ?? [],
                'condition_score' => $vehicle->condition_score ?? 100,
                'fuel_level' => $vehicle->fuel_level ?? 100,
                'is_premium' => $vehicle->is_premium ?? false,
                'has_maintenance_due' => $vehicle->maintenanceRecords->isNotEmpty(),
                'pricing_multiplier' => $vehicle->pricing_multiplier ?? 1.0,

                // Enhanced assignment information
                'availability_status' => $availabilityStatus,
                'requires_confirmation' => $requiresConfirmation,
                'requires_approval' => $requiresConfirmation && $enhancedAvailability['has_conflicts'],
                'conflicts' => $enhancedAvailability['conflicts'],
                'allows_concurrent' => $enhancedAvailability['allows_concurrent'],

                // Driver assignment information
                'current_driver' => $enhancedAvailability['current_driver'],
                'default_driver' => $enhancedAvailability['default_driver'],
                'recommended_driver' => $enhancedAvailability['current_driver'] ?? $enhancedAvailability['default_driver'],
                'force_default_driver' => $vehicle->hasForceDefaultDriver(),
                'auto_select_driver' => $enhancedAvailability['current_driver'] !== null || $enhancedAvailability['default_driver'] !== null,

                // Assignment options and recommendations
                'assignment_options' => $this->assignmentService->generateAssignmentOptions($vehicle, $enhancedAvailability['conflicts'], $fromDate, $toDate),
                'assignment_recommendations' => $enhancedAvailability['assignment_recommendations'],

                // Legacy compatibility
                'assignment_details' => $this->getVehicleAssignmentDetails($vehicle, $conflicts),
                'is_self_driven_compatible' => $this->isVehicleSelfDrivenCompatible($vehicle, $serviceType),
                'override_allowed' => $this->isVehicleOverrideAllowed($vehicle, $conflicts),
                'concurrent_assignment_possible' => $enhancedAvailability['allows_concurrent'],
                'current_assignment' => $this->getCurrentVehicleAssignment($vehicle, $fromDate, $toDate),
                'availability_percentage' => $this->calculateVehicleAvailabilityPercentage($vehicle, $fromDate, $toDate)
            ];
        })->toArray();
    }

    /**
     * Get available drivers for a specific vehicle group and time period
     */
    public function getAvailableDrivers(array $params): array
    {
        $vehicleGroupId = $params['vehicle_group_id'];
        $fromDate = Carbon::parse($params['from_date']);
        $toDate = Carbon::parse($params['to_date']);
        $fromTime = $params['from_time'];
        $toTime = $params['to_time'];
        $excludeBookingId = $params['exclude_booking_id'] ?? null; // For edit mode

        $vehicleGroup = VehicleGroup::findOrFail($vehicleGroupId);
        // $requiredLicenseTypes = $vehicleGroup->required_license_types ?? ['standard'];

        // Get ALL drivers instead of filtering out conflicted ones
        // We'll determine availability status for each driver individually
        $drivers = Driver::where('status', 'active')
            // ->where(function($query) use ($requiredLicenseTypes) {
            //     foreach ($requiredLicenseTypes as $type) {
            //         $query->orWhereJsonContains('license_type', $type);
            //     }
            // })
            // ->with(['ratings', 'emergencyContact'])
            ->get();
        return $drivers->map(function ($driver) use ($fromDate, $toDate, $excludeBookingId) {
            // Get enhanced assignment information
            $enhancedAvailability = $this->assignmentService->getEnhancedDriverAvailability(
                $driver->id,
                $fromDate,
                $toDate,
                $excludeBookingId
            );

            // Legacy conflict detection for backward compatibility
            $conflicts = $this->getDriverConflictsDetailed($driver, $fromDate, $toDate, $excludeBookingId);
            $availabilityStatus = $enhancedAvailability['availability_status'];

            return [
                'id' => $driver->id,
                'name' => $driver->user?->first_name . ' ' . $driver->user?->last_name,
                'phone' => $driver->user?->phone,
                'email' => $driver->user?->email,
                'license_number' => $driver->license_no ?? $driver->license_number,
                'license_type' => $driver->license_type ?? [],
                'experience_years' => $driver->experience_years,
                'rating' => $driver->ratings?->avg('rating') ?? 0,
                'total_trips' => $driver->total_trips ?? 0,
                'profile_image' => $driver->profile_image,
                'languages' => $driver->languages ?? [],
                'specializations' => $driver->specializations ?? [],
                'is_premium' => $driver->is_premium ?? false,
                'hourly_rate' => $driver->hourly_rate,
                'daily_rate' => $driver->daily_rate,
                'pricing_multiplier' => $driver->pricing_multiplier ?? 1.0,
                'status' => $driver->status ?? 'active',

                // Enhanced assignment information
                'availability_status' => $availabilityStatus,
                'has_conflicts' => $enhancedAvailability['has_conflicts'],
                'conflicts' => $enhancedAvailability['conflicts'],
                'requires_confirmation' => $enhancedAvailability['has_conflicts'],
                'requires_approval' => $enhancedAvailability['has_conflicts'],

                // Vehicle assignment information
                'current_vehicle' => $enhancedAvailability['current_vehicle'],
                'default_vehicle' => $enhancedAvailability['default_vehicle'],

                // Assignment recommendations
                'assignment_recommendations' => $enhancedAvailability['assignment_recommendations'],

                // Legacy compatibility
                'override_allowed' => $this->isDriverOverrideAllowed($driver, $conflicts),
                'emergency_contact' => $driver->emergencyContact ? [
                    'name' => $driver->emergencyContact->name,
                    'phone' => $driver->emergencyContact->phone,
                ] : null,
            ];
        })->toArray();
    }

    /**
     * Check for conflicts when selecting a specific vehicle
     */
    public function checkVehicleConflicts(string $vehicleId, array $params): array
    {
        $serviceType = $params['service_type'];
        $fromDate = Carbon::parse($params['from_date']);
        $toDate = Carbon::parse($params['to_date']);
        $fromTime = $params['from_time'];
        $toTime = $params['to_time'];

        $conflicts = [];

        // Check for existing bookings through booking_items
        $existingBookings = BookingItem::where('booking_items.vehicle_id', $vehicleId)
            ->join('bookings', 'booking_items.booking_id', '=', 'bookings.id')
            ->where('bookings.status', '!=', 'cancelled')
            ->where(function ($q) use ($fromDate, $toDate) {
                $q->whereBetween('booking_items.from_date', [$fromDate, $toDate])
                    ->orWhereBetween('booking_items.to_date', [$fromDate, $toDate])
                    ->orWhere(function ($inner) use ($fromDate, $toDate) {
                        $inner->where('booking_items.from_date', '<=', $fromDate)
                            ->where('booking_items.to_date', '>=', $toDate);
                    });
            })
            ->with('booking.customer')
            ->select('booking_items.*')
            ->get()
            ->map(fn($item) => $item->booking);

        foreach ($existingBookings as $booking) {
            $conflicts[] = [
                'type' => 'booking_conflict',
                'booking_id' => $booking->id,
                'customer_name' => $booking->customer->name ?? 'Unknown',
                'from_date' => $booking->from_date,
                'to_date' => $booking->to_date,
                'status' => $booking->status,
                'severity' => 'high',
            ];
        }

        // Check for maintenance schedules
        $vehicle = Vehicle::with('maintenanceRecords')->findOrFail($vehicleId);
        $maintenanceConflicts = $vehicle->maintenanceRecords()
            ->where('status', 'scheduled')
            ->whereBetween('scheduled_date', [$fromDate, $toDate])
            ->get();

        foreach ($maintenanceConflicts as $maintenance) {
            $conflicts[] = [
                'type' => 'maintenance_conflict',
                'maintenance_id' => $maintenance->id,
                'description' => $maintenance->description,
                'scheduled_date' => $maintenance->scheduled_date,
                'estimated_duration' => $maintenance->estimated_duration,
                'severity' => 'medium',
            ];
        }

        return [
            'has_conflicts' => count($conflicts) > 0,
            'conflicts' => $conflicts,
            'vehicle_status' => $vehicle->status,
            'recommendations' => $this->generateConflictRecommendations($conflicts, $vehicleId, $params),
        ];
    }

    /**
     * Check for conflicts when selecting a specific driver
     */
    public function checkDriverConflicts(string $driverId, array $params): array
    {
        $vehicleGroupId = $params['vehicle_group_id'];
        $fromDate = Carbon::parse($params['from_date']);
        $toDate = Carbon::parse($params['to_date']);
        $fromTime = $params['from_time'];
        $toTime = $params['to_time'];

        $conflicts = [];

        // Check for existing bookings through booking_items
        $existingBookings = BookingItem::where('booking_items.driver_id', $driverId)
            ->join('bookings', 'booking_items.booking_id', '=', 'bookings.id')
            ->where('bookings.status', '!=', 'cancelled')
            ->where(function ($q) use ($fromDate, $toDate) {
                $q->whereBetween('booking_items.from_date', [$fromDate, $toDate])
                    ->orWhereBetween('booking_items.to_date', [$fromDate, $toDate])
                    ->orWhere(function ($inner) use ($fromDate, $toDate) {
                        $inner->where('booking_items.from_date', '<=', $fromDate)
                            ->where('booking_items.to_date', '>=', $toDate);
                    });
            })
            ->with('booking.customer', 'booking.vehicle')
            ->select('booking_items.*')
            ->get()
            ->map(fn($item) => $item->booking);

        foreach ($existingBookings as $booking) {
            $conflicts[] = [
                'type' => 'booking_conflict',
                'booking_id' => $booking->id,
                'customer_name' => $booking->customer->name ?? 'Unknown',
                'vehicle_info' => $booking->vehicle ?
                    $booking->vehicle->make . ' ' . $booking->vehicle->model : 'Unknown',
                'from_date' => $booking->from_date,
                'to_date' => $booking->to_date,
                'status' => $booking->status,
                'severity' => 'high',
            ];
        }

        // Check for driver availability preferences
        $driver = Driver::findOrFail($driverId);
        if ($driver->availability_schedule) {
            $availabilityConflicts = $this->checkDriverAvailabilitySchedule(
                $driver->availability_schedule,
                $fromDate,
                $toDate,
                $fromTime,
                $toTime
            );
            $conflicts = array_merge($conflicts, $availabilityConflicts);
        }

        return [
            'has_conflicts' => count($conflicts) > 0,
            'conflicts' => $conflicts,
            'driver_status' => $driver->status,
            'recommendations' => $this->generateDriverConflictRecommendations($conflicts, $driverId, $params),
        ];
    }

    /**
     * Get available add-ons for a specific vehicle group and service type
     */
    public function getAvailableAddons($params): array
    {
        // Handle both old string parameter and new array parameter for backward compatibility
        if (is_string($params)) {
            $serviceType = $params;
            $vehicleGroupId = null;
            $search = null;
            $categoryFilter = null;
            $page = 1;
            $perPage = 50;
        } else {
            $serviceType = $params['service_type'] ?? null;
            $vehicleGroupId = $params['vehicle_group_id'] ?? null;
            $search = $params['search'] ?? null;
            $categoryFilter = $params['category_filter'] ?? null;
            $page = (int) ($params['page'] ?? 1);
            $perPage = (int) ($params['per_page'] ?? 50);
        }

        $serviceTypeModel = ServiceType::where('name', $serviceType)->first();
        $serviceTypeId = $serviceTypeModel ? $serviceTypeModel->id : null;

        $query = VehicleAddon::where('is_active', true);

        // Filter by service type
        if ($serviceTypeId) {
            $query->where(function ($q) use ($serviceTypeId) {
                $q->where('service_type_id', $serviceTypeId)
                    ->orWhereNull('service_type_id');
            });
        } else {
            $query->whereNull('service_type_id');
        }

        // Apply search filter
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('category', 'like', "%{$search}%");
            });
        }

        // Apply category filter
        if ($categoryFilter) {
            $query->where('category', $categoryFilter);
        }

        // Get total count for pagination
        $total = $query->count();

        // Apply pagination
        $addons = $query->orderBy('name', 'asc')
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get();

        $data = $addons->map(function ($addon) {
            return [
                'id' => $addon->id,
                'name' => $addon->name,
                'description' => $addon->description,
                'category' => $addon->category,
                'billing_type' => $addon->billing_type, // 'fixed', 'per_day', 'per_hour', 'percentage'
                'price' => $addon->amount,
                'original_price' => $addon->amount, // Store original for custom pricing comparison
                'is_required' => $addon->is_required ?? false,
                'max_quantity' => $addon->max_quantity ?? 1,
                'dependencies' => $addon->dependencies ?? [],
                'conflicts_with' => $addon->conflicts_with ?? [],
                'image_url' => $addon->image_url,
                'allows_custom_pricing' => true, // Allow custom pricing for all addons
            ];
        })->toArray();

        // Return in consistent format
        if (is_string($params)) {
            // Old format for backward compatibility
            return $data;
        } else {
            // New format with pagination
            return [
                'data' => $data,
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'last_page' => ceil($total / $perPage),
                    'from' => ($page - 1) * $perPage + 1,
                    'to' => min($page * $perPage, $total),
                ]
            ];
        }
    }

    public function submitBookingForApproval(array $params): Booking
    {
        return DB::transaction(function () use ($params) {

            // 1) Calculate pricing with the same payload you got from the controller
            $pricing = $this->calculatePricing($params);

            // 2) Extract quick totals + snapshot from pricing
            $totals = $this->extractTotalsFromPricing($pricing);

            // 3) Create the booking in "pending_approval"
            $booking = new Booking();

            // (No normalization: we use $params directly)
            $booking->customer_id = $params['customer_id'] ?? null;

            // pricing snapshot + quick numbers
            $booking->pricing_snapshot = $totals['pricing_snapshot'];
            $booking->base_amount = $totals['base_amount'];
            $booking->addons_cost = $totals['addons_cost'];
            $booking->discount_amount = $totals['discount_amount'];
            $booking->total_estimated = $totals['total_estimated'];
            $booking->duration_metrics = $totals['duration_metrics'] ?? null;
            $booking->distance_metrics = $totals['distance_metrics'] ?? null;
            $booking->discounts = $params['applied_discounts'] ?? [];

            // status + approval flags
            $booking->status = 'pending_approval';
            $booking->requires_approval = true;
            $booking->approval_status = 'pending';
            $booking->approval_requested_by = Auth::id();
            $booking->approval_requested_at = now();

            $booking->override_reasons = $params['override_reasons'] ?? [];
            $booking->has_overrides = !empty($booking->override_reasons);
            $booking->approval_justification = $params['approval_reason'] ?? ($params['review_notes']['booking_notes'] ?? null);
            $booking->review_notes = $params['review_notes'] ?? null;

            $booking->workflow_step = 'pending_approval';
            $booking->workflow_data = [
                'submitted_at' => now()->toISOString(),
                'submitted_by' => Auth::id(),
                'frontend_data' => $params,
            ];

            $booking->save();

            // 4) Persist addons (straight from selected_addons)
            if (!empty($params['selected_addons'])) {
                $this->syncBookingAddons($booking, $params['selected_addons']);
            }

            // 5) Persist variable customizations for this booking (if any)
            if (!empty($params['variable_customizations'])) {
                $this->storeVariableCustomizations($params['variable_customizations'], $booking->id, $params['session_id'] ?? null);
            }

            // 6) Handle vehicle and driver assignments
            $this->createBookingAssignments($booking, $params);

            // 7) Open approval record + notify
            $approval = new BookingApproval();
            $approval->booking_id = $booking->id;
            $approval->requested_by = Auth::id();
            $approval->status = BookingApproval::STATUS_PENDING;
            $approval->override_reasons = $booking->override_reasons ?? [];
            $approval->justification = $booking->approval_justification ?? null;
            $approval->priority = $this->determineApprovalPriority($params);
            $approval->save();

            if (method_exists($this, 'notifyApprovalRequested')) {
                $this->notifyApprovalRequested($booking, $approval);
            }

            return $booking->load(['customer', 'vehicle', 'driver', 'serviceType', 'vehicleGroup', 'approvals']);
        });
    }

    /**
     * Get Service Package information.
     */
    private function getServicePackageInformation(array $inputs): ?array
    {
        $packageId = $inputs['package_id'] ?? null;

        if (!$packageId) {
            return null;
        }

        $servicePackage = ServicePackage::find($packageId);

        if (!$servicePackage) {
            return null;
        }

        return [
            'id' => $servicePackage->id,
            'name' => $servicePackage->name,
            'code' => $servicePackage->code,
            'service_package' => $servicePackage,
            'max_km_per_day' => $servicePackage->max_km_per_day,
            'max_km_per_package' => $servicePackage->max_km_per_package,
            'price_multiplier' => $servicePackage->price_multiplier,
            'rate_type' => $servicePackage->rate_type,
            'default_duration_hours' => $servicePackage->default_duration_hours,
        ];
    }

    /**
     * Resolve district pricing adjustment based on start location.
     * Falls back to Colombo if no district pricing exists for the selected location.
     */
    private function resolveDistrictPricing(array $params, string $serviceTypeId, ?string $packageId = null, ?string $vehicleGroupId = null): ?array
    {
        // Extract district from pickup/start location
        $districtId = null;

        if (isset($params['pickup_location'])) {
            $location = $params['pickup_location'];

            // Location could be an array with district_id or a JSON-encoded string
            if (is_array($location)) {
                $districtId = $location['district_id'] ?? null;
            } elseif (is_string($location)) {
                $decoded = json_decode($location, true);
                if (is_array($decoded)) {
                    $districtId = $decoded['district_id'] ?? null;
                }
            }
        }

        if ($districtId) {
            $adjustment = DistrictPricingAdjustment::resolveAdjustment(
                $districtId,
                $serviceTypeId,
                $packageId,
                $vehicleGroupId
            );

            if ($adjustment && $adjustment['percentage'] != 0) {
                return [
                    'district_id' => $districtId,
                    'percentage_change' => $adjustment['percentage'],
                    'is_available' => $adjustment['is_available'],
                    'request_quote' => $adjustment['request_quote'],
                    'adjustment_id' => $adjustment['id'],
                ];
            }
        }

        $colomboDistrictId = \DB::table('districts')->where('name', 'Colombo')->value('id');

        if ($colomboDistrictId && $colomboDistrictId !== $districtId) {
            $colomboAdjustment = DistrictPricingAdjustment::resolveAdjustment(
                $colomboDistrictId,
                $serviceTypeId,
                $packageId,
                $vehicleGroupId
            );

            if ($colomboAdjustment && $colomboAdjustment['percentage'] != 0) {
                Log::info("Falling back to Colombo district pricing", [
                    'original_district' => $districtId,
                    'fallback_district' => $colomboDistrictId,
                    'percentage_change' => $colomboAdjustment['percentage']
                ]);

                return [
                    'district_id' => $colomboDistrictId,
                    'percentage_change' => $colomboAdjustment['percentage'],
                    'is_available' => $colomboAdjustment['is_available'],
                    'request_quote' => $colomboAdjustment['request_quote'],
                    'adjustment_id' => $colomboAdjustment['id'],
                    'is_fallback' => true,
                ];
            }
        }

        return null;
    }

    public function confirmBooking(array $params): Booking
    {
        return DB::transaction(function () use ($params) {

            // 1) Calculate pricing
            $pricing = $this->calculatePricing($params);

            // 2) Extract totals
            $totals = $this->extractTotalsFromPricing($pricing);

            // 3) Create confirmed booking
            $booking = new Booking();

            $booking->customer_id = $params['customer_id'] ?? null;
            $booking->service_type_id = $params['service_type'] ?? ($params['service_type_id'] ?? null);

            // For multi-group bookings, use first group as primary or null
            $isMultiGroup = !empty($params['vehicle_groups']) && count($params['vehicle_groups']) > 1;
            if ($isMultiGroup) {
                $booking->vehicle_group_id = null; // Multi-group booking
                $booking->vehicle_id = null;
                $booking->driver_id = null;
            } else {
                $booking->vehicle_group_id = $params['vehicle_group_id'] ?? null;
                $booking->vehicle_id = $params['vehicle_id'] ?? ($params['vehicle_id'] ?? null);
                $booking->driver_id = $params['driver_id'] ?? null;
            }

            $booking->from_date = $params['from_date'] ?? null;
            $booking->to_date = $params['to_date'] ?? null;
            $booking->from_time = $params['from_time'] ?? null;
            $booking->to_time = $params['to_time'] ?? null;
            $booking->pickup_location = $params['pickup_location'] ?? null;
            $booking->dropoff_location = $params['dropoff_location'] ?? null;

            $booking->pricing_snapshot = $totals['pricing_snapshot'];
            $booking->base_amount = $totals['base_amount'];
            $booking->addons_cost = $totals['addons_cost'];
            $booking->discount_amount = $totals['discount_amount'];
            $booking->total_estimated = $totals['total_estimated'];
            $booking->duration_metrics = $totals['duration_metrics'] ?? null;
            $booking->distance_metrics = $totals['distance_metrics'] ?? null;
            $booking->discounts = $params['applied_discounts'] ?? [];

            $booking->status = 'confirmed';
            $booking->confirmed = true;
            $booking->confirmed_at = now();
            $booking->requires_approval = false;
            $booking->approval_status = 'not_required';

            if (method_exists(Booking::class, 'generateConfirmationNumber')) {
                $booking->confirmation_number = Booking::generateConfirmationNumber();
            }

            $booking->workflow_step = 'confirmed';
            $booking->workflow_data = [
                'confirmed_at' => now()->toISOString(),
                'confirmed_by' => Auth::id(),
                'frontend_data' => $params,
                'is_multi_group' => $isMultiGroup,
            ];

            // initialize actuals with estimated
            $booking->total_actual = $booking->total_estimated;
            $booking->save();

            // Handle multi-group booking items creation
            if ($isMultiGroup) {
                $this->createMultiGroupBookingItems($booking, $params, $pricing);
            } else {
                // Handle single group booking (existing logic)
                if (!empty($params['selected_addons'])) {
                    $this->syncBookingAddons($booking, $params['selected_addons']);
                }
                if (!empty($params['variable_customizations'])) {
                    $this->storeVariableCustomizations($params['variable_customizations'], $booking->id, $params['session_id'] ?? null);
                }

                // Handle vehicle and driver assignments for confirmed booking
                $this->createBookingAssignments($booking, $params, 'active');
            }

            if (method_exists($this, 'sendBookingConfirmation')) {
                $this->sendBookingConfirmation($booking);
            }

            return $booking->load(['customer', 'vehicle', 'driver', 'serviceType', 'vehicleGroup', 'bookingItems']);
        });
    }

    /**
     * Create booking items for multi-group bookings
     */
    private function createMultiGroupBookingItems(Booking $booking, array $params, array $pricing): void
    {
        if (empty($params['vehicle_groups']) || !isset($pricing['groups'])) {
            return;
        }

        $vehicleGroups = $params['vehicle_groups'];
        $vehicles = $params['vehicles'] ?? [];
        $drivers = $params['drivers'] ?? [];
        $vehicleDriverAssignments = $params['vehicle_driver_assignments'] ?? [];

        foreach ($pricing['groups'] as $groupPricing) {
            $groupId = $groupPricing['group_id'];
            $quantity = $groupPricing['quantity'];

            // Find group selection data
            $groupSelection = collect($vehicleGroups)->firstWhere('id', $groupId);
            if (!$groupSelection) {
                continue;
            }

            // Get group-specific vehicles and drivers
            $groupVehicles = array_filter($vehicles, function ($vehicle) use ($groupId) {
                return $vehicle['group_id'] === $groupId;
            });

            $groupDrivers = array_filter($drivers, function ($driver) use ($groupId) {
                return !isset($driver['group_id']) || $driver['group_id'] === $groupId;
            });

            // Create booking items for each quantity of this group
            for ($i = 0; $i < $quantity; $i++) {
                // Assign specific vehicle if available
                $availableVehicles = array_values($groupVehicles);
                $assignedVehicleId = null;
                if (!empty($availableVehicles) && isset($availableVehicles[$i])) {
                    $assignedVehicleId = $availableVehicles[$i]['id'];
                }

                // Assign driver based on vehicle-driver assignments
                $assignedDriverId = null;
                if ($assignedVehicleId) {
                    foreach ($vehicleDriverAssignments as $assignment) {
                        if ($assignment['vehicle_id'] === $assignedVehicleId) {
                            $assignedDriver = collect($groupDrivers)->firstWhere('id', $assignment['driver_id']);
                            if ($assignedDriver) {
                                $assignedDriverId = $assignedDriver['id'];
                            }
                            break;
                        }
                    }
                }

                $bookingItem = BookingItem::create([
                    'booking_id' => $booking->id,
                    'vehicle_group_id' => $groupId,
                    'vehicle_id' => $assignedVehicleId,
                    'driver_id' => $assignedDriverId,
                    'quantity' => 1, // Each item represents 1 unit
                    'unit_price' => $groupPricing['base_pricing']['total_amount'] ?? 0,
                    'total_price' => $groupPricing['base_pricing']['total_amount'] ?? 0,
                    'from_date' => $booking->from_date,
                    'to_date' => $booking->to_date,
                    'duration_days' => $groupPricing['duration']['days'] ?? 0,
                    'duration_hours' => $groupPricing['duration']['hours'] ?? 0,
                    'currency' => $groupPricing['currency'] ?? 'LKR',
                    'exchange_rate' => '1.000000',
                    'status' => 'confirmed',
                    'requires_approval' => $groupPricing['requires_approval'] ?? false,
                    'approved_at' => ($groupPricing['requires_approval'] ?? false) ? null : now(),
                    'approved_by' => ($groupPricing['requires_approval'] ?? false) ? null : Auth::id(),
                    'item_type' => 'vehicle_group'
                ]);

                // Update additional JSON fields after creation
                $bookingItem->update([
                    'pricing_breakdown' => [
                        'base_pricing' => $groupPricing['base_pricing'] ?? [],
                        'addons_pricing' => $groupPricing['addons_pricing'] ?? [],
                        'summary' => $groupPricing['summary'] ?? [],
                        'calculations' => $groupPricing['calculations'] ?? []
                    ],
                    'addons' => $groupPricing['addons_pricing']['breakdown'] ?? [],
                    'customizations' => $groupPricing['applied_customizations'] ?? [],
                    'discounts' => [], // Can be implemented later for group-specific discounts
                    'metadata' => [
                        'group_info' => $groupPricing['group_info'] ?? [],
                        'vehicle_details' => $availableVehicles[$i] ?? null,
                        'driver_details' => $assignedDriverId ? collect($groupDrivers)->firstWhere('id', $assignedDriverId) : null,
                        'assignment_index' => $i
                    ]
                ]);

                // Create assignments for this booking item if needed
                if ($bookingItem->vehicle_id || $bookingItem->driver_id) {
                    $this->createBookingItemAssignments($bookingItem, [
                        'vehicle_id' => $bookingItem->vehicle_id,
                        'driver_id' => $bookingItem->driver_id,
                        'from_date' => $booking->from_date,
                        'to_date' => $booking->to_date,
                        'from_time' => $booking->from_time,
                        'to_time' => $booking->to_time
                    ]);
                }
            }
        }
    }

    /**
     * Create assignments for individual booking items
     */
    private function createBookingItemAssignments(BookingItem $bookingItem, array $assignmentData): void
    {
        // This method can be expanded to create specific vehicle and driver assignments
        // for each booking item in a multi-group booking scenario

        // For now, we'll use the existing assignment logic but scoped to the booking item
        if (!empty($assignmentData['vehicle_id']) || !empty($assignmentData['driver_id'])) {
            Log::info('Creating booking item assignments', [
                'booking_item_id' => $bookingItem->id,
                'booking_id' => $bookingItem->booking_id,
                'vehicle_id' => $assignmentData['vehicle_id'] ?? null,
                'driver_id' => $assignmentData['driver_id'] ?? null
            ]);

            // Future implementation: Create specific assignment records for booking items
            // This might involve a new table like booking_item_assignments or extending 
            // existing assignment tables to reference booking_item_id
        }
    }

    public function updateBooking(string $bookingId, array $params, array $changeAnalytics): Booking
    {
        return DB::transaction(function () use ($bookingId, $params) {

            $booking = Booking::with(['bookingAddons', 'variableCustomizations'])->findOrFail($bookingId);
            $original = $booking->replicate();

            // 1) Include booking_id in params for edit-mode customizations (your calculatePricing uses it)
            $params['booking_id'] = $bookingId;

            // 2) Recalculate pricing using the same flat payload
            $pricing = $this->calculatePricing($params);

            // 3) Extract totals + snapshot
            $totals = $this->extractTotalsFromPricing($pricing);

            // 4) Apply incoming fields directly (no normalization)
            $booking->customer_id = $params['customer_id'] ?? $booking->customer_id;
            $booking->service_type_id = $params['service_type'] ?? ($params['service_type_id'] ?? $booking->service_type_id);
            $booking->vehicle_group_id = $params['vehicle_group_id'] ?? $booking->vehicle_group_id;
            $booking->vehicle_id = $params['vehicle_id'] ?? ($params['vehicle_id'] ?? $booking->vehicle_id);
            $booking->driver_id = $params['driver_id'] ?? $booking->driver_id;

            $booking->from_date = $params['from_date'] ?? $booking->from_date;
            $booking->to_date = $params['to_date'] ?? $booking->to_date;
            $booking->from_time = $params['from_time'] ?? null;
            $booking->to_time = $params['to_time'] ?? null;
            $booking->pickup_location = $params['pickup_location'] ?? $booking->pickup_location;
            $booking->dropoff_location = $params['dropoff_location'] ?? $booking->dropoff_location;

            $booking->pricing_snapshot = $totals['pricing_snapshot'];
            $booking->base_amount = $totals['base_amount'];
            $booking->addons_cost = $totals['addons_cost'];
            $booking->discount_amount = $totals['discount_amount'];
            $booking->total_estimated = $totals['total_estimated'];
            $booking->duration_metrics = $totals['duration_metrics'] ?? $booking->duration_metrics;
            $booking->distance_metrics = $totals['distance_metrics'] ?? $booking->distance_metrics;
            $booking->discounts = $params['applied_discounts'] ?? ($booking->discounts ?? []);

            // Optional user-provided meta
            if (array_key_exists('override_reasons', $params)) {
                $booking->override_reasons = $params['override_reasons'] ?? [];
                $booking->has_overrides = !empty($booking->override_reasons);
            }
            if (array_key_exists('review_notes', $params)) {
                $booking->review_notes = $params['review_notes'];
            }

            $booking->save();

            // 5) Re-sync addons only if provided
            if (array_key_exists('selected_addons', $params)) {
                $booking->bookingAddons()->delete();
                if (!empty($params['selected_addons'])) {
                    $this->syncBookingAddons($booking, $params['selected_addons']);
                }
            }

            // 6) Replace variable customizations only if provided
            if (array_key_exists('variable_customizations', $params)) {
                $booking->variableCustomizations()->delete();
                if (!empty($params['variable_customizations'])) {
                    $this->storeVariableCustomizations($params['variable_customizations'], $booking->id, $params['session_id'] ?? null);
                }
            }

            // 7) Handle assignment updates when vehicle/driver or dates change
            $assignmentChanged = (
                $original->vehicle_id !== $booking->vehicle_id ||
                $original->driver_id !== $booking->driver_id ||
                optional($original->from_date)->toDateString() !== optional($booking->from_date)->toDateString() ||
                optional($original->to_date)->toDateString() !== optional($booking->to_date)->toDateString()
            );

            if ($assignmentChanged) {
                // Update assignments when vehicle/driver or dates change
                $this->updateBookingAssignments($booking, $params, $original);
            }

            // 8) Re-approval logic:
            //    Trigger when pricing says so OR meaningful totals changed
            $requiresApprovalByPricing = (bool) ($pricing['requires_approval'] ?? false);
            $pricingChanged = (
                (float) $original->base_amount !== (float) $booking->base_amount ||
                (float) $original->addons_cost !== (float) $booking->addons_cost ||
                (float) $original->discount_amount !== (float) $booking->discount_amount ||
                (float) $original->total_estimated !== (float) $booking->total_estimated
            );
            $detailsChanged = (
                optional($original->from_date)->toDateString() !== optional($booking->from_date)->toDateString() ||
                optional($original->to_date)->toDateString() !== optional($booking->to_date)->toDateString() ||
                $original->vehicle_group_id !== $booking->vehicle_group_id ||
                $original->vehicle_id !== $booking->vehicle_id ||
                $original->driver_id !== $booking->driver_id
            );

            if ($booking->status !== 'pending_approval' && ($requiresApprovalByPricing || $pricingChanged || $detailsChanged)) {
                $booking->status = 'pending_approval';
                $booking->requires_approval = true;
                $booking->approval_status = 'pending';
                $booking->approval_requested_at = now();
                $booking->approval_requested_by = Auth::id();
                $booking->save();

                $approval = new BookingApproval();
                $approval->booking_id = $booking->id;
                $approval->requested_by = Auth::id();
                $approval->status = BookingApproval::STATUS_PENDING;
                $approval->override_reasons = $booking->override_reasons ?? [];
                $approval->justification = $params['approval_reason'] ?? null;
                $approval->priority = $this->determineApprovalPriority($params);
                $approval->save();

                if (method_exists($this, 'notifyApprovalRequested')) {
                    $this->notifyApprovalRequested($booking, $approval);
                }
            }

            // 8) Optional: allow explicit status override if client sent it AND no approval needed
            if (isset($params['status']) && $params['status'] === 'confirmed' && !$booking->requires_approval) {
                $booking->status = 'confirmed';
                $booking->confirmed = true;
                $booking->confirmed_at = $booking->confirmed_at ?? now();
                if (empty($booking->confirmation_number) && method_exists(Booking::class, 'generateConfirmationNumber')) {
                    $booking->confirmation_number = Booking::generateConfirmationNumber();
                }
                $booking->save();

                if (method_exists($this, 'sendBookingConfirmation')) {
                    $this->sendBookingConfirmation($booking);
                }
            }

            return $booking->fresh(['customer', 'vehicle', 'driver', 'serviceType', 'vehicleGroup', 'approvals']);
        });
    }

    private function generateConflictRecommendations(array $conflicts, string $vehicleId, array $params): array
    {
        $recommendations = [];

        if (!empty($conflicts)) {
            // Find alternative vehicles in the same group
            $vehicle = Vehicle::findOrFail($vehicleId);
            $alternatives = $this->getAvailableVehiclesInGroup([
                'vehicle_group_id' => $vehicle->vehicle_group_id,
                'service_type' => $params['service_type'],
                'from_date' => $params['from_date'],
                'to_date' => $params['to_date'],
                'from_time' => $params['from_time'],
                'to_time' => $params['to_time'],
            ]);

            if (!empty($alternatives)) {
                $recommendations[] = [
                    'type' => 'alternative_vehicles',
                    'message' => 'Consider these alternative vehicles in the same group',
                    'alternatives' => array_slice($alternatives, 0, 3),
                ];
            }
        }

        return $recommendations;
    }

    private function generateDriverConflictRecommendations(array $conflicts, string $driverId, array $params): array
    {
        $recommendations = [];

        if (!empty($conflicts)) {
            // Find alternative drivers
            $alternatives = $this->getAvailableDrivers($params);
            $alternatives = array_filter($alternatives, function ($driver) use ($driverId) {
                return $driver['id'] !== $driverId;
            });

            if (!empty($alternatives)) {
                $recommendations[] = [
                    'type' => 'alternative_drivers',
                    'message' => 'Consider these alternative drivers',
                    'alternatives' => array_slice(array_values($alternatives), 0, 3),
                ];
            }
        }

        return $recommendations;
    }

    private function checkDriverAvailabilitySchedule(array $schedule, Carbon $fromDate, Carbon $toDate, string $fromTime, string $toTime): array
    {
        // Implementation for checking driver availability schedule
        // This would check against the driver's preferred working hours, days off, etc.
        return [];
    }


    private function requiresApproval(array $pricingOverrides, array $customerDiscounts, array $dynamicAdjustments): bool
    {
        // Logic to determine if booking requires approval
        return $pricingOverrides['has_overrides'] ||
            $customerDiscounts['total_discount'] > 100 ||
            abs($dynamicAdjustments['adjustment_amount']) > 50;
    }

    /**
     * Additional methods to implement all frontend endpoints...
     */

    public function validateSelfDrivenEligibility(string $customerId): array
    {
        $customer = Customer::findOrFail($customerId);

        // Check customer eligibility for self-driven service
        $requirements = [
            'valid_license' => !empty($customer->driving_license_number),
            'license_verified' => $customer->license_verified ?? false,
            'credit_check_passed' => $customer->credit_score >= 650,
            'age_requirement' => $customer->age >= 21,
            'experience_years' => $customer->driving_experience >= 2,
        ];

        $isEligible = array_reduce($requirements, function ($carry, $item) {
            return $carry && $item;
        }, true);

        return [
            'is_eligible' => $isEligible,
            'requirements' => $requirements,
            'missing_requirements' => array_keys(array_filter($requirements, function ($req) {
                return !$req;
            })),
        ];
    }

    public function getVehicleSelfDrivenSuitability(string $vehicleId): array
    {
        $vehicle = Vehicle::findOrFail($vehicleId);

        return [
            'is_suitable' => $vehicle->supports_self_driven ?? false,
            'features' => $vehicle->self_driven_features ?? [],
            'insurance_coverage' => $vehicle->self_driven_insurance ?? [],
            'restrictions' => $vehicle->self_driven_restrictions ?? [],
        ];
    }


    public function getApprovalStatus(string $bookingId): array
    {
        $booking = Booking::with('approvals.approver')->findOrFail($bookingId);

        return [
            'booking_id' => $booking->id,
            'status' => $booking->status,
            'approvals' => $booking->approvals->map(function ($approval) {
                return [
                    'id' => $approval->id,
                    'status' => $approval->status,
                    'approver_name' => $approval->approver->name ?? null,
                    'approved_at' => $approval->approved_at,
                    'comments' => $approval->comments,
                ];
            }),
        ];
    }

    public function requestManagerApproval(string $bookingId, array $approvalData): array
    {
        $booking = Booking::findOrFail($bookingId);

        $approval = new BookingApproval();
        $approval->booking_id = $bookingId;
        $approval->requested_by = Auth::id();
        $approval->manager_id = $approvalData['manager_id'] ?? null;
        $approval->priority = $approvalData['priority'];
        $approval->override_reasons = $approvalData['override_reasons'];
        $approval->justification = $approvalData['justification'];
        $approval->status = 'pending';
        $approval->save();

        return [
            'approval_id' => $approval->id,
            'status' => 'requested',
            'message' => 'Manager approval requested successfully',
        ];
    }


    /**
     * Validate if location array has valid coordinates
     */
    private function isValidLocationArray(array $location): bool
    {
        $lat = $this->extractLatitude($location);
        $lng = $this->extractLongitude($location);

        return $lat !== null && $lng !== null &&
            $lat >= -90 && $lat <= 90 &&
            $lng >= -180 && $lng <= 180;
    }

    /**
     * Extract latitude from location array with multiple key formats
     */
    private function extractLatitude(array $location): ?float
    {
        if (isset($location['lat'])) {
            return (float) $location['lat'];
        }
        if (isset($location['latitude'])) {
            return (float) $location['latitude'];
        }
        if (isset($location['y'])) {
            return (float) $location['y'];
        }
        return null;
    }

    /**
     * Extract longitude from location array with multiple key formats
     */
    private function extractLongitude(array $location): ?float
    {
        if (isset($location['lng'])) {
            return (float) $location['lng'];
        }
        if (isset($location['longitude'])) {
            return (float) $location['longitude'];
        }
        if (isset($location['lon'])) {
            return (float) $location['lon'];
        }
        if (isset($location['x'])) {
            return (float) $location['x'];
        }
        return null;
    }

    public function getDynamicPricingAdjustments(array $params): array
    {
        // Mock implementation for dynamic pricing
        return [
            'adjustment_type' => 'demand_based',
            'adjustment_percentage' => 0,
            'adjustment_amount' => 0,
            'reason' => 'Normal demand period',
        ];
    }

    /**
     * Calculate duration in days and hours format
     */
    public function calculateDurationInDaysAndHours(Carbon $fromDate, Carbon $toDate): array
    {
        // Calculate calendar days (not 24-hour blocks)
        // This counts the number of calendar days between two dates
        // Example: 10 PM today to 8 PM tomorrow = 2 calendar days
        $calendarDays = $fromDate->diffInDays($toDate) + 1; // +1 because we count both start and end day

        // For total hours calculation (not used for day pricing, but kept for reference)
        $totalHours = $fromDate->diffInHours($toDate);

        return [
            'total_hours' => $totalHours,
            'days' => $calendarDays,
            'hours' => 0,  // Hours not considered when calculating pricing for multi-day rentals with calendar days
            'formatted' => $this->formatDuration($calendarDays, 0),
            'breakdown' => [
                'days_text' => $calendarDays > 0 ? "{$calendarDays} " . ($calendarDays === 1 ? 'day' : 'days') : '',
                'hours_text' => '',
            ]
        ];
    }

    /**
     * Format duration as human-readable string
     */
    private function formatDuration(int $days, int $hours): string
    {
        $parts = [];

        if ($days > 0) {
            $parts[] = "{$days} " . ($days === 1 ? 'day' : 'days');
        }

        if ($hours > 0) {
            $parts[] = "{$hours} " . ($hours === 1 ? 'hour' : 'hours');
        }

        if (empty($parts)) {
            return '0 hours';
        }

        return implode(' ', $parts);
    }

    /**
     * Parse duration string to hours (e.g., "10 days 5 hours" -> 245 hours)
     */
    public function parseDurationToHours(string $duration): int
    {
        $totalHours = 0;

        // Extract days
        if (preg_match('/(\d+)\s*days?/i', $duration, $matches)) {
            $totalHours += intval($matches[1]) * 24;
        }

        // Extract hours
        if (preg_match('/(\d+)\s*hours?/i', $duration, $matches)) {
            $totalHours += intval($matches[1]);
        }

        return $totalHours;
    }

    /**
     * Convert hours to days and hours format
     */
    public function convertHoursToDaysAndHours(int $totalHours): array
    {
        $days = intval($totalHours / 24);
        $hours = $totalHours % 24;

        return [
            'total_hours' => $totalHours,
            'days' => $days,
            'hours' => $hours,
            'formatted' => $this->formatDuration($days, $hours)
        ];
    }

    public function validateBookingRules(array $bookingData): array
    {
        $violations = [];

        // Implement business rule validation
        // Example rules: minimum advance booking time, maximum booking duration, etc.

        return [
            'is_valid' => empty($violations),
            'violations' => $violations,
        ];
    }

    public function getAlternativeSuggestions(array $params): array
    {
        // Implementation for alternative suggestions
        return [
            'suggestions' => [],
            'message' => 'No alternatives found',
        ];
    }

    public function processAddonDependencies(array $selectedAddons): array
    {
        // Implementation for add-on dependencies processing
        return [
            'dependencies_met' => true,
            'conflicts' => [],
            'recommendations' => [],
        ];
    }

    public function generateBookingConfirmation(string $bookingId, string $format): array
    {
        $booking = Booking::with(['customer', 'vehicle', 'driver'])->findOrFail($bookingId);

        // Implementation for generating booking confirmation
        return [
            'confirmation_number' => $booking->confirmation_number,
            'format' => $format,
            'download_url' => null,
            'message' => 'Confirmation generated successfully',
        ];
    }

    /**
     * Search for specific vehicles by name, license plate, or ID
     */
    public function searchSpecificVehicles(array $params): array
    {
        $searchTerm = isset($params['search_term']) ? $params['search_term'] : '';
        $fromDate = Carbon::parse($params['from_date']);
        $toDate = Carbon::parse($params['to_date']);
        $fromTime = $params['from_time'];
        $toTime = $params['to_time'];
        $includeUnavailable = $params['include_unavailable'] ?? false;
        $vehicleGroupId = $params['vehicle_group_id'] ?? null;

        $query = Vehicle::when($vehicleGroupId, fn($q) => $q->where('vehicle_group_id', $vehicleGroupId))->with([
            'vehicleGroup',
            'bookingItems' => function ($query) use ($fromDate, $toDate) {
                $query->whereBetween('from_date', [$fromDate, $toDate])
                    ->orWhereBetween('to_date', [$fromDate, $toDate])
                    ->whereIn('status', ['active', 'pending_approval']);
            }
        ])
            ->where(function ($q) use ($searchTerm) {
                $q->where('title', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('license_plate', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('registration_no', 'LIKE', "%{$searchTerm}%");
                if (Uuid::isValid($searchTerm)) {
                    $q->orWhere('id', $searchTerm);
                }
            });

        if (!$includeUnavailable) {
            $query->where('availability_status', '!=', 'maintenance');
        }

        $vehicles = $query->get()->map(function ($vehicle) use ($fromDate, $toDate, $fromTime, $toTime) {
            $conflicts = $this->checkVehicleTimeConflicts($vehicle, $fromDate, $toDate, $fromTime, $toTime);

            return [
                'id' => $vehicle->id,
                'name' => $vehicle->title,
                'license_plate' => $vehicle->license_plate,
                'vehicle_group' => $vehicle->vehicleGroup ? [
                    'id' => $vehicle->vehicleGroup->id,
                    'name' => $vehicle->vehicleGroup->name,
                ] : null,
                'availability_status' => $vehicle->availability_status,
                'availability_percentage' => $this->calculateAvailabilityPercentage($conflicts, $fromDate, $toDate),
                'conflicts' => $conflicts,
                'requires_approval' => !empty($conflicts) || $vehicle->availability_status === 'maintenance',
                'concurrent_bookings_allowed' => $vehicle->concurrent_bookings_allowed ?? false,
                'is_self_driven_compatible' => $vehicle->self_driven_compatible ?? false,
            ];
        });

        return $vehicles->toArray();
    }

    /**
     * Search for specific drivers by name, license, or ID
     */
    public function searchSpecificDrivers(array $params): array
    {
        $searchTerm = isset($params['search_term']) ? $params['search_term'] : '';
        $fromDate = Carbon::parse($params['from_date']);
        $toDate = Carbon::parse($params['to_date']);
        $fromTime = $params['from_time'];
        $toTime = $params['to_time'];
        $includeUnavailable = $params['include_unavailable'] ?? false;

        $query = Driver::with([
            'driverAssignments' => function ($query) use ($fromDate, $toDate) {
                $query->whereBetween('from_date', [$fromDate, $toDate])
                    ->orWhereBetween('to_date', [$fromDate, $toDate])
                    ->whereIn('status', ['active', 'pending_approval']);
            },
            'user' => function ($query) use ($searchTerm) {
                $query->Where('first_name', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('last_name', 'LIKE', "%{$searchTerm}%");
            }
        ])
            ->where(function ($q) use ($searchTerm) {
                $q->Where('license_no', 'LIKE', "%{$searchTerm}%");
                if (Uuid::isValid($searchTerm)) {
                    $q->orWhere('id', $searchTerm);
                }
            });

        if (!$includeUnavailable) {
            $query->where('availability_status', '!=', 'off_duty');
        }

        $drivers = $query->get()->map(function ($driver) use ($fromDate, $toDate, $fromTime, $toTime) {
            $conflicts = $this->checkDriverTimeConflicts($driver, $fromDate, $toDate, $fromTime, $toTime);
            $scheduleConflicts = $this->checkDriverScheduleConflicts($driver, $fromDate, $toDate, $fromTime, $toTime);

            return [
                'id' => $driver->id,
                'name' => $driver->name ?? "{$driver->user?->first_name} {$driver->user?->last_name}",
                'license_type' => $driver->license_type,
                'license_number' => $driver->license_number,
                'experience_years' => $driver->experience_years,
                'availability_status' => $driver->availability_status,
                'availability_percentage' => $this->calculateAvailabilityPercentage(array_merge($conflicts, $scheduleConflicts), $fromDate, $toDate),
                'conflicts' => $conflicts,
                'schedule_conflicts' => $scheduleConflicts,
                'requires_approval' => !empty($conflicts) || !empty($scheduleConflicts) || $driver->availability_status === 'off_duty',
                'current_assignment' => $driver->current_booking_id ? [
                    'booking_id' => $driver->current_booking_id,
                    'status' => $driver->availability_status,
                ] : null,
                'working_schedule' => $driver->working_schedule,
                'hourly_rate' => $driver->hourly_rate,
                'overtime_rate' => $driver->overtime_rate,
                'can_override' => false,
            ];
        });

        return $drivers->toArray();
    }


    /**
     * Check vehicle time conflicts
     */
    private function checkVehicleTimeConflicts($vehicle, $fromDate, $toDate, $fromTime, $toTime): array
    {
        $conflicts = [];
        $requestStart = Carbon::parse($fromDate->format('Y-m-d'));
        $requestEnd = Carbon::parse($toDate->format('Y-m-d'));

        foreach ($vehicle->bookings as $booking) {
            $bookingStart = Carbon::parse($booking->from_date);
            $bookingEnd = Carbon::parse($booking->to_date);

            $bookingStartTime = Carbon::parse($booking->from_time);
            $bookingEndTime = Carbon::parse($booking->to_time);

            // Check for overlap
            if ($requestStart < $bookingEnd && $requestEnd > $bookingStart) {
                $conflicts[] = [
                    'booking_id' => $booking->id,
                    'customer_name' => $booking->customer?->user?->first_name . ' ' . $booking->customer?->user?->last_name ?? null,
                    'driver_name' => $booking->driver?->user?->first_name . ' ' . $booking->driver?->user?->last_name ?? null,
                    'from' => $bookingStart->toISOString(),
                    'to' => $bookingEnd->toISOString(),
                    'from_time' => $bookingStartTime->toISOString(),
                    'to_time' => $bookingEndTime->toISOString(),
                    'type' => $booking->booking_type,
                    'status' => $booking->status,
                    'service_type' => $booking->serviceType?->name,
                    'overlap_type' => $this->determineOverlapType($requestStart, $requestEnd, $bookingStart, $bookingEnd),
                    'can_override' => $vehicle->concurrent_bookings_allowed ?? false,
                ];
            }
        }

        return $conflicts;
    }

    /**
     * Check driver time conflicts
     */
    private function checkDriverTimeConflicts($driver, $fromDate, $toDate, $fromTime, $toTime): array
    {
        $conflicts = [];
        $requestStart = Carbon::parse($fromDate->format('Y-m-d'));
        $requestEnd = Carbon::parse($toDate->format('Y-m-d'));

        foreach ($driver->bookings as $booking) {
            $bookingStart = Carbon::parse($booking->from_date);
            $bookingEnd = Carbon::parse($booking->to_date);

            $bookingStartTime = Carbon::parse($booking->from_time);
            $bookingEndTime = Carbon::parse($booking->to_time);

            // Check for overlap
            if ($requestStart < $bookingEnd && $requestEnd > $bookingStart) {
                $conflicts[] = [
                    'booking_id' => $booking->id,
                    'customer_name' => $booking->customer?->user?->first_name . ' ' . $booking->customer?->user?->last_name ?? null,
                    'vehicle_name' => $booking->vehicle?->title ?? null,
                    'from' => $bookingStart->toISOString(),
                    'to' => $bookingEnd->toISOString(),
                    'from_time' => $bookingStartTime->toISOString(),
                    'to_time' => $bookingEndTime->toISOString(),
                    'type' => $booking->booking_type,
                    'status' => $booking->status,
                    'service_type' => $booking->serviceType?->name,
                    'overlap_type' => $this->determineOverlapType($requestStart, $requestEnd, $bookingStart, $bookingEnd),
                    'can_override' => false, // Drivers typically cannot have concurrent bookings
                ];
            }
        }

        return $conflicts;
    }

    /**
     * Check driver schedule conflicts
     */
    private function checkDriverScheduleConflicts($driver, $fromDate, $toDate, $fromTime, $toTime): array
    {
        $conflicts = [];

        if (!$driver->working_schedule) {
            return $conflicts;
        }

        $currentDate = $fromDate->copy();
        $requestStart = Carbon::parse($fromDate->format('Y-m-d') . ' ' . $fromTime);
        $requestEnd = Carbon::parse($toDate->format('Y-m-d') . ' ' . $toTime);

        while ($currentDate->lte($toDate)) {
            $dayOfWeek = strtolower($currentDate->format('l'));
            $daySchedule = $driver->working_schedule[$dayOfWeek] ?? null;

            if (!$daySchedule || !($daySchedule['available'] ?? false)) {
                // Driver not available on this day
                $dayStart = max($requestStart, $currentDate->copy()->startOfDay());
                $dayEnd = min($requestEnd, $currentDate->copy()->endOfDay());

                if ($dayStart < $dayEnd) {
                    $conflicts[] = [
                        'type' => 'schedule_unavailable',
                        'reason' => "Driver not available on {$currentDate->format('l')}",
                        'from' => $dayStart->toISOString(),
                        'to' => $dayEnd->toISOString(),
                        'can_override' => true,
                    ];
                }
            } else {
                // Check working hours
                $workStart = $currentDate->copy()->setTimeFromTimeString($daySchedule['start_time']);
                $workEnd = $currentDate->copy()->setTimeFromTimeString($daySchedule['end_time']);

                $requestDayStart = max($requestStart, $currentDate->copy()->startOfDay());
                $requestDayEnd = min($requestEnd, $currentDate->copy()->endOfDay());

                // Before work hours conflict
                if ($requestDayStart < $workStart && $requestDayEnd > $requestDayStart) {
                    $conflicts[] = [
                        'type' => 'outside_work_hours',
                        'reason' => "Before working hours on {$currentDate->format('l')}",
                        'from' => $requestDayStart->toISOString(),
                        'to' => min($workStart, $requestDayEnd)->toISOString(),
                        'can_override' => true,
                    ];
                }

                // After work hours conflict
                if ($requestDayEnd > $workEnd && $requestDayStart < $requestDayEnd) {
                    $conflicts[] = [
                        'type' => 'outside_work_hours',
                        'reason' => "After working hours on {$currentDate->format('l')}",
                        'from' => max($workEnd, $requestDayStart)->toISOString(),
                        'to' => $requestDayEnd->toISOString(),
                        'can_override' => true,
                    ];
                }
            }

            $currentDate->addDay();
        }

        return $conflicts;
    }

    /**
     * Calculate availability percentage
     */
    private function calculateAvailabilityPercentage(array $conflicts, $fromDate, $toDate): float
    {
        $totalMinutes = $fromDate->diffInMinutes($toDate);
        $conflictMinutes = 0;

        foreach ($conflicts as $conflict) {
            $conflictStart = Carbon::parse($conflict['from']);
            $conflictEnd = Carbon::parse($conflict['to']);
            $conflictMinutes += $conflictStart->diffInMinutes($conflictEnd);
        }

        return max(0, ($totalMinutes - $conflictMinutes) / $totalMinutes * 100);
    }

    /**
     * Determine overlap type
     */
    private function determineOverlapType($requestStart, $requestEnd, $assignmentStart, $assignmentEnd): string
    {
        if ($requestStart >= $assignmentStart && $requestEnd <= $assignmentEnd) {
            return 'complete_overlap';
        } elseif ($requestStart < $assignmentStart && $requestEnd > $assignmentEnd) {
            return 'contains_assignment';
        } elseif ($requestStart < $assignmentEnd && $requestEnd > $assignmentStart) {
            return 'partial_overlap';
        }

        return 'no_overlap';
    }

    /**
     * Determine vehicle availability status
     */
    private function determineVehicleAvailabilityStatus($vehicle, $conflicts, $fromDate, $toDate): string
    {
        if (empty($conflicts)) {
            return 'available';
        }

        // Simplified logic - in reality you'd check conflict types
        return 'booked';
    }

    /**
     * Get vehicle assignment details
     */
    private function getVehicleAssignmentDetails($vehicle, $conflicts): ?string
    {
        if (empty($conflicts)) {
            return null;
        }
        return 'Vehicle has scheduling conflicts';
    }

    /**
     * Check if vehicle is self-driven compatible
     */
    private function isVehicleSelfDrivenCompatible($vehicle, $serviceType): bool
    {
        return $serviceType !== 'self_driven' || ($vehicle->is_self_driven_eligible ?? true);
    }

    /**
     * Check if vehicle override is allowed
     */
    private function isVehicleOverrideAllowed($vehicle, $conflicts): bool
    {
        return !empty($conflicts);
    }

    /**
     * Check if concurrent assignment is possible
     */
    private function isConcurrentAssignmentPossible($vehicle, $conflicts): bool
    {
        return !empty($conflicts);
    }


    /**
     * Get current vehicle assignment
     */
    private function getCurrentVehicleAssignment($vehicle, $fromDate, $toDate): ?array
    {
        return null;
    }

    /**
     * Calculate vehicle availability percentage
     */
    private function calculateVehicleAvailabilityPercentage($vehicle, $fromDate, $toDate): int
    {
        return 100;
    }

    /**
     * Calculate dynamic pricing using calculation definitions
     * This is the core method that integrates with the dynamic calculation system
     */
    public function calculateDynamicPricing(array $params): array
    {
        try {
            $serviceTypeId = $params['service_type_id'];
            $mode = $params['mode'] ?? 'full_calculation';
            $appliedCustomizations = $params['applied_customizations'] ?? [];
            if (!$serviceTypeId) {
                Log::warning("No service type ID provided for dynamic pricing calculation");
                return $this->getDefaultPricingStructure();
            }

            // Get active calculation definition for this service type
            $calculationDefinition = VehiclePricingCalculationDefinition::where('service_type_id', $serviceTypeId)
                ->where('status', 'active')
                ->orderBy('created_at', 'desc')
                ->first();

            if (!$calculationDefinition) {
                return $this->calculateFallbackPricing($params);
            }

            // Prepare calculation inputs
            $calculationInputs = $this->prepareCalculationInputs($params);

            // Resolve Service Package information
            $servicePackageInfo = $this->getServicePackageInformation($calculationInputs);

            // Resolve district pricing adjustment
            $districtInfo = null;
            // $districtInfo = $this->resolveDistrictPricing($params, $serviceTypeId, $params['package_id'] ?? null, $params['vehicle_group_id'] ?? null);

            // Execute the calculation
            $calculationResult = $calculationDefinition->calculatePrice($calculationInputs, $appliedCustomizations, $servicePackageInfo, $districtInfo);

            Log::info("Dynamic pricing calculation executed", [
                'params' => $params,
                'definition_id' => $calculationDefinition->id,
                'inputs' => $calculationInputs,
                'service_package_info' => $servicePackageInfo,
                'result' => $calculationResult

            ]);
            // Transform result to standard pricing structure
            return $this->transformCalculationResult($calculationResult, $params, $mode);

        } catch (\Exception $e) {
            Log::error("Dynamic pricing calculation failed: " . $e->getMessage(), [
                'params' => $params,
                'trace' => $e->getTraceAsString()
            ]);

            return $this->calculateFallbackPricing($params);
        }
    }


    /**
     * Prepare inputs for calculation definition execution
     * Enhanced to support company-specific distance calculations and airport pricing
     */
    private function prepareCalculationInputs(array $params): array
    {
        $inputs = [
            'vehicle_group_id' => $params['vehicle_group_id'],
            'duration_hours' => $params['duration_hours'] ?? 24,
            'duration_days' => $params['duration_days'] ?? 1,
            'number_of_days' => $params['duration_days'] ?? 1,
        ];

        // Detect airport locations (for applying airport pricing rules)
        $pickupIsAirport = false;
        $dropoffIsAirport = false;


        if (isset($params['district_id'])) {
            $inputs['district_id'] = $params['district_id'];
        }

        if (isset($params['package_id'])) {
            $inputs['package_id'] = $params['package_id'];
        }

        if (isset($params['package_included_km'])) {
            $inputs['package_included_km'] = $params['package_included_km'];
        }

        if (isset($params['pickup_location']) && isset($params['dropoff_location'])) {
            $pickupIsAirport = $this->isAirportLocation($params['pickup_location']);
            $dropoffIsAirport = $this->isAirportLocation($params['dropoff_location']);

            $inputs['pickup_is_airport'] = $pickupIsAirport;
            $inputs['dropoff_is_airport'] = $dropoffIsAirport;

            $serviceType = $params['service_type'] ?? null;

            $distanceCalculations = $this->calculateCompanyDistances(
                $params['pickup_location'],
                $params['dropoff_location'],
                $serviceType,
                $params['vehicle_id'] ?? null,
            );
            $inputs = array_merge($inputs, $distanceCalculations);
        }

        // Add customer-specific inputs
        if (isset($params['customer_id'])) {
            $customer = Customer::find($params['customer_id']);
            if ($customer) {
                $inputs['customer_type'] = $customer->customer_type ?? 'regular';
                $inputs['customer_tier'] = $customer->tier ?? 'standard';
            }
        }

        // Add time-based factors
        if (isset($params['from_date'])) {
            $fromDate = Carbon::parse($params['from_date']);
            $inputs['is_weekend'] = $fromDate->isWeekend();
            $inputs['is_holiday'] = $this->isHoliday($fromDate);
            $inputs['month'] = $fromDate->month;
            $inputs['day_of_week'] = $fromDate->dayOfWeek;
        }

        return $inputs;
    }

    /**
     * Transform calculation result to standard pricing structure
     * Enhanced to properly extract distance_details for frontend consumption
     */
    private function transformCalculationResult(array $calculationResult, array $params, string $mode): array
    {
        $totalAmount = $calculationResult['total_amount'] ?? 0;
        $totalAmountWithoutCustomizations = $calculationResult['total_amount_without_customizations'] ?? 0;
        $breakdown = $calculationResult['breakdown'] ?? [];
        $kmCalculations = $calculationResult['km_calculations'] ?? [];
        $slabInfo = $calculationResult['slab_info'] ?? [];

        // Build distance_details from km_calculations and slab_info
        $distanceDetails = $this->buildDistanceDetails(
            $kmCalculations,
            $slabInfo,
            $params['service_type_id'] ?? null,
            $params['vehicle_group_id'] ?? null
        );

        Log::debug('TransformCalculationResult - Distance Details Built', [
            'km_calculations' => $kmCalculations,
            'slab_info' => $slabInfo ? [
                'type' => $slabInfo['type'] ?? null,
                'max_km_per_day' => $slabInfo['max_km_per_day'] ?? null,
                'max_km_per_package' => $slabInfo['max_km_per_package'] ?? null,
            ] : null,
            'distance_details' => $distanceDetails,
            'service_type_id' => $params['service_type_id'] ?? null,
            'vehicle_group_id' => $params['vehicle_group_id'] ?? null,
        ]);

        // Build standard pricing structure
        $result = [
            'base_amount' => $totalAmount,
            'total_amount' => $totalAmount,
            'total_amount_without_customizations' => $totalAmountWithoutCustomizations,
            'breakdown' => $this->formatPricingBreakdown($breakdown),
            'distance_details' => $distanceDetails,
            'calculation_metadata' => [
                'definition_used' => $calculationResult['definition_id'] ?? null,
                'variables_used' => $calculationResult['variables_used'] ?? [],
                'conditions_evaluated' => $calculationResult['conditions_evaluated'] ?? [],
                'calculation_mode' => $mode
            ]
        ];

        // Add detailed breakdown if full calculation mode
        if ($mode === 'full_calculation') {
            $result['detailed_breakdown'] = $calculationResult['detailed_breakdown'] ?? [];
            $result['km_calculations'] = $kmCalculations;
            $result['slab_information'] = $slabInfo;
        }

        return $result;
    }

    /**
     * Build distance_details structure for frontend consumption
     * 
     * This method consolidates km limits and extra km rates from:
     * - km_calculations (actual distances and overages)
     * - slab_info (package/daily km limits)
     * - common rate definitions (extra_km_rate per vehicle group)
     * 
     * @param array $kmCalculations KM calculations from pricing calculation
     * @param array|null $slabInfo Slab information including km limits
     * @param string|null $serviceTypeId Service type for rate lookup
     * @param string|null $vehicleGroupId Vehicle group for rate lookup
     * @return array Distance details for frontend display
     */
    private function buildDistanceDetails(
        array $kmCalculations,
        ?array $slabInfo,
        ?string $serviceTypeId,
        ?string $vehicleGroupId
    ): array {
        $distanceDetails = [
            'journey_distance' => $kmCalculations['journey_distance'] ?? 0,
            'allowed_total_km' => $kmCalculations['allowed_km'] ?? 0,
            'extra_km' => $kmCalculations['extra_km'] ?? 0,
            'extra_km_price' => null,
            'calculation_type' => $kmCalculations['calculation_type'] ?? 'none',
            'effective_days' => $kmCalculations['effective_days'] ?? 1,
            'free_km_per_day' => null,
            'free_km_per_package' => null,
        ];

        // Extract per-day or per-package limits from slab info
        if ($slabInfo) {
            $slabDefinition = $slabInfo['slab_definition'] ?? null;

            if (isset($slabInfo['max_km_per_day']) && $slabInfo['max_km_per_day'] > 0) {
                $distanceDetails['free_km_per_day'] = (float) $slabInfo['max_km_per_day'];
            } elseif ($slabDefinition && isset($slabDefinition->max_km_per_day) && $slabDefinition->max_km_per_day > 0) {
                $distanceDetails['free_km_per_day'] = (float) $slabDefinition->max_km_per_day;
            }

            if (isset($slabInfo['max_km_per_package']) && $slabInfo['max_km_per_package'] > 0) {
                $distanceDetails['free_km_per_package'] = (float) $slabInfo['max_km_per_package'];
            } elseif ($slabDefinition && isset($slabDefinition->max_km_per_package) && $slabDefinition->max_km_per_package > 0) {
                $distanceDetails['free_km_per_package'] = (float) $slabDefinition->max_km_per_package;
            }
        }

        // Get extra_km_rate from common rate definitions
        if ($serviceTypeId && $vehicleGroupId) {
            $extraKmRate = $this->getExtraKmRateForVehicleGroup($serviceTypeId, $vehicleGroupId);

            if ($extraKmRate !== null) {
                $distanceDetails['extra_km_price'] = (float) $extraKmRate;
            }

            Log::debug('BuildDistanceDetails - Extra KM Rate Lookup', [
                'service_type_id' => $serviceTypeId,
                'vehicle_group_id' => $vehicleGroupId,
                'extra_km_rate' => $extraKmRate,
            ]);
        }

        return $distanceDetails;
    }

    /**
     * Get extra km rate for a specific vehicle group and service type
     * 
     * Looks up the extra_km_rate from common rate definitions.
     * This rate is used for charging extra kilometers beyond the allowed limit.
     * 
     * @param string $serviceTypeId Service type ID
     * @param string $vehicleGroupId Vehicle group ID
     * @return float|null Extra km rate or null if not configured
     */
    private function getExtraKmRateForVehicleGroup(string $serviceTypeId, string $vehicleGroupId): ?float
    {
        try {
            // Look up the extra_km_rate common rate definition for this service type
            $commonRatePricing = VehicleGroupCommonRatePricing::whereHas('commonRateDefinition', function ($query) use ($serviceTypeId) {
                $query->where('code', 'extra_km_rate')
                    ->where('service_type_id', $serviceTypeId)
                    ->where('is_active', true);
            })
                ->where('vehicle_group_id', $vehicleGroupId)
                ->where('is_active', true)
                ->first();

            if ($commonRatePricing && $commonRatePricing->value !== null) {
                Log::debug('Extra KM Rate found for vehicle group', [
                    'vehicle_group_id' => $vehicleGroupId,
                    'service_type_id' => $serviceTypeId,
                    'rate' => $commonRatePricing->value,
                ]);
                return (float) $commonRatePricing->value;
            }

            // Fallback: Try to get a default rate from the common rate definition itself
            $commonRateDefinition = VehiclePricingCommonRateDefinition::where('code', 'extra_km_rate')
                ->where('service_type_id', $serviceTypeId)
                ->where('is_active', true)
                ->first();

            if ($commonRateDefinition) {
                Log::warning('Extra KM Rate not configured for vehicle group, no default available', [
                    'vehicle_group_id' => $vehicleGroupId,
                    'service_type_id' => $serviceTypeId,
                    'common_rate_definition_id' => $commonRateDefinition->id,
                ]);
            } else {
                Log::warning('Extra KM Rate definition not found for service type', [
                    'service_type_id' => $serviceTypeId,
                ]);
            }

            return null;
        } catch (\Exception $e) {
            Log::error('Error fetching extra km rate', [
                'service_type_id' => $serviceTypeId,
                'vehicle_group_id' => $vehicleGroupId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }


    /**
     * Format pricing breakdown for frontend consumption
     */
    private function formatPricingBreakdown(array $breakdown): array
    {
        $formatted = [];

        foreach ($breakdown as $item) {
            $formatted[] = [
                'name' => $item['description'] ?? $item['component'] ?? 'Unknown Component',
                'amount' => (float) ($item['amount'] ?? 0),
                'calculation' => $item['calculation'] ?? null
            ];
        }

        return $formatted;
    }

    /**
     * Calculate fallback pricing when dynamic calculation fails
     */
    private function calculateFallbackPricing(array $params): array
    {
        $serviceTypeId = $params['service_type_id'];

        // Simple fallback calculation based on service type
        $serviceType = ServiceType::find($serviceTypeId);
        $baseRate = $this->getServiceTypeBaseRate($serviceType);

        return [
            'base_amount' => $baseRate,
            'total_amount' => $baseRate,
            'breakdown' => [],
            'calculation_metadata' => [
                'fallback_used' => true,
                'reason' => 'No active calculation definition found'
            ]
        ];
    }

    /**
     * Get service type base rate for fallback calculations
     */
    private function getServiceTypeBaseRate(?ServiceType $serviceType): float
    {
        if (!$serviceType) {
            return 1000; // Default rate
        }

        // Define base rates by service type
        $rates = [
            'chauffeur_driven' => 1200,
            'wedding_hire' => 2500,
            'airport_drop' => 800,
            'airport_pickup' => 800,
            'transfers' => 900,
            'break_down_service' => 1500,
            'corporate' => 1400,
            'corporate_self' => 1000,
            'self_driven' => 800,
        ];

        return $rates[$serviceType->code] ?? 1000;
    }

    /**
     * Get default pricing structure
     */
    private function getDefaultPricingStructure(): array
    {
        return [
            'base_amount' => 0,
            'total_amount' => 0,
            'breakdown' => [],
            'calculation_metadata' => [
                'default_structure' => true,
                'reason' => 'Insufficient parameters for calculation'
            ]
        ];
    }

    /**
     * Enhanced pricing calculation with session-scoped variable customizations
     * Implements session-scoped design: preview vs persist pattern
     * Now supports multi-vehicle group selection
     */
    public function calculatePricing(array $params): array
    {
        try {
            // Get base currency and target currency
            $baseCurrency = $params['base_currency'] ?? 'LKR';
            $targetCurrency = $params['currency'] ?? $baseCurrency;
            $sessionId = $params['session_id'] ?? null;
            $bookingId = $params['booking_id'] ?? null;
            $isPreviewCalculation = $params['is_preview_calculation'] ?? true;

            // Calculate rental duration (days and hours)
            $duration = $this->calculateDurationInDaysAndHours(
                Carbon::parse($params['from_date']),
                Carbon::parse($params['to_date'])
            );

            // Check if this is multi-group selection or single group
            $isMultiGroup = !empty($params['vehicle_groups']) && count($params['vehicle_groups']) > 1;
            $hasSingleGroup = !empty($params['vehicle_group_id']) || (!empty($params['vehicle_groups']) && count($params['vehicle_groups']) === 1);

            if ($isMultiGroup) {
                // Handle multi-group pricing calculation
                return $this->calculateMultiGroupPricing($params, $duration, $baseCurrency, $targetCurrency);
            } else if ($hasSingleGroup) {
                // Handle single group pricing (existing logic)
                return $this->calculateSingleGroupPricing($params, $duration, $baseCurrency, $targetCurrency);
            } else {
                throw new \Exception('No vehicle groups specified for pricing calculation');
            }
        } catch (\Exception $e) {
            Log::error('Pricing calculation failed', [
                'error' => $e->getMessage(),
                'params' => $params
            ]);

            throw $e;
        }
    }

    /**
     * Calculate pricing for multiple vehicle groups
     */
    private function calculateMultiGroupPricing(array $params, array $duration, string $baseCurrency, string $targetCurrency): array
    {
        $vehicleGroups = $params['vehicle_groups'] ?? [];
        $vehicles = $params['vehicles'] ?? [];
        $selectedAddons = $params['selected_addons'] ?? [];
        $variableCustomizations = $params['variable_customizations'] ?? [];

        $groupPricingResults = [];
        $totalSubtotal = 0;
        $totalAddons = 0;
        $totalAmount = 0;
        $allAppliedCustomizations = [];

        foreach ($vehicleGroups as $groupSelection) {
            $groupId = $groupSelection['id'];
            $quantity = $groupSelection['quantity'];

            // Get group-specific vehicles
            $groupVehicles = array_filter($vehicles, function ($vehicle) use ($groupId) {
                return $vehicle['group_id'] === $groupId;
            });

            // Get group-specific addons
            $groupAddons = array_filter($selectedAddons, function ($addon) use ($groupId) {
                return !isset($addon['vehicle_group_id']) || $addon['vehicle_group_id'] === $groupId;
            });

            // Get group-specific customizations
            $groupCustomizations = array_filter($variableCustomizations, function ($customization) use ($groupId) {
                return !isset($customization['vehicle_group_id']) || $customization['vehicle_group_id'] === $groupId;
            });

            // Calculate pricing for this specific group
            $groupParams = array_merge($params, [
                'vehicle_group_id' => $groupId,
                'vehicles' => array_values($groupVehicles),
                'selected_addons' => array_values($groupAddons),
                'variable_customizations' => array_values($groupCustomizations),
                'quantity' => $quantity
            ]);

            $groupResult = $this->calculateSingleGroupPricing($groupParams, $duration, $baseCurrency, $targetCurrency);

            // Multiply by quantity for this group
            $groupResult['base_pricing']['total_amount'] *= $quantity;
            $groupResult['addons_pricing']['addons_total'] *= $quantity;
            $groupResult['summary']['total'] *= $quantity;
            $groupResult['summary']['subtotal'] *= $quantity;
            $groupResult['summary']['addons_total'] *= $quantity;

            // Add group metadata
            $groupResult['group_id'] = $groupId;
            $groupResult['quantity'] = $quantity;
            $groupResult['group_info'] = $this->getVehicleGroupInfo($groupId);

            $groupPricingResults[] = $groupResult;

            // Aggregate totals
            $totalSubtotal += $groupResult['summary']['subtotal'];
            $totalAddons += $groupResult['summary']['addons_total'];
            $totalAmount += $groupResult['summary']['total'];

            // Collect all customizations
            if (!empty($groupResult['applied_customizations'])) {
                $allAppliedCustomizations = array_merge($allAppliedCustomizations, $groupResult['applied_customizations']);
            }
        }

        return [
            'success' => true,
            'is_multi_group' => true,
            'currency' => $targetCurrency,
            'duration' => $duration,
            'groups' => $groupPricingResults,
            'applied_customizations' => $allAppliedCustomizations,
            'summary' => [
                'subtotal' => $totalSubtotal,
                'addons_total' => $totalAddons,
                'total' => $totalAmount,
                'currency' => $targetCurrency,
                'group_count' => count($vehicleGroups),
                'total_vehicles' => array_sum(array_column($vehicleGroups, 'quantity'))
            ],
            'calculations' => [
                'base_currency' => $baseCurrency,
                'target_currency' => $targetCurrency,
                'duration_days' => $duration['days'],
                'duration_hours' => $duration['hours'],
                'calculated_at' => now()->toISOString()
            ]
        ];
    }

    /**
     * Calculate pricing for single vehicle group (existing logic, refactored)
     */
    private function calculateSingleGroupPricing(array $params, array $duration, string $baseCurrency, string $targetCurrency): array
    {
        $sessionId = $params['session_id'] ?? null;
        $bookingId = $params['booking_id'] ?? null;

        // Ensure we have a vehicle_group_id for single group calculation
        if (empty($params['vehicle_group_id']) && !empty($params['vehicle_groups'])) {
            $params['vehicle_group_id'] = $params['vehicle_groups'][0]['id'];
        }

        // === SESSION-SCOPED DESIGN: PREVIEW vs PERSIST ===
        $appliedCustomizations = [];

        if (!empty($params['variable_customizations'])) {
            $appliedCustomizations = $this->applyVariableCustomizations($params['variable_customizations'], $params);
        } else if (!empty($bookingId)) {
            // Edit mode: Load existing persisted customizations for this vehicle group
            $vehicleGroupId = $params['vehicle_group_id'] ?? null;
            $query = \App\Models\Booking\BookingVariableCustomization::where('booking_id', $bookingId);

            if ($vehicleGroupId) {
                $query->where('vehicle_group_id', $vehicleGroupId);
            }

            $existingCustomizations = $query->orderBy('updated_at', 'desc')
                ->get()
                ->groupBy('variable_name')
                ->map(function ($customizations) {
                    // Return the most recent customization for each variable
                    $latest = $customizations->first();
                    return [
                        'variable_name' => $latest->variable_name,
                        'variable_type' => $latest->variable_type,
                        'original_value' => $latest->original_value,
                        'custom_value' => $latest->custom_value,
                        'reason' => $latest->customization_reason,
                        'context' => $latest->context,
                        'vehicle_group_id' => $latest->vehicle_group_id,
                    ];
                })
                ->values()
                ->toArray();

            if (!empty($existingCustomizations)) {
                $appliedCustomizations = $this->applyVariableCustomizations($existingCustomizations, $params);
            }
        } else if (!empty($sessionId)) {
            Log::info('🔄 Session-scoped mode - no session persistence implemented', [
                'session_id' => $sessionId
            ]);
        }

        // Calculate base pricing with duration and variable customizations
        $basePricingResult = $this->calculateDynamicPricingWithCustomizations(
            array_merge($params, [
                'duration_days' => $duration['days'],
                'duration_hours' => $duration['hours'],
                'mode' => 'base_with_duration',
                'duration' => $duration,
                'applied_customizations' => $appliedCustomizations,
                'session_id' => $sessionId
            ])
        );

        // Calculate addons with enhanced handling and duration awareness
        $addonsPricingResult = $this->calculateAddonsPricingWithDuration(
            $params['selected_addons'] ?? [],
            $params['vehicle_group_id'],
            $duration,
            $params
        );

        // Apply currency conversion if needed
        if ($targetCurrency !== $baseCurrency) {
            $basePricingResult = $this->currencyService->convertPricingStructure($basePricingResult, $baseCurrency, $targetCurrency);
            $addonsPricingResult = $this->currencyService->convertPricingStructure($addonsPricingResult, $baseCurrency, $targetCurrency);
        }

        // Calculate totals with proper duration multipliers applied in backend
        $subtotal = $basePricingResult['total_amount'] ?? 0;
        $addonsTotal = $addonsPricingResult['addons_total'] ?? 0;
        $addonsTotalWithoutCustomizations = $addonsPricingResult['addons_total_without_customizations'] ?? 0;
        $subtotalWithoutCustomizations = $basePricingResult['total_amount_without_customizations'] ?? 0;
        $totalAmount = $subtotal + $addonsTotal;
        $totalAmountWithoutCustomizations = $subtotalWithoutCustomizations + $addonsTotalWithoutCustomizations;

        $result = [
            'success' => true,
            'currency' => $targetCurrency,
            'base_pricing' => $basePricingResult,
            'addons_pricing' => $addonsPricingResult,
            'duration' => $duration,
            'applied_customizations' => $appliedCustomizations,
            'summary' => [
                'subtotal' => $subtotal,
                'addons_total' => $addonsTotal,
                'addons_total_without_customizations' => $addonsTotalWithoutCustomizations,
                'subtotal_without_customizations' => $subtotalWithoutCustomizations,
                'total' => $totalAmount,
                'currency' => $targetCurrency,
                'total_without_customizations' => $totalAmountWithoutCustomizations,
                'exchange_rate' => $this->currencyService->getExchangeRate($baseCurrency, $targetCurrency)
            ],
            'requires_approval' => $this->determineApprovalRequirement($params, $basePricingResult, $addonsPricingResult)
        ];

        $totalDiscountAmount = 0;
        $breakdown = [];
        $appliedDiscounts = $params['applied_discounts'] ?? [];
        if ($appliedDiscounts) {
            foreach ($appliedDiscounts as $discount) {
                $total = $result['summary']['total'] ?? 0;
                $discountPercentageorAmount = $discount['value'] ?? 0;

                $discountAmount = $discount['method'] === 'percentage' ? ($total * $discountPercentageorAmount) / 100 : $discountPercentageorAmount;
                $totalDiscountAmount += $discountAmount;

                $breakdown[] = [
                    'id' => $discount['id'] ?? null,
                    'name' => $discount['name'] ?? $discount['discount_name'] ?? 'Discount',
                    'type' => $discount['type'] ?? 'manual',
                    'amount' => $discountAmount,
                    'method' => $discount['method'],
                    'value' => $discount['value'] ?? 0,
                    'description' => $discount['description'] ?? $discount['reason'] ?? '',
                ];
            }
        }

        $discountSummary = [
            'total_discount_amount' => $totalDiscountAmount,
            'discount_count' => count($appliedDiscounts),
            'breakdown' => $breakdown,
        ];

        $result['discount_summary'] = $discountSummary;
        $result['final_breakdown'] = [
            'subtotal_before_discount' => ($result['summary']['total'] ?? $result['summary']['subtotal'] ?? 0),
            'total_discount' => $discountSummary['total_discount_amount'] ?? 0,
            'final_amount' => ($result['summary']['total'] ?? 0) - ($discountSummary['total_discount_amount'] ?? 0),
            'requires_approval' => $result['requires_approval'] ?? false,
        ];


        // Update final breakdown to include all adjustments
        $result['final_breakdown'] = [
            'subtotal_before_discount' => ($result['summary']['total'] ?? $result['summary']['subtotal'] ?? 0),
            'total_discount' => $discountSummary['total_discount_amount'] ?? 0,
            'amount_after_discount' => ($result['summary']['total'] ?? 0) - ($discountSummary['total_discount_amount'] ?? 0),
            'requires_approval' => $result['requires_approval'] ?? false,
        ];

        // Enhanced pricing breakdown with before/after customizations
        $result['detailed_breakdown'] = [
            'price_before_customizations' => [
                'base_amount' => $result['summary']['subtotal_without_customizations'] ?? 0,
                'addon_amount' => $result['summary']['addons_total_without_customizations'] ?? 0,
                'subtotal' => $result['summary']['total_without_customizations'] ?? 0,
            ],
            'customizations_applied' => [
                'base_overrides' => $result['summary']['subtotal_without_customizations'] != $result['summary']['subtotal'] ? true : false,
                'addon_overrides' => $result['summary']['addons_total_without_customizations'] != $result['summary']['addons_total'] ? true : false,
                'discount_overrides' => $result['discount_summary']['total_discount_amount'] > 0 ? true : false,
                'variable_customizations' => !empty($params['variable_customizations']),
            ],
            'price_after_customizations' => [
                'base_amount' => $result['summary']['subtotal'] ?? $result['summary']['subtotal'] ?? 0,
                'addon_amount' => $result['summary']['addons_total'] ?? $result['summary']['addons_total'] ?? 0,
                'subtotal' => $result['summary']['total'] ?? 0
            ],
            'discounts_applied' => $result['discount_summary'],
            'km_range_pricing_applied' => $result['km_range_pricing'],
            'price_adjustments_applied' => $result['price_adjustments'],
            'final_totals' => $result['final_breakdown'],
        ];

        return $result;
    }

    /**
     * Get vehicle group information for multi-group pricing
     */
    private function getVehicleGroupInfo($groupId): array
    {
        try {
            $group = VehicleGroup::find($groupId);
            if (!$group) {
                return ['id' => $groupId, 'name' => 'Unknown Group'];
            }

            return [
                'id' => $group->id,
                'name' => $group->name,
                'description' => $group->description,
                'vehicle_type' => $group->vehicle_type ?? null,
                'capacity' => $group->capacity ?? null
            ];
        } catch (\Exception $e) {
            Log::error('Failed to get vehicle group info', [
                'group_id' => $groupId,
                'error' => $e->getMessage()
            ]);

            return ['id' => $groupId, 'name' => 'Unknown Group'];
        }
    }

    /**
     * Determine if approval is required for enhanced pricing
     */
    private function determineApprovalRequirement(array $params, array $basePricing, array $addonsPricing): bool
    {
        // Check for custom pricing
        $hasCustomBasePricing = isset($params['custom_base_pricing']) && !empty($params['custom_base_pricing']);
        $hasCustomAddonPricing = isset($params['custom_addon_pricing']) && !empty($params['custom_addon_pricing']);

        // Check for high value
        $totalAmount = ($basePricing['total_amount'] ?? 0) + ($addonsPricing['addons_total'] ?? 0);
        $isHighValue = $totalAmount > 100000; // Configurable threshold

        // Check for large discounts
        $hasLargeDiscount = false;
        if (isset($params['discounts'])) {
            foreach ($params['discounts'] as $discount) {
                if (
                    ($discount['type'] === 'percentage' && $discount['value'] > 20) ||
                    ($discount['type'] === 'amount' && $discount['value'] > 50000)
                ) {
                    $hasLargeDiscount = true;
                    break;
                }
            }
        }

        return $hasCustomBasePricing || $hasCustomAddonPricing || $isHighValue || $hasLargeDiscount;
    }

    private function calculateDistance(array $from, array $to): ?float
    {

        // Validate input and handle different location formats
        if (!$this->isValidLocationArray($from) || !$this->isValidLocationArray($to)) {
            Log::info('Calculating distance between coordinates', [
                'from' => [
                    'latitude' => $this->extractLatitude($from),
                    'longitude' => $this->extractLongitude($from),
                ],
                'to' => [
                    'address' => $to['address'] ?? 'Unknown',
                    'latitude' => $this->extractLatitude($to),
                    'longitude' => $this->extractLongitude($to),
                ],
                'from_valid' => $this->isValidLocationArray($from),
                'to_valid' => $this->isValidLocationArray($to)
            ]);

            // For missing coordinates, return null to indicate calculation not possible
            // This allows the system to show "Request Quotation" instead of "Not Available"
            return null;
        }

        try {
            $distance = app(GoogleMapsService::class)->distanceKm($from, $to);
            return $distance;
        } catch (\Exception $e) {
            Log::error('Error calculating distance', [
                'from' => $from,
                'to' => $to,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            // Return null instead of 0.0 to indicate calculation failure
            return null;
        }
    }

    private function calculateCompanyDistances(array $pickupLocation, array $dropoffLocation, ?string $serviceType = null, ?string $specificVehicleId = null): array
    {
        // Get the company location (specified company or default)
        $vehicle = $specificVehicleId ? Vehicle::find($specificVehicleId) : null;
        $company = $vehicle ? $vehicle->company : \App\Models\Company::getDefaultCompany();

        $journeyDistance = $this->calculateDistance($pickupLocation, $dropoffLocation);

        // Handle case when journey distance cannot be calculated
        if ($journeyDistance === null) {
            return [
                'pickup_distance' => null,
                'delivery_distance' => null,
                'journey_distance' => null,
                'calculation_possible' => false,
                'service_type_used' => $serviceType,
                'company_used' => [
                    'id' => $company ? $company->id : null,
                    'name' => $company ? $company->name : null,
                    'coordinates' => $company ? [$company->latitude, $company->longitude] : null
                ],
                'company_location' => $company ? [
                    'latitude' => $company->latitude,
                    'longitude' => $company->longitude
                ] : null,
                'company_id' => $company ? $company->id : null
            ];
        }

        if (!$company || !$company->latitude || !$company->longitude) {
            return [
                'pickup_distance' => 10.0,
                'delivery_distance' => 10.0,
                'journey_distance' => round($journeyDistance, 2),
                'calculation_possible' => true,
                'service_type_used' => $serviceType,
                'company_used' => [
                    'id' => null,
                    'name' => null,
                    'coordinates' => null
                ],
                'company_location' => null,
                'company_id' => null
            ];
        }

        $companyLocation = [
            'latitude' => $company->latitude,
            'longitude' => $company->longitude
        ];

        // Determine service type category
        $isWithDriver = $this->isWithDriverService($serviceType);

        $distances = [];

        if ($isWithDriver) {
            // With Driver Services:
            // - Pickup Distance: From company to customer's starting location (driver goes to pickup)
            // - Delivery Distance: From customer's drop-off location to company (driver returns)
            $distances['pickup_distance'] = $this->calculateDistance($companyLocation, $pickupLocation);
            $distances['delivery_distance'] = $this->calculateDistance($dropoffLocation, $companyLocation);
        } else {
            // Self Drive Services:
            // - Pickup Distance: From customer's drop-off location to company (customer returns vehicle)
            // - Delivery Distance: From company to customer's starting location (vehicle delivery)
            $distances['pickup_distance'] = $this->calculateDistance($dropoffLocation, $companyLocation);
            $distances['delivery_distance'] = $this->calculateDistance($companyLocation, $pickupLocation);
        }

        // Check if any distance calculation failed
        if ($distances['pickup_distance'] === null || $distances['delivery_distance'] === null) {
            return [
                'pickup_distance' => $distances['pickup_distance'],
                'delivery_distance' => $distances['delivery_distance'],
                'journey_distance' => $journeyDistance,
                'calculation_possible' => false,
                'service_type_used' => $serviceType,
                'company_used' => [
                    'id' => $company->id,
                    'name' => $company->name,
                    'coordinates' => $companyLocation
                ],
                'company_location' => $companyLocation,
                'company_id' => $company->id
            ];
        }

        // Enhanced return structure with all required fields for pricing calculations (no costs here)
        return [
            'journey_distance' => round($journeyDistance, 2),
            'pickup_distance' => round($distances['pickup_distance'], 2),
            'delivery_distance' => round($distances['delivery_distance'], 2),
            'total_distance' => round($journeyDistance + $distances['pickup_distance'] + $distances['delivery_distance'], 2), // Main billable distance (excludes company legs)
            'calculation_possible' => true,
            'service_type_used' => $serviceType,
            'company_used' => [
                'id' => $company->id,
                'name' => $company->name,
                'coordinates' => $companyLocation
            ],
            'company_location' => $companyLocation,
            'company_id' => $company->id
        ];
    }

    /**
     * Determine if a service type requires a driver
     */
    private function isWithDriverService(?string $serviceType): bool
    {
        $selfDrivenServices = [
            'self_driven',
            'corporate_self',
            'self_drive',
        ];
        return !($serviceType && in_array(strtolower($serviceType), $selfDrivenServices, true));
    }

    /**
     * Check if a date is a holiday (simple stub; replace with DB/API as needed)
     */
    private function isHoliday(Carbon $date): bool
    {
        $holidays = [
            '01-01', // New Year's Day
            '12-25', // Christmas
        ];
        return in_array($date->format('m-d'), $holidays, true);
    }

    /**
     * Check if a location is an airport
     * Used to apply airport-specific pricing rules
     */
    private function isAirportLocation(?array $location): bool
    {
        if (!$location || !isset($location['address'])) {
            return false;
        }

        $address = strtolower($location['address']);

        // Comprehensive list of Sri Lankan airports and related keywords (case-insensitive matching)
        $airportKeywords = [
            'airport',
            'bandaranaike',
            'bandaranayake', // Common misspelling
            'mattala',
            'colombo bia',
            'negombo airport',
            'katunayake',
            'ratmalana airport',
            'jaffna airport',
            'jaffna international',
            'koggala airport',
            'sigiriya airport',
            'ampara airport',
            'hambantota airport',
            'rajapaksa international',
            'bia',
            'cmb airport', // Airport code
            'hri airport', // Mattala code
            'jaf airport', // Jaffna code
            'kct airport', // Koggala code
            'rml airport', // Ratmalana code
            'giu airport', // Sigiriya code
            'international airport',
            'air terminal',
            'aviation',
        ];

        foreach ($airportKeywords as $keyword) {
            if (strpos($address, $keyword) !== false) {
                return true;
            }
        }

        // Also check coordinates against known airport locations
        if (isset($location['latitude']) && isset($location['longitude'])) {
            $knownAirports = [
                ['lat' => 7.1808, 'lng' => 79.8841, 'name' => 'Bandaranaike International Airport'], // BIA
                ['lat' => 6.2844, 'lng' => 81.1247, 'name' => 'Mattala Rajapaksa International Airport'], // HRI
                ['lat' => 9.7923, 'lng' => 80.0700, 'name' => 'Jaffna Airport'], // JAF
                ['lat' => 5.9936, 'lng' => 80.3203, 'name' => 'Koggala Airport'], // KCT
                ['lat' => 6.8220, 'lng' => 79.8862, 'name' => 'Ratmalana Airport'], // RML
                ['lat' => 7.9563, 'lng' => 80.7281, 'name' => 'Sigiriya Airport'], // GIU
                ['lat' => 7.3417, 'lng' => 81.6500, 'name' => 'Ampara Airport'], // AMP
            ];

            $locationLat = (float) $location['latitude'];
            $locationLng = (float) $location['longitude'];

            foreach ($knownAirports as $airport) {
                // Check if coordinates are within 2km radius of known airport
                $distance = $this->calculateDistance(
                    ['latitude' => $locationLat, 'longitude' => $locationLng],
                    ['latitude' => $airport['lat'], 'longitude' => $airport['lng']]
                );

                if ($distance <= 2.0) { // Within 2km radius
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get customizable variables for base pricing
     * Now supports multiple vehicle groups
     */
    public function getCustomizableVariables($serviceTypeId, $vehicleGroupIds, ?string $bookingId = null): array
    {
        // Ensure we have an array of vehicle group IDs
        if (!is_array($vehicleGroupIds)) {
            $vehicleGroupIds = [$vehicleGroupIds];
        }

        $allVariables = [];

        foreach ($vehicleGroupIds as $vehicleGroupId) {
            $groupVariables = $this->pricingVariableService->getCustomizableVariables($serviceTypeId, $vehicleGroupId);

            foreach ($groupVariables as $variable) {
                $variableName = $variable['name'] ?? $variable['variable_name'] ?? null;
                if (!$variableName)
                    continue;

                // Create unique variable entry for each group
                $variableWithGroup = $variable;
                $variableWithGroup['vehicle_group_id'] = $vehicleGroupId;
                $variableWithGroup['unique_key'] = $variableName . '_' . $vehicleGroupId; // Unique identifier
                $variableWithGroup['applicable_groups'] = [$vehicleGroupId]; // Only applicable to this specific group

                $allVariables[] = $variableWithGroup;
            }
        }

        // If booking ID is provided, merge with existing customizations
        if ($bookingId) {
            // Get existing customizations with vehicle group context
            $existingCustomizations = BookingVariableCustomization::where('booking_id', $bookingId)
                ->orderBy('updated_at', 'desc')
                ->get()
                ->groupBy(function ($customization) {
                    // Group by variable_name + vehicle_group_id for unique identification
                    return $customization->variable_name . '_' . ($customization->vehicle_group_id ?? '');
                })
                ->map(function ($customizations) {
                    // Return the most recent customization for each variable+group combination
                    return $customizations->first();
                });

            foreach ($allVariables as &$variable) {
                $variableName = $variable['name'] ?? $variable['variable_name'] ?? null;
                $vehicleGroupId = $variable['vehicle_group_id'];
                $uniqueKey = $variableName . '_' . $vehicleGroupId;

                if (!$variableName || !$vehicleGroupId)
                    continue;

                $customization = $existingCustomizations->get($uniqueKey);
                if ($customization) {
                    $variable['original_value'] = $customization->original_value;
                    $variable['custom_value'] = $customization->custom_value;
                    $variable['current_value'] = $customization->custom_value;
                    $variable['is_customized'] = true;
                    $variable['customization_reason'] = $customization->customization_reason;
                } else {
                    $variable['is_customized'] = false;
                    $variable['custom_value'] = null;
                }
            }
        }

        return $allVariables;
    }

    /**
     * Store variable customizations
     */
    public function storeVariableCustomizations(array $customizations, ?string $bookingId = null, ?string $sessionId = null): array
    {
        return $this->pricingVariableService->storeVariableCustomizations($customizations, $bookingId, $sessionId);
    }

    /**
     * Get existing variable customizations
     */
    public function getVariableCustomizations(?string $bookingId = null, ?string $sessionId = null): array
    {
        return $this->pricingVariableService->getVariableCustomizations($bookingId, $sessionId);
    }


    /**
     * Apply discount using the new DiscountService
     */
    public function applyDiscount(array $discountData, string $bookingId = null): array
    {
        $discountService = app(DiscountService::class);
        return $discountService->applyDiscount($discountData, $bookingId);
    }

    /**
     * Apply gamify discount using the new loyalty system
     */
    public function applyGamifyDiscount(string $bookingId, int $pointsToRedeem, string $userId): array
    {
        $booking = Booking::findOrFail($bookingId);

        $discountData = [
            'type' => 'loyalty_points',
            'customer_id' => $booking->customer_id,
            'points_to_redeem' => $pointsToRedeem,
            'order_amount' => $booking->total_amount ?? 0,
        ];

        $discountService = app(DiscountService::class);
        return $discountService->applyDiscount($discountData, $bookingId);
    }

    /**
     * Get customer loyalty information for gamification
     */
    public function getCustomerLoyaltyInfo(string $customerId): array
    {
        $discountService = app(DiscountService::class);
        return $discountService->getCustomerLoyaltyInfo($customerId);
    }

    /**
     * Process loyalty points earning for completed booking
     */
    public function processLoyaltyPointsEarning(string $bookingId): array
    {
        $discountService = app(DiscountService::class);
        return $discountService->processLoyaltyPointsEarning($bookingId);
    }

    /**
     * Remove discount from booking
     */
    public function removeDiscount(string $discountId, ?string $reason = null): array
    {
        $discountService = app(DiscountService::class);
        return $discountService->removeDiscount($discountId, $reason);
    }

    /**
     * Get comprehensive discount summary for booking
     */
    public function getBookingDiscountSummary(string $bookingId): array
    {
        $discountService = app(DiscountService::class);
        return $discountService->getBookingDiscountSummary($bookingId);
    }

    /**
     * Get approval details for a booking
     */
    public function getApprovalDetails(string $bookingId): array
    {
        $booking = Booking::with(['customer', 'basePriceEditedBy', 'approvedBy'])->findOrFail($bookingId);

        return [
            'booking' => $booking,
            'original_totals' => $booking->original_totals,
            'edited_totals' => $booking->edited_totals,
            'overrides' => [
                'base_price' => [
                    'amount' => $booking->base_price_override,
                    'reason' => $booking->base_price_override_reason,
                    'edited_by' => $booking->basePriceEditedBy?->name,
                    'edited_at' => $booking->base_price_edited_at
                ],
                'addons' => $booking->addon_overrides,
                'discounts' => $booking->discounts
            ],
            'approval_status' => $booking->approval_status,
            'approved_by' => $booking->approvedBy?->name,
            'approval_at' => $booking->approval_at,
            'approval_note' => $booking->approval_note,
            'requires_approval' => $booking->requiresApproval()
        ];
    }

    /**
     * Process booking approval or rejection
     */
    public function processApproval(string $bookingId, string $action, ?string $note, string $userId): array
    {
        $booking = Booking::findOrFail($bookingId);

        if (!$booking->requiresApproval()) {
            throw new \Exception('This booking does not require approval.');
        }

        $booking->approval_status = $action === 'approve' ? 'approved' : 'rejected';
        $booking->approval_by = $userId;
        $booking->approval_at = now();
        $booking->approval_note = $note;

        if ($action === 'approve') {
            // Lock the pricing values
            $booking->confirmed = true;
        } else {
            // Reset overrides if rejected
            $booking->base_price_override = null;
            $booking->base_price_override_reason = null;
            $booking->addon_overrides = null;
            $booking->discounts = null;

            // Restore original totals
            if ($booking->original_totals) {
                $booking->base_amount = $booking->original_totals['base_amount'] ?? $booking->base_amount;
                $booking->total_estimated = $booking->original_totals['total_estimated'] ?? $booking->total_estimated;
            }
        }

        $booking->save();

        return [
            'booking' => $booking,
            'action' => $action,
            'message' => 'Booking ' . $action . 'd successfully'
        ];
    }

    /**
     * Calculate overridden addons cost
     */
    private function calculateOverriddenAddonsCost(Booking $booking): float
    {
        $totalCost = 0;
        $overrides = $booking->addon_overrides ?? [];

        foreach ($overrides as $override) {
            $price = $override['override_price'] ?? 0;
            $quantity = $override['override_quantity'] ?? 1;
            $totalCost += $price * $quantity;
        }

        return $totalCost;
    }

    /**
     * Get all company locations for dropdown selection
     */
    public function getCompanyLocations(): array
    {
        // Get all company locations that have valid address and coordinates
        $companies = Company::where('is_active', true)
            ->whereNotNull('address')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->select('id', 'name', 'address', 'latitude', 'longitude', 'is_default')
            ->orderBy('is_default', 'desc')
            ->orderBy('name')
            ->get();

        $locations = [];

        foreach ($companies as $company) {
            $locations[] = [
                'id' => $company->id,
                'name' => $company->name,
                'address' => $company->address,
                'latitude' => (float) $company->latitude,
                'longitude' => (float) $company->longitude,
                'is_default' => (bool) $company->is_default,
                'type' => 'company_location'
            ];
        }

        // If no companies found, add the default fallback location
        if (empty($locations)) {
            $locations[] = [
                'id' => 'default',
                'name' => 'TheTaxi Company (Main)',
                'address' => 'TheTaxi Company, Colombo, Sri Lanka',
                'latitude' => 6.9271,
                'longitude' => 79.8612,
                'is_default' => true,
                'type' => 'company_location'
            ];
        }

        return $locations;
    }

    /**
     * Get comprehensive booking summary for review
     */
    public function getBookingSummary(array $data): array
    {
        try {
            $summary = [];

            // Get customer details
            if (isset($data['customer_id'])) {
                $customer = Customer::find($data['customer_id']);
                $summary['customer'] = $customer ? [
                    'id' => $customer->id,
                    'name' => $customer->name,
                    'email' => $customer->email,
                    'phone' => $customer->phone,
                    'nic' => $customer->nic,
                    'address' => $customer->address
                ] : null;
            }

            // Get vehicle group details
            if (isset($data['vehicle_group_id'])) {
                $vehicleGroup = VehicleGroup::find($data['vehicle_group_id']);
                $summary['vehicle_group'] = $vehicleGroup ? [
                    'id' => $vehicleGroup->id,
                    'name' => $vehicleGroup->name,
                    'category' => $vehicleGroup->category,
                    'description' => $vehicleGroup->description,
                    'features' => $vehicleGroup->features,
                ] : null;
            }

            // Get specific vehicle details if selected
            if (isset($data['vehicle_id'])) {
                $vehicle = Vehicle::find($data['vehicle_id']);
                $summary['specific_vehicle'] = $vehicle ? [
                    'id' => $vehicle->id,
                    'name' => $vehicle->name,
                    'make' => $vehicle->make,
                    'model' => $vehicle->model,
                    'year' => $vehicle->year,
                    'license_plate' => $vehicle->license_plate,
                    'seating_capacity' => $vehicle->seating_capacity,
                    'availability_status' => $vehicle->availability_status
                ] : null;
            }

            // Get driver details if selected
            if (isset($data['driver_id'])) {
                $driver = Driver::find($data['driver_id']);
                $summary['driver'] = $driver ? [
                    'id' => $driver->id,
                    'name' => $driver->name,
                    'license_number' => $driver->license_number,
                    'experience_years' => $driver->experience_years,
                    'availability_status' => $driver->availability_status
                ] : null;
            }

            // Get service type details
            $summary['service_details'] = [
                'service_type' => $data['service_type'],
                'service_type_id' => $data['service_type_id'],
                'from_date' => $data['from_date'],
                'to_date' => $data['to_date'],
                'from_time' => $data['from_time'] ?? null,
                'to_time' => $data['to_time'] ?? null
            ];

            // Get location details
            if (isset($data['pickup_location_id'])) {
                $pickupLocation = Company::find($data['pickup_location_id']);
                $summary['pickup_location'] = $pickupLocation ? [
                    'id' => $pickupLocation->id,
                    'name' => $pickupLocation->name,
                    'address' => $pickupLocation->address
                ] : null;
            }

            if (isset($data['dropoff_location_id'])) {
                $dropoffLocation = Company::find($data['dropoff_location_id']);
                $summary['dropoff_location'] = $dropoffLocation ? [
                    'id' => $dropoffLocation->id,
                    'name' => $dropoffLocation->name,
                    'address' => $dropoffLocation->address
                ] : null;
            }

            // Get selected addons
            if (isset($data['selected_addons']) && is_array($data['selected_addons'])) {
                $addonIds = array_column($data['selected_addons'], 'addon_id');
                $addons = VehicleAddon::whereIn('id', $addonIds)->get();

                $summary['selected_addons'] = [];
                foreach ($data['selected_addons'] as $selectedAddon) {
                    $addon = $addons->firstWhere('id', $selectedAddon['addon_id']);
                    if ($addon) {
                        $summary['selected_addons'][] = [
                            'id' => $addon->id,
                            'name' => $addon->name,
                            'description' => $addon->description,
                            'base_price' => $addon->base_price,
                            'quantity' => $selectedAddon['quantity'] ?? 1,
                            'custom_price' => $selectedAddon['custom_price'] ?? null,
                            'total_price' => ($selectedAddon['custom_price'] ?? $addon->base_price) * ($selectedAddon['quantity'] ?? 1)
                        ];
                    }
                }
            }

            return $summary;
        } catch (\Exception $e) {
            Log::error('Error getting booking summary: ' . $e->getMessage());
            throw $e;
        }
    }

    protected function syncBookingAddons(Booking $booking, array $selectedAddons): void
    {
        foreach ($selectedAddons as $row) {
            $addonId = $row['id'] ?? $row['addon_id'] ?? null;
            if (!$addonId)
                continue;

            $booking->bookingAddons()->create([
                'addon_id' => $addonId,
                'quantity' => isset($row['quantity']) ? (int) $row['quantity'] : 1,
                'custom_price' => $row['custom_price'] ?? null,
            ]);
        }
    }

    protected function extractTotalsFromPricing(array $pricing): array
    {
        $snapshot = $pricing;

        $baseAmount = (float) ($pricing['base_pricing']['total_amount'] ?? 0);
        $addonsCost = (float) ($pricing['addons_pricing']['addons_total'] ?? 0);
        $discountAmount = (float) ($pricing['discount_summary']['total_discount_amount'] ?? 0);

        // If final_breakdown exists, prefer it; otherwise derive
        $finalAmount = (float) ($pricing['final_breakdown']['final_amount']
            ?? (($pricing['summary']['total'] ?? 0) - $discountAmount));

        return [
            'pricing_snapshot' => $snapshot,
            'base_amount' => $baseAmount,
            'addons_cost' => $addonsCost,
            'discount_amount' => $discountAmount,
            'total_estimated' => $finalAmount,
            'duration_metrics' => $pricing['duration'] ?? null,
            'distance_metrics' => $pricing['km_calculations']
                ?? ($pricing['base_pricing']['km_calculations'] ?? null),
        ];
    }

    /**
     * Transform frontend booking data to database format
     */
    private function transformBookingData(array $bookingData): array
    {
        $serviceDetails = $bookingData['service_details'] ?? [];
        $vehicleDriver = $bookingData['vehicle_driver'] ?? [];
        $customer = $bookingData['customer'] ?? [];
        $pricing = $bookingData['pricing'] ?? [];
        $addons = $bookingData['addons'] ?? [];

        // Base booking data
        $data = [
            'customer_id' => $customer['customer_id'] ?? null,
            'service_type_id' => $serviceDetails['service_type'] ?? null,
            'vehicle_group_id' => $vehicleDriver['vehicle_group_id'] ?? null,
            'vehicle_id' => $vehicleDriver['vehicle_id'] ?? $vehicleDriver['vehicle_id'] ?? null,
            'driver_id' => $vehicleDriver['driver_id'] ?? null,

            // Dates and times
            'booking_date' => now(),
            'from_date' => $serviceDetails['from_date'] ?? null,
            'to_date' => $serviceDetails['to_date'] ?? null,
            'from_time' => $serviceDetails['from_time'] ?? null,
            'to_time' => $serviceDetails['to_time'] ?? null,

            // Location data
            'pickup_location' => $serviceDetails['pickup_location'] ?? null,
            'dropoff_location' => $serviceDetails['dropoff_location'] ?? null,
            'pickup_latitude' => $serviceDetails['pickup_location']['latitude'] ?? null,
            'pickup_longitude' => $serviceDetails['pickup_location']['longitude'] ?? null,
            'dropoff_latitude' => $serviceDetails['dropoff_location']['latitude'] ?? null,
            'dropoff_longitude' => $serviceDetails['dropoff_location']['longitude'] ?? null,

            // Service details
            'is_self_driven' => $serviceDetails['is_self_driven'] ?? false,
            'passenger_count' => $serviceDetails['passenger_count'] ?? 1,
            'luggage_count' => $serviceDetails['luggage_count'] ?? null,
            'special_requirements' => $serviceDetails['special_requirements'] ?? null,
        ];

        // Calculate pricing amounts from breakdown or direct values
        $breakdown = $pricing['breakdown'] ?? null;

        $baseAmount = 0;
        if ($breakdown && isset($breakdown['subtotal'])) {
            $baseAmount = $breakdown['subtotal'];
        } elseif (isset($pricing['base_pricing_total'])) {
            $baseAmount = $pricing['base_pricing_total'];
        } elseif (isset($pricing['base_amount'])) {
            $baseAmount = $pricing['base_amount'];
        }

        // Addons cost from breakdown
        $addonsCost = 0;
        if ($breakdown && isset($breakdown['addons_total'])) {
            $addonsCost = $breakdown['addons_total'];
        } elseif (isset($pricing['addons_total'])) {
            $addonsCost = $pricing['addons_total'];
        }

        // Discount amount
        $discountAmount = 0;
        if ($breakdown && isset($breakdown['total_discount'])) {
            $discountAmount = $breakdown['total_discount'];
        } elseif (isset($pricing['discount_amount'])) {
            $discountAmount = $pricing['discount_amount'];
        }


        // Tax amount
        $taxAmount = $pricing['tax_amount'] ?? 0;
        if ($taxAmount > 100000) {
            $taxAmount = $taxAmount / 100;
        }

        // Total amount - try multiple sources
        $totalAmount = 0;
        if ($breakdown && isset($breakdown['total'])) {
            $totalAmount = $breakdown['total'];
        } elseif (isset($pricing['total'])) {
            $totalAmount = $pricing['total'];
        } elseif (isset($pricing['total_amount'])) {
            $totalAmount = $pricing['total_amount'];
        } else {
            // Calculate total from components
            $totalAmount = $baseAmount + $addonsCost + $taxAmount - $discountAmount;
        }


        // Add pricing data to the base data array
        $data['base_amount'] = (float) $baseAmount;
        $data['driver_cost'] = (float) ($pricing['driver_cost'] ?? 0);
        $data['distance_cost'] = (float) ($pricing['distance_cost'] ?? 0);
        $data['addons_cost'] = (float) $addonsCost;
        $data['discount_amount'] = (float) $discountAmount;
        $data['tax_amount'] = (float) $taxAmount;
        $data['total_estimated'] = (float) $totalAmount;
        $data['currency'] = 'LKR';

        // Add pricing overrides and base pricing details
        // $basePricingOverrides = $pricing['base_pricing_overrides'] ?? [];
        $extraPricingOverrides = $pricing['extra_pricing_overrides'] ?? [];

        $data['base_price_override'] = !empty($basePricingOverrides) ? $basePricingOverrides[0]['amount'] ?? null : null;
        $data['base_price_override_reason'] = !empty($basePricingOverrides) ? $basePricingOverrides[0]['reason'] ?? 'Custom pricing applied' : null;
        $data['base_price_edited_by'] = (!empty($basePricingOverrides) || !empty($extraPricingOverrides)) ? Auth::id() : null;
        $data['base_price_edited_at'] = (!empty($basePricingOverrides) || !empty($extraPricingOverrides)) ? now() : null;

        // Store override information in structured format
        $data['addon_overrides'] = $addons['addon_overrides'] ?? null;
        $data['discounts'] = $pricing['discounts'] ?? null;

        // Handle variable customizations for pricing
        $variableCustomizations = $pricing['variable_customizations'] ?? null;
        if ($variableCustomizations) {
            $data['variable_customizations'] = $variableCustomizations;
        }

        // Store original and edited totals for tracking changes
        $addonData = $addons['addon_data'] ?? [];
        $hasOverrides = !empty($basePricingOverrides) || !empty($extraPricingOverrides) || !empty($addonData) || !empty($variableCustomizations);

        if ($hasOverrides) {
            // Try to read original values from the incoming breakdown if available
            $rawBreakdown = $pricing['breakdown'] ?? null;

            $origBase = $baseAmount;
            $origAddons = $addonsCost;
            $origTotal = $totalAmount;

            // If breakdown supplies explicit original fields, prefer those
            if ($rawBreakdown) {
                // Sum original amounts from base_pricing if provided
                if (isset($rawBreakdown['base_pricing']) && is_array($rawBreakdown['base_pricing'])) {
                    $calced = 0;
                    foreach ($rawBreakdown['base_pricing'] as $bp) {
                        if (isset($bp['original_amount'])) {
                            $calced += (float) $bp['original_amount'];
                        } elseif (isset($bp['amount'])) {
                            $calced += (float) $bp['amount'];
                        }
                    }
                    $origBase = $calced;
                }

                // Use addons original_unit_price * qty when provided
                if (isset($rawBreakdown['addons']) && is_array($rawBreakdown['addons'])) {
                    $calcedAddons = 0;
                    foreach ($rawBreakdown['addons'] as $ad) {
                        $qty = isset($ad['quantity']) ? (int) $ad['quantity'] : 1;
                        if (isset($ad['original_unit_price'])) {
                            $calcedAddons += (float) $ad['original_unit_price'] * $qty;
                        } elseif (isset($ad['price'])) {
                            $calcedAddons += (float) $ad['price'] * $qty;
                        }
                    }
                    $origAddons = $calcedAddons;
                }

                // If breakdown provides an explicit original total, use it
                if (isset($rawBreakdown['original_total'])) {
                    $origTotal = (float) $rawBreakdown['original_total'];
                } elseif (isset($rawBreakdown['subtotal']) && isset($rawBreakdown['addons_total'])) {
                    $origTotal = (float) $rawBreakdown['subtotal'] + (float) $rawBreakdown['addons_total'] - (float) ($rawBreakdown['total_discount'] ?? 0);
                }
            }

            // Edited totals should reflect the values AFTER user's edits
            $editedBase = $baseAmount;
            $editedAddons = $addonsCost;
            $editedTotal = $totalAmount;

            if ($rawBreakdown) {
                // Sum edited amounts from base_pricing using `amount` fields
                if (isset($rawBreakdown['base_pricing']) && is_array($rawBreakdown['base_pricing'])) {
                    $calced = 0;
                    foreach ($rawBreakdown['base_pricing'] as $bp) {
                        $calced += (float) ($bp['amount'] ?? 0);
                    }
                    $editedBase = $calced;
                }

                if (isset($rawBreakdown['addons']) && is_array($rawBreakdown['addons'])) {
                    $calcedAddons = 0;
                    foreach ($rawBreakdown['addons'] as $ad) {
                        $qty = isset($ad['quantity']) ? (int) $ad['quantity'] : 1;
                        $unit = isset($ad['custom_price']) ? (float) $ad['custom_price'] : (float) ($ad['price'] ?? 0);
                        $calcedAddons += $unit * $qty;
                    }
                    $editedAddons = $calcedAddons;
                }

                if (isset($rawBreakdown['total'])) {
                    $editedTotal = (float) $rawBreakdown['total'];
                }
            }

            $data['original_totals'] = [
                'base_amount' => (float) $origBase,
                'addons_cost' => (float) $origAddons,
                'total' => (float) $origTotal,
                'timestamp' => now()->toISOString()
            ];

            $data['edited_totals'] = [
                'base_amount' => (float) $editedBase,
                'addons_cost' => (float) $editedAddons,
                'total' => (float) $editedTotal,
                'timestamp' => now()->toISOString()
            ];
        } else {
            // Even when no overrides, persist the current totals for analytics
            $data['original_totals'] = [
                'base_amount' => (float) $baseAmount,
                'addons_cost' => (float) $addonsCost,
                'total' => (float) $totalAmount,
                'timestamp' => now()->toISOString()
            ];

            $data['edited_totals'] = [
                'base_amount' => (float) $baseAmount,
                'addons_cost' => (float) $addonsCost,
                'total' => (float) $totalAmount,
                'timestamp' => now()->toISOString()
            ];
        }

        // Add source tracking
        $data['created_from'] = 'internal';
        $data['booking_source'] = 'dashboard';
        $data['created_by_user_id'] = Auth::id();
        $data['created_user_id'] = Auth::id();

        // Add payment details
        $data['payment_method'] = $bookingData['payment_method'] ?? null;
        $data['payment_status'] = 'pending';

        // Add corporate booking fields
        $data['is_corporate_booking'] = $bookingData['is_corporate_booking'] ?? false;
        $data['corporate_account_id'] = $bookingData['corporate_account_id'] ?? null;
        $data['cost_center'] = $bookingData['cost_center'] ?? null;
        $data['project_code'] = $bookingData['project_code'] ?? null;
        $data['employee_id'] = $bookingData['employee_id'] ?? null;

        // Add emergency contact
        $data['emergency_contact_name'] = $bookingData['emergency_contact_name'] ?? null;
        $data['emergency_contact_phone'] = $bookingData['emergency_contact_phone'] ?? null;
        $data['emergency_contact_relationship'] = $bookingData['emergency_contact_relationship'] ?? null;

        // Add insurance and safety
        $data['insurance_type'] = $bookingData['insurance_type'] ?? null;
        $data['safety_features_required'] = $bookingData['safety_features_required'] ?? null;

        // Add notification preferences
        $data['notification_sms'] = $bookingData['notification_sms'] ?? true;
        $data['notification_email'] = $bookingData['notification_email'] ?? true;
        $data['notification_whatsapp'] = $bookingData['notification_whatsapp'] ?? false;
        $data['notification_push'] = $bookingData['notification_push'] ?? true;

        // Add timestamps
        $data['booked_at'] = now();

        return array_filter($data, function ($value) {
            return $value !== null;
        });
    }

    /**
     * Determine approval priority based on booking data
     */
    private function determineApprovalPriority(array $bookingData): string
    {
        $state = $bookingData['state'] ?? [];
        $pricing = $bookingData['pricing'] ?? [];

        // High priority conditions
        if (
            isset($pricing['base_price_override']) ||
            (!empty($state['concurrent_assignments'])) ||
            (isset($pricing['discount_value']) && $pricing['discount_value'] > 50) ||
            (!empty($state['overrideReasons']) && count($state['overrideReasons']) > 2)
        ) {
            return 'high';
        }

        // Urgent priority conditions (very specific overrides)
        if (
            (isset($pricing['discount_value']) && $pricing['discount_value'] > 80) ||
            (!empty($state['overrideReasons']) && count($state['overrideReasons']) > 5)
        ) {
            return 'urgent';
        }

        return 'normal';
    }

    /**
     * Create booking add-on records
     */
    private function createBookingAddons(Booking $booking, array $addonsData): void
    {
        if (empty($addonsData['selected_addons'])) {
            return;
        }

        $addonData = $addonsData['addon_data'] ?? [];

        // Add new addons
        foreach ($addonsData['selected_addons'] as $addonItem) {

            $addonId = $addonItem['addon_id'] ?? $addonItem['id'] ?? null;
            $quantity = $addonItem['quantity'] ?? 1;
            $customPrice = $addonItem['custom_price'] ?? null;

            if (!$addonId) {
                Log::warning("Invalid addon data when creating booking addons", ['addon_item' => $addonItem]);
                continue;
            }

            $addon = VehicleAddon::find($addonId);
            if (!$addon) {
                continue;
            }

            $quantity = $addonData[$addonId]['quantity'] ?? 1;
            $customPrice = $addonData[$addonId]['custom_price'] ?? null;
            $rate = $customPrice ?? (float) $addon->amount;

            BookingAddon::create([
                'id' => Str::uuid(),
                'booking_id' => $booking->id,
                'addon_id' => $addonId,
                'qty' => (int) $quantity,
                'rate' => $rate,
                'amount' => $rate * (int) $quantity,
                'label' => $addon->name,
                'created_user_id' => Auth::id(),
            ]);
        }
    }

    /**
     * Send approval request notifications
     */
    private function notifyApprovalRequested(Booking $booking, BookingApproval $approval): void
    {
        // TODO: Implement notification logic
        // This would typically:
        // 1. Find appropriate managers/approvers
        // 2. Send email notifications
        // 3. Create in-app notifications
        // 4. Log the notification attempt

        Log::info('Booking approval requested', [
            'booking_id' => $booking->id,
            'booking_number' => $booking->booking_number,
            'approval_id' => $approval->id,
            'priority' => $approval->priority,
            'requested_by' => $approval->requested_by,
        ]);
    }

    /**
     * Send booking confirmation notifications
     */
    private function sendBookingConfirmation(Booking $booking): void
    {
        // TODO: Implement confirmation notification logic
        // This would typically:
        // 1. Send confirmation email to customer
        // 2. Send notification to driver
        // 3. Create calendar events
        // 4. Update vehicle/driver schedules
        // 5. Log the confirmation

        Log::info('Booking confirmed', [
            'booking_id' => $booking->id,
            'booking_number' => $booking->booking_number,
            'confirmation_number' => $booking->confirmation_number,
            'customer_id' => $booking->customer_id,
            'vehicle_id' => $booking->vehicle_id,
            'driver_id' => $booking->driver_id,
        ]);
    }

    /**
     * Get comprehensive booking data for editing
     */
    public function getComprehensiveBookingData(string $bookingId): array
    {
        $booking = Booking::with([
            'customer',
            'vehicle.vehicleGroup',
            'driver',
            'serviceType',
            'vehicleGroup',
            'bookingAddons.addon',
            'approvals',
            'variableCustomizations',
        ])->findOrFail($bookingId);

        return [
            'booking' => $booking,
            'service_details' => [
                'service_type' => $booking->serviceType->id ?? $booking->service_type_id,
                'from_date' => $booking->from_date,
                'to_date' => $booking->to_date,
                'from_time' => $booking->from_time,
                'to_time' => $booking->to_time,
                'pickup_location' => [
                    'address' => $booking->pickup_location ? $booking->pickup_location['address'] : null,
                    'latitude' => $booking->pickup_latitude,
                    'longitude' => $booking->pickup_longitude
                ],
                'dropoff_location' => [
                    'address' => $booking->dropoff_location ? $booking->dropoff_location['address'] : null,
                    'latitude' => $booking->dropoff_latitude,
                    'longitude' => $booking->dropoff_longitude
                ]
            ],
            'vehicle_driver' => [
                'vehicle_group_id' => $booking->vehicle_group_id,
                'vehicle_id' => $booking->vehicle_id,
                'driver_id' => $booking->driver_id,
                'is_self_driven' => $booking->is_self_driven,
                'needs_specific_driver' => !is_null($booking->driver_id),
                'vehicle_id' => $booking->vehicle_id
            ],
            'customer_details' => [
                'customer_id' => $booking->customer_id,
                'first_name' => $booking->customer?->user?->first_name,
                'last_name' => $booking->customer?->user?->last_name,
                'full_name' => $booking->customer?->user?->first_name . ' ' . $booking->customer?->user?->last_name,
                'phone' => $booking->customer?->user?->phone,
                'email' => $booking->customer?->user?->email
            ],
            'addons' => [
                'selected_addons' => $booking->bookingAddons->pluck('addon_id')->toArray(),
                'addon_data' => $booking->bookingAddons->mapWithKeys(function ($bookingAddon) {
                    return [
                        $bookingAddon->addon_id => [
                            'quantity' => (int) ($bookingAddon->qty ?? 1),
                            'custom_price' => !is_null($bookingAddon->rate) && isset($bookingAddon->addon->amount)
                                ? ((float) $bookingAddon->rate !== (float) $bookingAddon->addon->amount ? (float) $bookingAddon->rate : null)
                                : (!is_null($bookingAddon->rate) ? (float) $bookingAddon->rate : null),
                            'total_price' => (float) ($bookingAddon->amount ?? 0),
                            'original_price' => (float) ($bookingAddon->addon->amount ?? 0),
                            'is_customized' => !is_null($bookingAddon->rate) && isset($bookingAddon->addon->amount)
                                ? ((float) $bookingAddon->rate !== (float) $bookingAddon->addon->amount)
                                : false
                        ]
                    ];
                })->toArray()
            ],
            'pricing' => [
                'base_amount' => $booking->base_amount,
                'addon_total' => $booking->addons_cost,
                'discount_total' => $booking->discount_amount,
                'tax_amount' => $booking->tax_amount,
                'total_amount' => $booking->total_estimated,
                'base_price_override' => $booking->base_price_override,
                'base_price_override_reason' => $booking->base_price_override_reason,
                'has_custom_base_price' => !is_null($booking->base_price_override),
                'is_pricing_locked' => !is_null($booking->base_price_override) || $booking->bookingAddons->whereNotNull('rate')->count() > 0,
                // 'base_pricing_overrides' => !is_null($booking->base_price_override) ? [
                //     [
                //         'amount' => $booking->base_price_override,
                //         'reason' => $booking->base_price_override_reason ?? 'Custom pricing applied'
                //     ]
                // ] : [],
                'breakdown' => [
                    'base_pricing' => !is_null($booking->base_price_override) ? [
                        [
                            'component' => 'custom_base_rate',
                            'description' => 'Custom Base Rate',
                            'amount' => $booking->base_price_override,
                            'original_amount' => $booking->base_amount,
                            'is_custom' => true
                        ]
                    ] : [
                        [
                            'component' => 'base_rate',
                            'description' => 'Base Rate',
                            'amount' => $booking->base_amount,
                            'original_amount' => $booking->base_amount,
                            'is_custom' => false
                        ]
                    ],
                    'addons' => $booking->bookingAddons->map(function ($bookingAddon) {
                        return [
                            'id' => $bookingAddon->addon_id,
                            'name' => $bookingAddon->label,
                            'quantity' => $bookingAddon->qty,
                            'price' => $bookingAddon->rate,
                            'custom_price' => $bookingAddon->addon ?
                                ($bookingAddon->rate != $bookingAddon->addon->amount ? $bookingAddon->rate : null) :
                                null,
                            'original_unit_price' => $bookingAddon->addon ? $bookingAddon->addon->amount : $bookingAddon->rate,
                            'total_price' => $bookingAddon->amount,
                            'is_custom' => $bookingAddon->addon ?
                                ($bookingAddon->rate != $bookingAddon->addon->amount) :
                                false
                        ];
                    })->toArray(),
                    'subtotal' => !is_null($booking->base_price_override) ? $booking->base_price_override : $booking->base_amount,
                    'addons_total' => $booking->addons_cost,
                    'discount_total' => $booking->discount_amount,
                    'tax_amount' => $booking->tax_amount,
                    'total' => $booking->total_estimated
                ],
                'calculation_params' => [
                    'vehicle_group_id' => $booking->vehicle_group_id,
                    'service_type' => $booking->service_type_id,
                    'from_date' => $booking->from_date,
                    'to_date' => $booking->to_date,
                    'pickup_location' => [
                        'latitude' => $booking->pickup_latitude,
                        'longitude' => $booking->pickup_longitude
                    ],
                    'dropoff_location' => [
                        'latitude' => $booking->dropoff_latitude,
                        'longitude' => $booking->dropoff_longitude
                    ]
                ],
                'variable_customizations' => BookingVariableCustomization::where('booking_id', $bookingId)
                    ->orderBy('updated_at', 'desc')
                    ->get()
                    ->groupBy('variable_name')
                    ->map(function ($customizations) {
                        // Return the most recent customization for each variable
                        $latest = $customizations->first();
                        return [
                            'id' => $latest->id,
                            'variable_name' => $latest->variable_name,
                            'variable_type' => $latest->variable_type,
                            'display_name' => $latest->variable_name, // Add for frontend consistency
                            'category' => 'base', // Default category
                            'unit' => 'LKR', // Default unit
                            'original_value' => $latest->original_value,
                            'custom_value' => $latest->custom_value,
                            'customization_reason' => $latest->customization_reason,
                            'context' => $latest->context,
                        ];
                    })
                    ->values()
                    ->toArray()
            ],
            'state' => [
                'status' => $booking->status,
                'override_reasons' => $booking->override_reasons,
                'concurrent_assignments' => $booking->concurrent_assignments,
                'approval_status' => $booking->approval_status,
                'requires_approval' => $booking->requiresApproval()
            ]
        ];
    }

    /**
     * Track booking changes for analytics and approval requirements
     */
    public function trackBookingChanges(Booking $originalBooking, array $newData, string $userId): array
    {
        $changes = [
            'user_id' => $userId,
            'timestamp' => now(),
            'original_status' => $originalBooking->status,
            'changes' => [],
            'significant_changes' => [],
            'pricing_changed' => false,
            'vehicle_changed' => false,
            'driver_changed' => false,
            'dates_changed' => false,
            'approval_required' => false
        ];

        // Check service details changes
        if (isset($newData['service_details'])) {
            $serviceDetails = $newData['service_details'];

            if (
                $originalBooking->from_date !== $serviceDetails['from_date'] ||
                $originalBooking->to_date !== $serviceDetails['to_date']
            ) {
                $changes['dates_changed'] = true;
                $changes['significant_changes'][] = 'booking_dates';
                $changes['changes']['dates'] = [
                    'from' => ['old' => $originalBooking->from_date, 'new' => $serviceDetails['from_date']],
                    'to' => ['old' => $originalBooking->to_date, 'new' => $serviceDetails['to_date']]
                ];
            }

            $newPickupAddress = $serviceDetails['pickup_location']['address'] ?? '';
            $oldPickupAddress = is_array($originalBooking->pickup_location)
                ? ($originalBooking->pickup_location['address'] ?? '')
                : (string) $originalBooking->pickup_location;

            if ($oldPickupAddress !== $newPickupAddress) {
                $changes['significant_changes'][] = 'pickup_location';
                $changes['changes']['pickup_location'] = [
                    'old' => $oldPickupAddress,
                    'new' => $newPickupAddress
                ];
            }
        }

        // Check vehicle/driver changes
        if (isset($newData['vehicle_driver'])) {
            $vehicleDriver = $newData['vehicle_driver'];

            $newVehicleId = $vehicleDriver['vehicle_id'] ?? $vehicleDriver['vehicle_id'] ?? null;
            if ($originalBooking->vehicle_id !== $newVehicleId) {
                $changes['vehicle_changed'] = true;
                $changes['significant_changes'][] = 'vehicle_assignment';
                $changes['changes']['vehicle'] = [
                    'old' => $originalBooking->vehicle_id,
                    'new' => $newVehicleId
                ];
            }

            if ($originalBooking->driver_id !== $vehicleDriver['driver_id']) {
                $changes['driver_changed'] = true;
                $changes['significant_changes'][] = 'driver_assignment';
                $changes['changes']['driver'] = [
                    'old' => $originalBooking->driver_id,
                    'new' => $vehicleDriver['driver_id']
                ];
            }
        }


        // Determine if approval is required
        $changes['approval_required'] =
            $changes['pricing_changed'] ||
            $changes['vehicle_changed'] ||
            $changes['driver_changed'] ||
            count($changes['significant_changes']) > 2;

        return $changes;
    }



    /**
     * Check if booking update requires re-approval
     */
    public function checkReApprovalRequired(array $changeAnalytics): bool
    {
        return $changeAnalytics['approval_required'] ||
            $changeAnalytics['pricing_changed'] ||
            count($changeAnalytics['significant_changes']) > 1;
    }

    /**
     * Create approval record for booking changes
     */
    public function createApprovalRecord(Booking $booking, array $changeAnalytics): void
    {
        BookingApproval::create([
            'id' => Str::uuid(),
            'booking_id' => $booking->id,
            'status' => 'pending',
            'priority' => $this->determineEditApprovalPriority($changeAnalytics),
            'override_reasons' => json_encode($changeAnalytics['significant_changes']),
            'requested_by' => Auth::id(),
            'requested_at' => now(),
            'change_summary' => json_encode($changeAnalytics['changes']),
            'approval_type' => 'modification'
        ]);
    }

    /**
     * Track analytics events
     */
    public function trackAnalytics(string $event, array $data): void
    {
        Log::info("Booking Analytics: {$event}", $data);

        // Here you could also send to analytics service, database table, etc.
        // For now, just logging for comprehensive tracking
    }

    /**
     * Get booking edit history
     */
    public function getBookingEditHistory(string $bookingId): array
    {
        $booking = Booking::findOrFail($bookingId);

        // Get approval records
        $approvals = BookingApproval::where('booking_id', $bookingId)
            ->with('requester', 'approver')
            ->orderBy('created_at', 'desc')
            ->get();

        // Get activity logs if using Spatie Activity Log
        $activities = [];
        if (class_exists('\Spatie\Activitylog\Models\Activity')) {
            $activities = \Spatie\Activitylog\Models\Activity::where('subject_type', Booking::class)
                ->where('subject_id', $bookingId)
                ->with('causer')
                ->orderBy('created_at', 'desc')
                ->get();
        }

        return [
            'booking_id' => $bookingId,
            'current_status' => $booking->status,
            'approvals' => $approvals,
            'activities' => $activities,
            'last_modified' => [
                'by' => $booking->last_modified_by,
                'at' => $booking->last_modified_at
            ]
        ];
    }

    /**
     * Clone booking for creating similar booking
     */
    public function cloneBooking(string $bookingId, string $userId): Booking
    {
        $originalBooking = Booking::with(['bookingAddons'])->findOrFail($bookingId);

        $clonedData = $originalBooking->toArray();

        // Remove unique fields and set as draft
        unset($clonedData['id'], $clonedData['booking_number'], $clonedData['confirmation_number']);
        $clonedData['status'] = 'draft';
        $clonedData['created_by'] = $userId;
        $clonedData['created_at'] = now();
        $clonedData['booking_date'] = now();

        // Clear approval-related fields
        $clonedData['approval_status'] = null;
        $clonedData['approval_requested_at'] = null;
        $clonedData['approval_requested_by'] = null;
        $clonedData['approved_by'] = null;
        $clonedData['approved_at'] = null;

        $clonedBooking = Booking::create($clonedData);

        // Clone addons
        foreach ($originalBooking->bookingAddons as $addon) {
            BookingAddon::create([
                'id' => Str::uuid(),
                'booking_id' => $clonedBooking->id,
                'addon_id' => $addon->addon_id,
                'quantity' => $addon->quantity,
                'price' => $addon->price,
                'total_price' => $addon->total_price
            ]);
        }

        return $clonedBooking->fresh();
    }

    /**
     * Get booking analytics
     */
    public function getBookingAnalytics(string $bookingId): array
    {
        $booking = Booking::findOrFail($bookingId);

        return [
            'booking_id' => $bookingId,
            'created_at' => $booking->created_at,
            'status_history' => $this->getStatusHistory($booking),
            'edit_count' => $this->getEditCount($booking),
            'approval_cycle_time' => $this->getApprovalCycleTime($booking),
            'pricing_changes' => $this->getPricingChanges($booking),
            'vehicle_changes' => $this->getVehicleChanges($booking)
        ];
    }

    /**
     * Store change log for audit trail
     */
    private function storeChangeLog(Booking $booking, array $changeAnalytics): void
    {
        // Store in a dedicated change log table or use activity log
        Log::info('Booking Change Log', [
            'booking_id' => $booking->id,
            'changes' => $changeAnalytics,
            'user_id' => Auth::id(),
            'timestamp' => now()
        ]);
    }


    /**
     * Determine approval priority based on changes for edits
     */
    private function determineEditApprovalPriority(array $changeAnalytics): string
    {
        if (
            isset($changeAnalytics['significant_changes']) &&
            is_array($changeAnalytics['significant_changes']) &&
            count($changeAnalytics['significant_changes']) > 0
        ) {
            return 'high';
        }
        return 'normal';
    }

    /**
     * Get booking status history
     */
    private function getStatusHistory(Booking $booking): array
    {
        // Implementation depends on your activity log setup
        return [
            'current_status' => $booking->status,
            'status_changes' => [] // Fetch from activity log
        ];
    }

    /**
     * Get edit count for booking
     */
    private function getEditCount(Booking $booking): int
    {
        // Count edits from activity log or dedicated tracking
        return 0; // Placeholder
    }

    /**
     * Get approval cycle time
     */
    private function getApprovalCycleTime(Booking $booking): ?int
    {
        if ($booking->approval_requested_at && $booking->approved_at) {
            return $booking->approval_requested_at->diffInMinutes($booking->approved_at);
        }
        return null;
    }

    /**
     * Get pricing changes history
     */
    private function getPricingChanges(Booking $booking): array
    {
        // Fetch from change log or activity log
        return [];
    }

    /**
     * Get vehicle changes history
     */
    private function getVehicleChanges(Booking $booking): array
    {
        // Fetch from change log or activity log
        return [];
    }

    // ========================
    // BOOKING LIST MANAGEMENT
    // ========================

    /**
     * Get filtered bookings with pagination
     */
    public function getFilteredBookings(array $filters): array
    {
        $query = Booking::with([
            'customer',
            'customer.user:id,first_name,last_name,email,phone',
            'vehicle.vehicleGroup',
            'driver.user:id,first_name,last_name,email,phone',
            'serviceType:id,name',
            'createdBy:id,first_name,last_name',
            'vehicleAssignments' => function ($q) {
                $q->where('status', '!=', 'cancelled')
                    ->orderBy('created_at', 'desc')
                    ->limit(1);
            },
            'driverAssignments' => function ($q) {
                $q->where('status', '!=', 'cancelled')
                    ->orderBy('created_at', 'desc')
                    ->limit(1);
            }
        ]);

        // Apply filters
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('booking_id', 'like', "%{$search}%")
                    ->orWhere('reference_number', 'like', "%{$search}%")
                    ->orWhereHas('customer', function ($customerQuery) use ($search) {
                        $customerQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%");
                    });
            });
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['service_type'])) {
            $query->where('service_type_id', $filters['service_type']);
        }

        if (!empty($filters['customer_id'])) {
            $query->where('customer_id', $filters['customer_id']);
        }

        if (!empty($filters['vehicle_group_id'])) {
            $query->whereHas('vehicle', function ($q) use ($filters) {
                $q->where('vehicle_group_id', $filters['vehicle_group_id']);
            });
        }

        if (!empty($filters['vehicle_id'])) {
            $query->where('vehicle_id', $filters['vehicle_id']);
        }

        if (!empty($filters['driver_id'])) {
            $query->where('driver_id', $filters['driver_id']);
        }

        if (!empty($filters['date_from'])) {
            $query->whereDate('from_date', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('to_date', '<=', $filters['date_to']);
        }

        if (isset($filters['requires_approval'])) {
            $query->where('requires_approval', $filters['requires_approval']);
        }

        if (isset($filters['has_overrides'])) {
            if ($filters['has_overrides']) {
                $query->where(function ($q) {
                    $q->whereNotNull('base_price_override')
                        ->orWhereNotNull('addon_overrides')
                        ->orWhereNotNull('custom_pricing');
                });
            } else {
                $query->whereNull('base_price_override')
                    ->whereNull('addon_overrides')
                    ->whereNull('custom_pricing');
            }
        }

        if (!empty($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }

        if (!empty($filters['assigned_to'])) {
            $query->where('assigned_to', $filters['assigned_to']);
        }

        if (!empty($filters['created_by'])) {
            $query->where('created_by', $filters['created_by']);
        }

        // Apply sorting
        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortDirection = $filters['sort_direction'] ?? 'desc';
        $query->orderBy($sortBy, $sortDirection);

        // Paginate
        $perPage = $filters['per_page'] ?? 15;
        $paginated = $query->paginate($perPage);

        return [
            'bookings' => $paginated->items(),
            'pagination' => [
                'current_page' => $paginated->currentPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'last_page' => $paginated->lastPage(),
                'from' => $paginated->firstItem(),
                'to' => $paginated->lastItem(),
            ],
            'filters_applied' => $filters,
            'summary' => $this->getBookingsSummary($query)
        ];
    }

    /**
     * Get booking details for display
     */
    // app/Services/BookingFlowService.php

    public function getBookingDetails(string $bookingId): array
    {
        $booking = Booking::with([
            'customer.user',
            'vehicle.vehicleGroup',
            'driver',
            'serviceType',
            'vehicleGroup',
            'bookingAddons.addon',
            'approvals.approver',
        ])->findOrFail($bookingId);

        // ---- helpers / safe getters
        $pricingSnapshot = (array) ($booking->pricing_snapshot ?? []);
        $summary = (array) ($pricingSnapshot['summary'] ?? []);
        $addonsPricing = (array) ($pricingSnapshot['addons_pricing'] ?? []);
        $discountSummary = (array) ($pricingSnapshot['discount_summary'] ?? []);
        $detailed = (array) ($pricingSnapshot['detailed_breakdown'] ?? []);
        $duration = (array) ($pricingSnapshot['duration'] ?? []);
        $customizations = (array) ($pricingSnapshot['applied_customizations'] ?? []);
        $finalBreakdown = (array) ($pricingSnapshot['final_breakdown'] ?? []);

        // fallback currency
        $currency = $summary['currency'] ?? 'LKR';

        // -------- BEFORE/AFTER (Base, Addons, Totals)
        $beforeBase = (float) ($summary['subtotal_without_customizations']
            ?? ($pricingSnapshot['base_pricing']['total_amount_without_customizations'] ?? 0));
        $afterBase = (float) ($summary['subtotal']
            ?? ($pricingSnapshot['base_pricing']['total_amount'] ?? 0));

        $beforeAdd = (float) ($addonsPricing['addons_total_without_customizations'] ?? 0);
        $afterAdd = (float) ($addonsPricing['addons_total'] ?? ($summary['addons_total'] ?? 0));

        $beforeTot = (float) ($summary['total_without_customizations']
            ?? ($detailed['price_before_customizations']['subtotal'] ?? ($beforeBase + $beforeAdd)));
        $afterTot = (float) ($summary['total']
            ?? ($detailed['price_after_customizations']['subtotal'] ?? ($afterBase + $afterAdd)));

        $discountAmt = (float) ($discountSummary['total_discount_amount'] ?? 0);
        $finalAmt = (float) ($finalBreakdown['final_amount'] ?? max(0, $afterTot - $discountAmt));

        $pct = function (float $before, float $after): ?float {
            if ($before == 0.0)
                return null;
            return round((($after - $before) / $before) * 100, 2);
        };

        $customizationSummary = [
            'base' => [
                'before' => $beforeBase,
                'after' => $afterBase,
                'delta' => $afterBase - $beforeBase,
                'delta_percent' => $pct($beforeBase, $afterBase),
            ],
            'addons' => [
                'before' => $beforeAdd,
                'after' => $afterAdd,
                'delta' => $afterAdd - $beforeAdd,
                'delta_percent' => $pct($beforeAdd, $afterAdd),
            ],
            'totals' => [
                'before' => $beforeTot,
                'after' => $afterTot,
                'delta' => $afterTot - $beforeTot,
                'delta_percent' => $pct($beforeTot, $afterTot),
            ],
            'discounts' => [
                'total_discount_amount' => $discountAmt,
                'discount_count' => (int) ($discountSummary['discount_count'] ?? 0),
                'breakdown' => (array) ($discountSummary['breakdown'] ?? []),
            ],
            'final' => [
                'final_amount' => $finalAmt,
                'requires_approval' => (bool) ($pricingSnapshot['requires_approval'] ?? $booking->requires_approval ?? false),
            ],
            'signals' => [
                'base_overrides' => (bool) ($detailed['customizations_applied']['base_overrides'] ?? ($afterBase !== $beforeBase)),
                'addon_overrides' => (bool) ($detailed['customizations_applied']['addon_overrides'] ?? ($afterAdd !== $beforeAdd)),
                'discount_applied' => (bool) ($discountAmt > 0),
            ],
        ];

        // normalize addons (prefer snapshot → fallback to relation)
        $addons = [];
        if (!empty($addonsPricing['addons'])) {
            foreach ($addonsPricing['addons'] as $a) {
                $addons[] = [
                    'id' => $a['id'] ?? null,
                    'name' => $a['name'] ?? 'Addon',
                    'unit_price' => (float) ($a['unit_price'] ?? 0),
                    'quantity' => (int) ($a['quantity'] ?? 1),
                    'billing_type' => $a['billing_type'] ?? null,
                    'multiplier' => $a['multiplier'] ?? null,
                    'duration_info' => $a['duration_info'] ?? null,
                    'total_price' => (float) ($a['total_price'] ?? 0),
                    'is_custom_price' => (bool) ($a['is_custom_price'] ?? false),
                    'original_price' => isset($a['original_price']) ? (float) $a['original_price'] : null,
                    'calculation_detail' => $a['calculation_detail'] ?? null,
                ];
            }
        } else {
            $addons = $booking->bookingAddons->map(function ($ba) {
                $orig = (float) ($ba->addon->amount ?? 0);
                $rate = (float) ($ba->rate ?? $orig);
                return [
                    'id' => $ba->addon_id,
                    'name' => $ba->addon->name ?? 'Addon',
                    'unit_price' => $rate,
                    'quantity' => (int) ($ba->qty ?? 1),
                    'billing_type' => $ba->billing_type ?? null,
                    'multiplier' => null,
                    'duration_info' => null,
                    'total_price' => (float) ($ba->amount ?? ($rate * max(1, (int) $ba->qty))),
                    'is_custom_price' => $ba->rate && $rate !== $orig,
                    'original_price' => $rate !== $orig ? $orig : null,
                    'calculation_detail' => null,
                ];
            })->values()->toArray();
        }

        // discounts (DB column) + snapshot summary merged for the UI
        $dbDiscounts = (array) ($booking->discounts ?? []);
        $snapshotDiscounts = (array) ($discountSummary['breakdown'] ?? []);
        $discountsMerged = array_values(array_filter(array_merge(
            array_map(function ($d) {
                return [
                    'name' => $d['name'] ?? 'Discount',
                    'type' => $d['type'] ?? 'manual',
                    'method' => $d['method'] ?? null,
                    'value' => isset($d['value']) ? (float) $d['value'] : null,
                    'amount' => isset($d['amount']) ? (float) $d['amount'] : null,
                    'reason' => $d['reason'] ?? ($d['description'] ?? null),
                    'applied_by' => $d['applied_by'] ?? null,
                    'applied_at' => $d['applied_at'] ?? null,
                ];
            }, $dbDiscounts),
            array_map(function ($d) {
                return [
                    'name' => $d['name'] ?? 'Discount',
                    'type' => $d['type'] ?? 'manual',
                    'method' => $d['method'] ?? null,
                    'value' => isset($d['value']) ? (float) $d['value'] : null,
                    'amount' => isset($d['amount']) ? (float) $d['amount'] : null,
                    'reason' => $d['description'] ?? null,
                    'applied_by' => null,
                    'applied_at' => null,
                ];
            }, $snapshotDiscounts)
        ), fn($x) => $x !== null));

        // approval
        $firstApproval = $booking->approvals->first();
        $approval = $firstApproval ? [
            'status' => $booking->approval_status ?? 'pending',
            'priority' => $booking->approval_priority ?? 'normal',
            'requested_by' => $firstApproval->created_by_name ?? ($booking->approval_requested_by ?? 'N/A'),
            'requested_at' => optional($firstApproval->created_at)->toISOString() ?? optional($booking->approval_requested_at)->toISOString(),
            'reviewed_by' => $firstApproval->approved_by_name ?? null,
            'reviewed_at' => optional($firstApproval->approved_at)->toISOString(),
            'notes' => $firstApproval->notes ?? null,
            'justification' => $firstApproval->justification ?? ($booking->approval_justification ?? null),
            'requires_approval' => (bool) ($booking->requires_approval ?? false),
            'approval_reason' => $booking->approval_reason ?? null,
        ] : (($booking->requires_approval ?? false) ? [
                'status' => $booking->approval_status ?? 'pending',
                'priority' => $booking->approval_priority ?? 'normal',
                'requested_by' => (string) ($booking->approval_requested_by ?? ''),
                'requested_at' => optional($booking->approval_requested_at)->toISOString(),
                'reviewed_by' => null,
                'reviewed_at' => null,
                'notes' => null,
                'justification' => $booking->approval_justification ?? null,
                'requires_approval' => true,
                'approval_reason' => $booking->approval_reason ?? null,
            ] : null);

        // activity log (Spatie\Activitylog) if available
        $activityLog = [];
        if (class_exists(\Spatie\Activitylog\Models\Activity::class)) {
            $Activity = \Spatie\Activitylog\Models\Activity::class;
            $activityLog = $Activity::query()
                ->where('subject_type', Booking::class)
                ->where('subject_id', $booking->id)
                ->orderByDesc('created_at')
                ->take(100)
                ->get()
                ->map(function ($a) {
                    return [
                        'id' => (string) $a->id,
                        'action' => (string) $a->event,
                        'description' => (string) ($a->description ?? ''),
                        'user_name' => optional($a->causer)->name ?? 'System',
                        'created_at' => optional($a->created_at)->toISOString(),
                        'metadata' => $a->properties ?? [],
                    ];
                })->toArray();
        }

        // edit history (example via activity log “updated”)
        $editHistory = array_values(array_filter(array_map(function ($item) {
            if (($item['action'] ?? '') !== 'updated')
                return null;
            return [
                'id' => $item['id'],
                'changes' => $item['metadata']['attributes'] ?? [],
                'reason' => $item['metadata']['reason'] ?? null,
                'edited_by' => $item['user_name'] ?? 'System',
                'edited_at' => $item['created_at'] ?? null,
            ];
        }, $activityLog)));

        // files/attachments (stub)
        $files = [];

        // pricing block
        $pricing = [
            'base_amount' => (float) ($summary['subtotal'] ?? $booking->base_amount ?? 0),
            'addons_cost' => (float) ($summary['addons_total'] ?? $booking->addons_cost ?? 0),
            'discount_amount' => (float) ($discountSummary['total_discount_amount'] ?? $booking->discount_amount ?? 0),
            'tax_amount' => (float) ($booking->tax_amount ?? 0),
            'total_amount' => (float) ($finalAmt ?: ($summary['total'] ?? $booking->total_estimated ?? 0)),
            'currency' => $currency,
            'exchange_rate' => (float) ($summary['exchange_rate'] ?? 1),
            'is_pricing_locked' => (bool) ($booking->has_overrides ?? false),
            'base_price_override' => isset($booking->base_price_override) ? (float) $booking->base_price_override : null,
            'base_price_override_reason' => $booking->base_price_override_reason ?? null,
            'breakdown' => [
                'base_pricing' => (array) ($pricingSnapshot['base_pricing']['breakdown'] ?? []),
                'extra_pricing' => [],
            ],
            'detailed_breakdown' => $detailed,
            'discount_summary' => $discountSummary,
            'duration' => $duration,
            'addons_totals' => [
                'with_custom' => (float) ($addonsPricing['addons_total'] ?? 0),
                'without_custom' => (float) ($addonsPricing['addons_total_without_customizations'] ?? 0),
            ],
            // 👇 NEW: easy-for-approver summary (base + addons + totals + discount + final)
            'customization_summary' => $customizationSummary,
        ];

        // service details
        $serviceDetails = [
            'service_type' => $booking->serviceType?->name ?? 'Unknown Service',
            'from_date' => optional($booking->from_date)->toISOString() ?? (string) $booking->from_date,
            'to_date' => optional($booking->to_date)->toISOString() ?? (string) $booking->to_date,
            'from_time' => property_exists($booking, 'from_time') ? (string) $booking->from_time : null,
            'to_time' => property_exists($booking, 'to_time') ? (string) $booking->to_time : null,
            'pickup_location' => [
                'address' => $booking->pickup_location['address'] ?? '',
                'latitude' => $booking->pickup_location['latitude'] ?? null,
                'longitude' => $booking->pickup_location['longitude'] ?? null,
                'place_id' => $booking->pickup_location['place_id'] ?? null,
            ],
            'dropoff_location' => $booking->dropoff_location ? [
                'address' => $booking->dropoff_location['address'] ?? '',
                'latitude' => $booking->dropoff_location['latitude'] ?? null,
                'longitude' => $booking->dropoff_location['longitude'] ?? null,
                'place_id' => $booking->dropoff_location['place_id'] ?? null,
            ] : null,
        ];

        // vehicle / driver info
        $vehicleDriver = [
            'vehicle_group_name' => $booking->vehicleGroup?->name ?? 'Unknown Group',
            'vehicle_name' => $booking->vehicle?->name ?? null,
            'vehicle_plate' => $booking->vehicle?->plate_number ?? null,
            'driver_name' => $booking->driver ? ($booking->driver->first_name . ' ' . $booking->driver->last_name) : null,
            'driver_license' => $booking->driver?->license_number ?? null,
            'is_self_driven' => (bool) ($booking->is_self_driven ?? false),
        ];

        // customer
        $customer = [
            'id' => (string) $booking->customer_id,
            'name' => trim(($booking->customer?->user?->first_name ?? '') . ' ' . ($booking->customer?->user?->last_name ?? '')) ?: ($booking->customer?->user?->name ?? 'Customer'),
            'email' => $booking->customer?->user?->email ?? '',
            'phone' => $booking->customer?->user?->phone ?? '',
            'code' => $booking->customer?->code ?? null,
        ];

        $overrideReasons = array_values((array) ($booking->override_reasons ?? []));
        $reviewNotes = (array) ($booking->review_notes ?? []);

        return [
            'id' => (string) $booking->id,
            'booking_number' => $booking->booking_number ?? Booking::generateBookingNumber(),
            'status' => (string) ($booking->status ?? 'pending'),
            'workflow_step' => (string) ($booking->workflow_step ?? 'pending_approval'),
            'requires_approval' => (bool) ($booking->requires_approval ?? false),
            'created_at' => optional($booking->created_at)->toISOString(),
            'updated_at' => optional($booking->updated_at)->toISOString(),

            'customer' => $customer,
            'service_details' => $serviceDetails,
            'vehicle_driver' => $vehicleDriver,

            'pricing' => $pricing,
            'addons' => $addons,
            'discounts' => $discountsMerged,
            'customizations' => $customizations,

            'approval' => $approval,
            'override_reasons' => $overrideReasons,
            'review_notes' => $reviewNotes,

            'activity_log' => $activityLog,
            'edit_history' => $editHistory,
            'assignment_history' => $this->getAssignmentHistory($bookingId),
            'files' => $files,

            'meta' => [
                'base_currency' => $summary['currency'] ?? 'LKR',
                'exchange_rate' => (float) ($summary['exchange_rate'] ?? 1),
            ],
        ];
    }



    /**
     * Delete or cancel booking
     */
    public function deleteBooking(string $bookingId, string $userId): array
    {
        $booking = Booking::findOrFail($bookingId);

        // Check if booking can be deleted or should be cancelled
        $canDelete = in_array($booking->status, ['draft', 'pending_approval']);

        if ($canDelete) {
            $booking->delete();
            return [
                'deleted' => true,
                'cancelled' => false,
                'message' => 'Booking deleted successfully'
            ];
        } else {
            $booking->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => $userId,
                'cancellation_reason' => 'Cancelled via admin interface'
            ]);

            return [
                'deleted' => false,
                'cancelled' => true,
                'message' => 'Booking cancelled successfully'
            ];
        }
    }

    /**
     * Perform bulk operations on bookings
     */
    public function bulkOperations(string $operation, array $bookingIds, array $data, string $userId, ?string $reason = null): array
    {
        $results = [
            'success' => [],
            'failed' => [],
            'operation' => $operation
        ];

        foreach ($bookingIds as $bookingId) {
            try {
                switch ($operation) {
                    case 'delete':
                        $result = $this->deleteBooking($bookingId, $userId);
                        $results['success'][] = [
                            'booking_id' => $bookingId,
                            'result' => $result
                        ];
                        break;

                    case 'approve':
                        $this->approveBooking($bookingId, $userId, $reason);
                        $results['success'][] = [
                            'booking_id' => $bookingId,
                            'result' => 'approved'
                        ];
                        break;

                    case 'reject':
                        $this->rejectBooking($bookingId, $userId, $reason);
                        $results['success'][] = [
                            'booking_id' => $bookingId,
                            'result' => 'rejected'
                        ];
                        break;

                    case 'assign_vehicle':
                        if (empty($data['vehicle_id'])) {
                            throw new \Exception('Vehicle ID required');
                        }
                        $this->assignVehicle($bookingId, $data['vehicle_id'], $userId);
                        $results['success'][] = [
                            'booking_id' => $bookingId,
                            'result' => 'vehicle_assigned'
                        ];
                        break;

                    case 'assign_driver':
                        if (empty($data['driver_id'])) {
                            throw new \Exception('Driver ID required');
                        }
                        $this->assignDriver($bookingId, $data['driver_id'], $userId);
                        $results['success'][] = [
                            'booking_id' => $bookingId,
                            'result' => 'driver_assigned'
                        ];
                        break;

                    case 'change_status':
                        if (empty($data['status'])) {
                            throw new \Exception('Status required');
                        }
                        $this->changeBookingStatus($bookingId, $data['status'], $userId, $reason);
                        $results['success'][] = [
                            'booking_id' => $bookingId,
                            'result' => 'status_changed'
                        ];
                        break;

                    default:
                        throw new \Exception("Unsupported operation: {$operation}");
                }
            } catch (\Exception $e) {
                $results['failed'][] = [
                    'booking_id' => $bookingId,
                    'error' => $e->getMessage()
                ];
            }
        }

        return $results;
    }

    // ========================
    // DASHBOARD & ANALYTICS
    // ========================

    /**
     * Get comprehensive dashboard statistics
     */
    public function getDashboardStats(array $params): array
    {
        $period = $params['period'] ?? 'month';
        $dateFrom = $params['date_from'] ?? null;
        $dateTo = $params['date_to'] ?? null;

        // Set date range based on period
        [$startDate, $endDate] = $this->getDateRange($period, $dateFrom, $dateTo);

        $query = Booking::whereBetween('created_at', [$startDate, $endDate]);

        return [
            'overview' => [
                'total_bookings' => $query->count(),
                'confirmed_bookings' => $query->where('status', 'confirmed')->count(),
                'pending_approval' => $query->where('status', 'pending_approval')->count(),
                'in_progress' => $query->where('status', 'in_progress')->count(),
                'completed' => $query->where('status', 'completed')->count(),
                'cancelled' => $query->where('status', 'cancelled')->count(),
                'total_revenue' => $query->where('status', 'completed')->sum('total_actual'),
                'pending_revenue' => $query->whereIn('status', ['confirmed', 'in_progress'])->sum('total_estimated'),
            ],
            'trends' => $this->getDashboardTrends($startDate, $endDate),
            'top_customers' => $this->getTopCustomers($startDate, $endDate),
            'vehicle_utilization' => $this->getVehicleUtilization($startDate, $endDate),
            'service_type_breakdown' => $this->getServiceTypeBreakdown($startDate, $endDate),
            'recent_activities' => $this->getRecentActivities($startDate, $endDate),
            'alerts' => $this->getDashboardAlerts(),
            'period_info' => [
                'period' => $period,
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString()
            ]
        ];
    }

    /**
     * Get booking trends
     */
    public function getBookingTrends(array $params): array
    {
        $period = $params['period'];
        $metric = $params['metric'];
        $dateFrom = Carbon::parse($params['date_from']);
        $dateTo = Carbon::parse($params['date_to']);
        $groupBy = $params['group_by'] ?? null;

        $query = Booking::whereBetween('created_at', [$dateFrom, $dateTo]);

        $trends = [];
        $current = $dateFrom->copy();

        while ($current <= $dateTo) {
            $nextPeriod = $this->getNextPeriod($current, $period);

            $periodQuery = clone $query;
            $periodQuery->whereBetween('created_at', [$current, $nextPeriod]);

            $periodData = [
                'period' => $current->format('Y-m-d'),
                'period_end' => $nextPeriod->format('Y-m-d'),
            ];

            switch ($metric) {
                case 'count':
                    $periodData['value'] = $periodQuery->count();
                    break;
                case 'revenue':
                    $periodData['value'] = $periodQuery->where('status', 'completed')->sum('total_actual');
                    break;
                case 'average_value':
                    $periodData['value'] = $periodQuery->where('status', 'completed')->avg('total_actual');
                    break;
                case 'completion_rate':
                    $total = $periodQuery->count();
                    $completed = $periodQuery->where('status', 'completed')->count();
                    $periodData['value'] = $total > 0 ? ($completed / $total) * 100 : 0;
                    break;
            }

            if ($groupBy) {
                $periodData['breakdown'] = $this->getTrendBreakdown($periodQuery, $groupBy, $metric);
            }

            $trends[] = $periodData;
            $current = $nextPeriod->addDay();
        }

        return [
            'trends' => $trends,
            'summary' => $this->getTrendsSummary($trends, $metric),
            'metadata' => [
                'period' => $period,
                'metric' => $metric,
                'group_by' => $groupBy,
                'date_range' => [
                    'from' => $dateFrom->toDateString(),
                    'to' => $dateTo->toDateString()
                ]
            ]
        ];
    }

    /**
     * Get revenue analytics
     */
    public function getRevenueAnalytics(array $params): array
    {
        $period = $params['period'];
        $dateFrom = Carbon::parse($params['date_from']);
        $dateTo = Carbon::parse($params['date_to']);
        $breakdown = $params['breakdown'] ?? null;
        $includeProjections = $params['include_projections'] ?? false;

        $query = Booking::whereBetween('created_at', [$dateFrom, $dateTo])
            ->where('status', 'completed');

        $analytics = [
            'total_revenue' => $query->sum('total_amount'),
            'average_booking_value' => $query->avg('total_amount'),
            'booking_count' => $query->count(),
            'revenue_by_period' => $this->getRevenueByPeriod($query, $period, $dateFrom, $dateTo),
        ];

        if ($breakdown) {
            $analytics['breakdown'] = $this->getRevenueBreakdown($query, $breakdown);
        }

        if ($includeProjections) {
            $analytics['projections'] = $this->getRevenueProjections($dateFrom, $dateTo);
        }

        return $analytics;
    }

    /**
     * Get utilization reports
     */
    public function getUtilizationReports(array $params): array
    {
        $type = $params['type'];
        $period = $params['period'];
        $dateFrom = Carbon::parse($params['date_from']);
        $dateTo = Carbon::parse($params['date_to']);

        switch ($type) {
            case 'vehicle':
                return $this->getVehicleUtilizationReport($dateFrom, $dateTo, $period, $params);
            case 'driver':
                return $this->getDriverUtilizationReport($dateFrom, $dateTo, $period, $params);
            case 'service_type':
                return $this->getServiceTypeUtilizationReport($dateFrom, $dateTo, $period, $params);
            case 'time_based':
                return $this->getTimeBasedUtilizationReport($dateFrom, $dateTo, $period, $params);
            default:
                throw new \Exception("Unsupported utilization report type: {$type}");
        }
    }

    /**
     * Get customer analytics
     */
    public function getCustomerAnalytics(array $params): array
    {
        $metric = $params['metric'];
        $period = $params['period'];
        $dateFrom = Carbon::parse($params['date_from']);
        $dateTo = Carbon::parse($params['date_to']);
        $customerSegment = $params['customer_segment'] ?? null;

        $baseQuery = Booking::with('customer')
            ->whereBetween('created_at', [$dateFrom, $dateTo]);

        if ($customerSegment) {
            $baseQuery->whereHas('customer', function ($q) use ($customerSegment) {
                $q->where('customer_type', $customerSegment);
            });
        }

        switch ($metric) {
            case 'acquisition':
                return $this->getCustomerAcquisitionAnalytics($baseQuery, $period, $dateFrom, $dateTo);
            case 'retention':
                return $this->getCustomerRetentionAnalytics($baseQuery, $period, $dateFrom, $dateTo);
            case 'lifetime_value':
                return $this->getCustomerLifetimeValueAnalytics($baseQuery, $period, $dateFrom, $dateTo);
            case 'booking_frequency':
                return $this->getCustomerBookingFrequencyAnalytics($baseQuery, $period, $dateFrom, $dateTo);
            default:
                throw new \Exception("Unsupported customer analytics metric: {$metric}");
        }
    }

    /**
     * Generate comprehensive reports
     */
    public function generateReport(array $params): array
    {
        $reportType = $params['report_type'];
        $format = $params['format'] ?? 'pdf';
        $dateFrom = Carbon::parse($params['date_from']);
        $dateTo = Carbon::parse($params['date_to']);
        $filters = $params['filters'] ?? [];

        $reportData = [];

        switch ($reportType) {
            case 'financial':
                $reportData = $this->generateFinancialReport($dateFrom, $dateTo, $filters);
                break;
            case 'operational':
                $reportData = $this->generateOperationalReport($dateFrom, $dateTo, $filters);
                break;
            case 'customer':
                $reportData = $this->generateCustomerReport($dateFrom, $dateTo, $filters);
                break;
            case 'vehicle_performance':
                $reportData = $this->generateVehiclePerformanceReport($dateFrom, $dateTo, $filters);
                break;
            case 'driver_performance':
                $reportData = $this->generateDriverPerformanceReport($dateFrom, $dateTo, $filters);
                break;
            default:
                throw new \Exception("Unsupported report type: {$reportType}");
        }

        // Generate file based on format
        $fileName = $this->generateReportFile($reportData, $format, $reportType, $dateFrom, $dateTo);

        return [
            'report_data' => $reportData,
            'file_path' => $fileName,
            'format' => $format,
            'generated_at' => now(),
            'metadata' => [
                'report_type' => $reportType,
                'date_range' => [
                    'from' => $dateFrom->toDateString(),
                    'to' => $dateTo->toDateString()
                ],
                'filters' => $filters
            ]
        ];
    }

    /**
     * Export bookings data
     */
    public function exportBookings(array $params): array
    {
        $format = $params['format'];
        $filters = $params['filters'] ?? [];
        $columns = $params['columns'] ?? null;
        $includeRelations = $params['include_relations'] ?? false;

        $query = Booking::query();

        // Apply filters (reuse filtering logic)
        if (!empty($filters)) {
            $bookingsData = $this->getFilteredBookings($filters);
            $bookings = collect($bookingsData['bookings']);
        } else {
            if ($includeRelations) {
                $query->with(['customer', 'vehicle.vehicleGroup', 'driver', 'createdBy']);
            }
            $bookings = $query->get();
        }

        // Select specific columns if provided
        if ($columns) {
            $bookings = $bookings->map(function ($booking) use ($columns) {
                return collect($booking)->only($columns);
            });
        }

        // Generate export file
        $fileName = $this->generateExportFile($bookings, $format);

        return [
            'file_path' => $fileName,
            'format' => $format,
            'record_count' => $bookings->count(),
            'exported_at' => now(),
            'download_url' => url("storage/exports/{$fileName}")
        ];
    }

    // ========================
    // HELPER METHODS
    // ========================

    /**
     * Get bookings summary for filtered results
     */
    private function getBookingsSummary($query): array
    {
        // Create fresh clones for each aggregation to avoid conflicts
        $totalQuery = clone $query;
        $avgQuery = clone $query;

        // For grouped queries, create clones and remove any ORDER BY clauses
        $statusQuery = clone $query;
        $statusQuery->reorder(); // Remove all existing order clauses

        $serviceTypeQuery = clone $query;
        $serviceTypeQuery->reorder(); // Remove all existing order clauses

        return [
            'total_amount' => $totalQuery->sum('total_actual'),
            'average_amount' => $avgQuery->avg('total_actual'),
            'status_counts' => $statusQuery->groupBy('status')
                ->selectRaw('status, count(*) as count')
                ->pluck('count', 'status')
                ->toArray(),
            'service_type_counts' => $serviceTypeQuery->groupBy('service_type_id')
                ->selectRaw('service_type_id, count(*) as count')
                ->pluck('count', 'service_type_id')
                ->toArray()
        ];
    }

    /**
     * Get date range based on period
     */
    private function getDateRange(string $period, ?string $dateFrom, ?string $dateTo): array
    {
        $now = now();

        switch ($period) {
            case 'today':
                return [$now->startOfDay(), $now->endOfDay()];
            case 'week':
                return [$now->startOfWeek(), $now->endOfWeek()];
            case 'month':
                return [$now->startOfMonth(), $now->endOfMonth()];
            case 'quarter':
                return [$now->startOfQuarter(), $now->endOfQuarter()];
            case 'year':
                return [$now->startOfYear(), $now->endOfYear()];
            case 'custom':
                if (!$dateFrom || !$dateTo) {
                    throw new \Exception('Custom period requires date_from and date_to');
                }
                return [Carbon::parse($dateFrom), Carbon::parse($dateTo)];
            default:
                return [$now->startOfMonth(), $now->endOfMonth()];
        }
    }

    /**
     * Get next period for trends
     */
    private function getNextPeriod(Carbon $current, string $period): Carbon
    {
        switch ($period) {
            case 'daily':
                return $current->copy()->endOfDay();
            case 'weekly':
                return $current->copy()->endOfWeek();
            case 'monthly':
                return $current->copy()->endOfMonth();
            case 'quarterly':
                return $current->copy()->endOfQuarter();
            default:
                return $current->copy()->endOfDay();
        }
    }

    // Placeholder methods for complex analytics (implement as needed)
    private function getDashboardTrends($startDate, $endDate): array
    {
        return [];
    }
    private function getTopCustomers($startDate, $endDate): array
    {
        return [];
    }
    private function getVehicleUtilization($startDate, $endDate): array
    {
        return [];
    }
    private function getServiceTypeBreakdown($startDate, $endDate): array
    {
        return [];
    }
    private function getRecentActivities($startDate, $endDate): array
    {
        return [];
    }
    private function getDashboardAlerts(): array
    {
        return [];
    }
    private function getTrendBreakdown($query, $groupBy, $metric): array
    {
        return [];
    }
    private function getTrendsSummary($trends, $metric): array
    {
        return [];
    }
    private function getRevenueByPeriod($query, $period, $dateFrom, $dateTo): array
    {
        return [];
    }
    private function getRevenueBreakdown($query, $breakdown): array
    {
        return [];
    }
    private function getRevenueProjections($dateFrom, $dateTo): array
    {
        return [];
    }
    private function getVehicleUtilizationReport($dateFrom, $dateTo, $period, $params): array
    {
        return [];
    }
    private function getDriverUtilizationReport($dateFrom, $dateTo, $period, $params): array
    {
        return [];
    }
    private function getServiceTypeUtilizationReport($dateFrom, $dateTo, $period, $params): array
    {
        return [];
    }
    private function getTimeBasedUtilizationReport($dateFrom, $dateTo, $period, $params): array
    {
        return [];
    }
    private function getCustomerAcquisitionAnalytics($query, $period, $dateFrom, $dateTo): array
    {
        return [];
    }
    private function getCustomerRetentionAnalytics($query, $period, $dateFrom, $dateTo): array
    {
        return [];
    }
    private function getCustomerLifetimeValueAnalytics($query, $period, $dateFrom, $dateTo): array
    {
        return [];
    }
    private function getCustomerBookingFrequencyAnalytics($query, $period, $dateFrom, $dateTo): array
    {
        return [];
    }
    private function generateFinancialReport($dateFrom, $dateTo, $filters): array
    {
        return [];
    }
    private function generateOperationalReport($dateFrom, $dateTo, $filters): array
    {
        return [];
    }
    private function generateCustomerReport($dateFrom, $dateTo, $filters): array
    {
        return [];
    }
    private function generateVehiclePerformanceReport($dateFrom, $dateTo, $filters): array
    {
        return [];
    }
    private function generateDriverPerformanceReport($dateFrom, $dateTo, $filters): array
    {
        return [];
    }
    private function generateReportFile($data, $format, $type, $dateFrom, $dateTo): string
    {
        return 'report.pdf';
    }
    private function generateExportFile($data, $format): string
    {
        return 'export.csv';
    }
    private function getBookingTimeline($booking): array
    {
        return [];
    }
    private function getPricingBreakdown($booking): array
    {
        return [];
    }
    private function getBookingDocuments($booking): array
    {
        return [];
    }
    private function getBookingActivities($booking): array
    {
        return [];
    }
    private function getRelatedBookings($booking): array
    {
        return [];
    }
    private function assignVehicle($bookingId, $vehicleId, $userId): void
    {
    }
    private function assignDriver($bookingId, $driverId, $userId): void
    {
    }
    private function changeBookingStatus($bookingId, $status, $userId, $reason): void
    {
    }

    /**
     * Approve booking
     */
    private function approveBooking(string $bookingId, string $userId, ?string $reason = null): void
    {
        $booking = Booking::findOrFail($bookingId);

        $booking->update([
            'status' => 'approved',
            'approved_at' => now(),
            'approved_by' => $userId,
            'approval_reason' => $reason
        ]);
    }

    /**
     * Reject booking
     */
    private function rejectBooking(string $bookingId, string $userId, ?string $reason = null): void
    {
        $booking = Booking::findOrFail($bookingId);

        $booking->update([
            'status' => 'rejected',
            'rejected_at' => now(),
            'rejected_by' => $userId,
            'rejection_reason' => $reason
        ]);
    }

    /**
     * Analyze vehicle availability in a group with enhanced conflict detection
     */
    private function analyzeVehicleAvailability($vehicleGroup, Carbon $fromDate, Carbon $toDate, ?string $excludeBookingId = null): array
    {
        $allVehicles = $vehicleGroup->vehicles()->where('status', 'active')->get();
        $totalCount = $allVehicles->count();
        $availableCount = 0;
        $bookedCount = 0;
        $conflictCount = 0;
        $concurrentPossible = false;
        $overrideAvailable = false;
        $vehicleDetails = [];

        foreach ($allVehicles as $vehicle) {
            $conflicts = $this->getVehicleConflictsDetailed($vehicle, $fromDate, $toDate, $excludeBookingId);
            $availabilityStatus = $this->determineVehicleAvailabilityStatus($vehicle, $conflicts, $fromDate, $toDate);

            if ($availabilityStatus === 'available') {
                $availableCount++;
            } elseif ($availabilityStatus === 'booked') {
                $bookedCount++;
            } elseif ($availabilityStatus === 'conflict') {
                $conflictCount++;
            }

            if ($this->isConcurrentAssignmentPossible($vehicle, $conflicts)) {
                $concurrentPossible = true;
            }

            if ($this->isVehicleOverrideAllowed($vehicle, $conflicts)) {
                $overrideAvailable = true;
            }

            $vehicleDetails[] = [
                'id' => $vehicle->id,
                'name' => $vehicle->title,
                'license_plate' => $vehicle->license_plate,
                'status' => $availabilityStatus,
                'conflicts' => $conflicts,
                'can_override' => $this->isVehicleOverrideAllowed($vehicle, $conflicts),
                'concurrent_possible' => $this->isConcurrentAssignmentPossible($vehicle, $conflicts),
            ];
        }

        return [
            'total_count' => $totalCount,
            'available_count' => $availableCount,
            'booked_count' => $bookedCount,
            'conflict_count' => $conflictCount,
            'concurrent_possible' => $concurrentPossible,
            'override_available' => $overrideAvailable,
            'vehicle_details' => $vehicleDetails,
        ];
    }

    /**
     * Get detailed vehicle conflicts with booking information
     */
    private function getVehicleConflictsDetailed($vehicle, Carbon $fromDate, Carbon $toDate, ?string $excludeBookingId = null): array
    {
        $conflicts = [];

        // Query through booking_items which contains the date fields
        $bookingItems = BookingItem::where('booking_items.vehicle_id', $vehicle->id)
            ->join('bookings', 'booking_items.booking_id', '=', 'bookings.id')
            ->where('bookings.status', '!=', 'cancelled')
            ->when($excludeBookingId, function ($q) use ($excludeBookingId) {
                $q->where('bookings.id', '!=', $excludeBookingId);
            })
            ->where(function ($q) use ($fromDate, $toDate) {
                $q->whereBetween('booking_items.from_date', [$fromDate, $toDate])
                    ->orWhereBetween('booking_items.to_date', [$fromDate, $toDate])
                    ->orWhere(function ($inner) use ($fromDate, $toDate) {
                        $inner->where('booking_items.from_date', '<=', $fromDate)
                            ->where('booking_items.to_date', '>=', $toDate);
                    });
            })
            ->select('booking_items.*', 'bookings.id as booking_id', 'bookings.status', 'bookings.customer_id')
            ->with(['booking.customer'])
            ->get();

        foreach ($bookingItems as $item) {
            $booking = $item->booking;
            $overlapType = $this->determineOverlapType($fromDate, $toDate, $item->from_date, $item->to_date);
            $canOverride = $this->canOverrideBooking($booking);

            $conflicts[] = [
                'booking_id' => $booking->id,
                'customer_name' => $booking->customer->first_name . ' ' . $booking->customer->last_name,
                'from' => $item->from_date->format('Y-m-d H:i'),
                'to' => $item->to_date->format('Y-m-d H:i'),
                'status' => $booking->status,
                'type' => $item->service_type_id,
                'overlap_type' => $overlapType,
                'can_override' => $canOverride,
                'priority' => $this->getBookingPriority($booking),
                'reason' => $canOverride ? 'Override possible with approval' : 'Firm booking conflict',
            ];
        }

        return $conflicts;
    }

    /**
     * Check if a booking can be overridden
     */
    private function canOverrideBooking($booking): bool
    {
        // Logic for determining if booking can be overridden
        // This could be based on booking status, customer type, priority, etc.
        return in_array($booking->status, ['pending', 'draft']) ||
            $booking->customer->priority_level === 'low' ||
            $booking->created_at->diffInHours(now()) < 24;
    }

    /**
     * Get booking priority level
     */
    private function getBookingPriority($booking): string
    {
        // Determine priority based on various factors
        if ($booking->status === 'confirmed')
            return 'high';
        if ($booking->customer->priority_level === 'premium')
            return 'high';
        if ($booking->status === 'pending')
            return 'medium';
        return 'low';
    }

    /**
     * Determine group availability status
     */
    private function determineGroupAvailabilityStatus(int $availableCount, int $totalCount, int $conflictCount): string
    {
        if ($availableCount === $totalCount) {
            return 'fully_available';
        } elseif ($availableCount > 0) {
            return 'partially_available';
        } elseif ($conflictCount > 0) {
            return 'conflict_available';
        } else {
            return 'unavailable';
        }
    }

    /**
     * Get detailed driver conflicts with booking information
     */
    private function getDriverConflictsDetailed($driver, Carbon $fromDate, Carbon $toDate, ?string $excludeBookingId = null): array
    {
        $conflicts = [];

        // Query through booking_items which contains the date fields
        $bookingItems = DriverAssignment::where('driver_id', $driver->id)
            ->join('bookings', 'driver_assignments.booking_id', '=', 'bookings.id')
            ->where('bookings.status', '!=', 'cancelled')
            ->when($excludeBookingId, function ($q) use ($excludeBookingId) {
                $q->where('bookings.id', '!=', $excludeBookingId);
            })
            ->where(function ($q) use ($fromDate, $toDate) {
                $q->whereBetween('driver_assignments.from_date', [$fromDate, $toDate])
                    ->orWhereBetween('driver_assignments.to_date', [$fromDate, $toDate])
                    ->orWhere(function ($inner) use ($fromDate, $toDate) {
                        $inner->where('driver_assignments.from_date', '<=', $fromDate)
                            ->where('driver_assignments.to_date', '>=', $toDate);
                    });
            })
            ->select('driver_assignments.*', 'bookings.id as booking_id', 'bookings.status', 'bookings.service_type', 'bookings.customer_id')
            ->with(['booking.customer'])
            ->get();

        foreach ($bookingItems as $item) {
            $booking = $item->booking;
            $overlapType = $this->determineOverlapType($fromDate, $toDate, $item->from_date, $item->to_date);
            $canOverride = $this->canOverrideBooking($booking);

            $conflicts[] = [
                'booking_id' => $booking->id,
                'customer_name' => $booking->customer->first_name . ' ' . $booking->customer->last_name,
                'from' => $item->from_date->format('Y-m-d H:i'),
                'to' => $item->to_date->format('Y-m-d H:i'),
                'status' => $booking->status,
                'type' => $booking->service_type,
                'overlap_type' => $overlapType,
                'can_override' => $canOverride,
                'priority' => $this->getBookingPriority($booking),
                'reason' => $canOverride ? 'Override possible with approval' : 'Firm booking conflict',
            ];
        }

        return $conflicts;
    }

    /**
     * Determine driver availability status
     */
    private function determineDriverAvailabilityStatus($driver, array $conflicts, Carbon $fromDate, Carbon $toDate): string
    {
        if (empty($conflicts)) {
            return 'available';
        }

        // Check if all conflicts can be overridden
        $allCanOverride = true;
        foreach ($conflicts as $conflict) {
            if (!$conflict['can_override']) {
                $allCanOverride = false;
                break;
            }
        }

        if ($allCanOverride) {
            return 'available_with_override';
        }

        return 'booked';
    }

    /**
     * Check if driver booking can be overridden
     */
    private function isDriverOverrideAllowed($driver, array $conflicts): bool
    {
        if (empty($conflicts)) {
            return true;
        }

        // Check if all conflicts allow override
        foreach ($conflicts as $conflict) {
            if (!$conflict['can_override']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Apply variable customizations to pricing calculations
     */
    protected function applyVariableCustomizations(array $customizations, array $params): array
    {
        $appliedCustomizations = [];
        $sessionId = $params['session_id'] ?? session()->getId();
        $bookingId = $params['booking_id'] ?? null;
        $vehicleGroupId = $params['vehicle_group_id'] ?? null;

        foreach ($customizations as $customization) {
            try {
                // Get vehicle_group_id from customization data or params
                $customizationGroupId = $customization['vehicle_group_id'] ?? $vehicleGroupId;

                // Store or update variable customization in database
                $customizationRecord = \App\Models\Booking\BookingVariableCustomization::updateOrCreate([
                    'session_id' => $sessionId,
                    'booking_id' => $bookingId,
                    'vehicle_group_id' => $customizationGroupId,
                    'variable_name' => $customization['variable_name'],
                ], [
                    'variable_type' => $customization['variable_type'] ?? $this->determineVariableType($customization['variable_name']),
                    'original_value' => $customization['original_value'] ?? 0,
                    'custom_value' => $customization['custom_value'],
                    'customization_reason' => $customization['reason'] ?? 'User customization',
                    'context' => $customization['context'] ?? 'base_pricing',
                    'metadata' => [
                        'rate_type' => $customization['rate_type'] ?? null,
                        'billing_type' => $customization['billing_type'] ?? null,
                        'model_type' => $customization['model_type'] ?? null,
                        'model_id' => $customization['model_id'] ?? null,
                    ],
                    'created_user_id' => Auth::id(),
                    'updated_user_id' => Auth::id(),
                ]);

                $appliedCustomizations[] = [
                    'id' => $customizationRecord->id,
                    'variable_name' => $customization['variable_name'],
                    'variable_type' => $customizationRecord->variable_type,
                    'original_value' => $customizationRecord->original_value,
                    'custom_value' => $customizationRecord->custom_value,
                    'rate_type' => $customizationRecord->metadata['rate_type'] ?? null,
                    'billing_type' => $customizationRecord->metadata['billing_type'] ?? null,
                    'change_percentage' => $customizationRecord->original_value > 0
                        ? (($customizationRecord->custom_value - $customizationRecord->original_value) / $customizationRecord->original_value * 100)
                        : 0,
                    'applied_at' => $customizationRecord->updated_at
                ];
            } catch (\Exception $e) {
                Log::error("Failed to apply variable customization: " . $e->getMessage(), [
                    'customization' => $customization
                ]);
            }
        }

        return $appliedCustomizations;
    }

    /**
     * Determine variable type based on variable name
     */
    protected function determineVariableType(string $variableName): string
    {
        if (str_contains($variableName, 'slab_rate') || str_contains($variableName, 'base_rate')) {
            return 'slab_rate';
        }

        if (str_contains($variableName, 'rate_per_km') || str_contains($variableName, 'allowance') || str_contains($variableName, 'charges')) {
            return 'common_rate';
        }

        if (str_contains($variableName, 'addon_')) {
            return 'addon_rate';
        }

        return 'fixed_value';
    }

    /**
     * Calculate dynamic pricing with customizations and duration awareness
     */
    protected function calculateDynamicPricingWithCustomizations(array $params): array
    {
        // Apply variable customizations to calculation inputs if available
        $originalParams = $params;
        if (!empty($params['applied_customizations'])) {
            $params = $this->pricingVariableService->applyVariableCustomizations(
                $params,
                $params['applied_customizations'],
                'base_pricing'
            );
        }

        // Get base pricing calculations
        $basePricing = $this->calculateDynamicPricing($params);

        // Calculate original pricing without customizations for comparison
        $originalBasePricing = null;
        if (!empty($originalParams['applied_customizations'])) {
            $originalBasePricing = $this->calculateDynamicPricing($originalParams);
            if (!empty($originalParams['duration'])) {
                // Apply same duration calculations to original pricing
                $duration = $originalParams['duration'];
                if (isset($originalBasePricing['breakdown']) && is_array($originalBasePricing['breakdown'])) {
                    foreach ($originalBasePricing['breakdown'] as &$item) {
                        $rateType = strtolower($item['rate_type'] ?? '');
                        $originalAmount = $item['unit_amount'] ?? $item['amount'] ?? 0;
                        if (!isset($item['unit_amount'])) {
                            $item['unit_amount'] = $originalAmount;
                        }
                        $multiplier = 1;
                        if (str_contains($rateType, 'per_day') || str_contains($rateType, 'daily')) {
                            $multiplier = $duration['days'];
                            $item['amount'] = $originalAmount * $multiplier;
                        } elseif (str_contains($rateType, 'per_hour') || str_contains($rateType, 'hourly')) {
                            $multiplier = $duration['hours'];
                            $item['amount'] = $originalAmount * $multiplier;
                        } else {
                            $item['amount'] = $originalAmount;
                        }
                    }
                }
                $originalBasePricing['total_amount'] = array_sum(array_column($originalBasePricing['breakdown'] ?? [], 'amount'));
            }
        }

        // Apply duration multipliers to base pricing items
        if (!empty($params['duration'])) {
            $duration = $params['duration'];

            if (isset($basePricing['breakdown']) && is_array($basePricing['breakdown'])) {
                foreach ($basePricing['breakdown'] as &$item) {
                    $rateType = strtolower($item['rate_type'] ?? '');
                    $originalAmount = $item['unit_amount'] ?? $item['amount'] ?? 0;

                    // Store original unit amount if not already present
                    if (!isset($item['unit_amount'])) {
                        $item['unit_amount'] = $originalAmount;
                    }

                    // Apply duration multipliers based on rate type
                    $multiplier = 1;
                    $durationInfo = 'One-time';

                    if (str_contains($rateType, 'per_day') || str_contains($rateType, 'daily')) {
                        $multiplier = $duration['days'];
                        $durationInfo = $duration['days'] . ' days';
                        $item['amount'] = $originalAmount * $multiplier;
                    } elseif (str_contains($rateType, 'per_hour') || str_contains($rateType, 'hourly')) {
                        $multiplier = $duration['hours'];
                        $durationInfo = $duration['hours'] . ' hours';
                        $item['amount'] = $originalAmount * $multiplier;
                    } elseif (str_contains($rateType, 'flat_rate') || str_contains($rateType, 'fixed')) {
                        // No multiplication for flat rates
                        $item['amount'] = $originalAmount;
                        $durationInfo = 'Fixed rate';
                    } else {
                        // Default: treat as flat rate
                        $item['amount'] = $originalAmount;
                        $durationInfo = 'Fixed rate';
                    }

                    $item['duration_applied'] = $durationInfo;
                    $item['multiplier'] = $multiplier;
                    $item['calculation_detail'] = $multiplier > 1
                        ? "LKR {$originalAmount} × {$multiplier} = LKR {$item['amount']}"
                        : "LKR {$originalAmount} (fixed)";
                }
            }

            // Recalculate total amount
            $basePricing['total_amount'] = array_sum(array_column($basePricing['breakdown'] ?? [], 'amount'));
        }

        // Add original pricing for comparison if customizations were applied
        if ($originalBasePricing) {
            $basePricing['total_amount_without_customizations'] = $originalBasePricing['total_amount'];
        }

        return $basePricing;
    }

    /**
     * Check if a pricing item matches a variable customization
     */
    protected function matchesPricingItem(array $item, string $variableName, array $customization): bool
    {
        $itemComponent = strtolower($item['component'] ?? '');
        $itemDescription = strtolower($item['description'] ?? '');
        $variableName = strtolower($variableName);

        // Direct matches
        if (str_contains($itemComponent, $variableName) || str_contains($itemDescription, $variableName)) {
            return true;
        }

        // Slab rate matches
        if (str_contains($variableName, 'slab_rate') && str_contains($itemComponent, 'base_rate')) {
            return true;
        }

        // Common rate matches (rate_per_km, allowances, etc.)
        if ($customization['variable_type'] === 'common_rate') {
            $rateKey = str_replace(['common_rate_', '_rate_per_km', '_allowance'], '', $variableName);
            if (str_contains($itemComponent, $rateKey) || str_contains($itemDescription, $rateKey)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Calculate addons pricing with duration awareness
     */
    protected function calculateAddonsPricingWithDuration(array $selectedAddons, string $vehicleGroupId, array $duration, array $params): array
    {
        if (empty($selectedAddons)) {
            return [
                'addons' => [],
                'addons_total' => 0
            ];
        }

        $addons = [];
        $addonsTotal = 0;
        $addonsTotalWithoutCustomizations = 0;

        foreach ($selectedAddons as $selectedAddon) {
            try {
                // Get addon details from database
                $addon = VehicleAddon::find($selectedAddon['id']);
                if (!$addon) {
                    continue;
                }

                $quantity = $selectedAddon['quantity'] ?? 1;
                $customPrice = $selectedAddon['custom_price'] ?? null;
                $priceWithoutCustomizations = 0;
                if ($customPrice !== $addon->amount) {
                    $unitPrice = $addon->amount;

                    // Get billing type from addon (per_day, per_hour, per_package)
                    $billingType = $addon->billing_type ?? 'per_package';

                    // Initialize variables
                    $basePrice = $unitPrice * $quantity;
                    $totalPrice = $basePrice;
                    $durationInfo = 'One-time';
                    $multiplier = 1;
                    $customizationId = null;

                    // Calculate total price based on billing type and duration
                    switch ($billingType) {
                        case 'per_day':
                            $multiplier = $duration['days'];
                            $totalPrice = $basePrice * $multiplier;
                            $durationInfo = $duration['days'] . ' days';
                            break;
                        case 'per_hour':
                            $multiplier = $duration['hours'];
                            $totalPrice = $basePrice * $multiplier;
                            $durationInfo = $duration['hours'] . ' hours';
                            break;
                        case 'per_package':
                        default:
                            $totalPrice = $basePrice;
                            $durationInfo = 'One-time';
                            break;
                    }

                    $priceWithoutCustomizations = $totalPrice;
                }

                // Use the amount field from VehicleAddon model (this is the unit price)
                $unitPrice = $customPrice ?? $addon->amount;

                // Get billing type from addon (per_day, per_hour, per_package)
                $billingType = $addon->billing_type ?? 'per_package';

                // Initialize variables
                $basePrice = $unitPrice * $quantity;
                $totalPrice = $basePrice;
                $durationInfo = 'One-time';
                $multiplier = 1;
                $customizationId = null;

                // Calculate total price based on billing type and duration
                switch ($billingType) {
                    case 'per_day':
                        $multiplier = $duration['days'];
                        $totalPrice = $basePrice * $multiplier;
                        $durationInfo = $duration['days'] . ' days';
                        break;
                    case 'per_hour':
                        $multiplier = $duration['hours'];
                        $totalPrice = $basePrice * $multiplier;
                        $durationInfo = $duration['hours'] . ' hours';
                        break;
                    case 'per_package':
                    default:
                        $totalPrice = $basePrice;
                        $durationInfo = 'One-time';
                        break;
                }


                $addonData = [
                    'id' => $addon->id,
                    'name' => $addon->name,
                    'unit_price' => $unitPrice,
                    'quantity' => $quantity,
                    'billing_type' => $billingType,
                    'duration_info' => $durationInfo,
                    'multiplier' => $multiplier,
                    'total_price' => $totalPrice,
                    'is_custom_price' => !is_null($customPrice) || !is_null($customizationId),
                    'original_price' => $addon->amount,
                    'price_without_customizations' => $priceWithoutCustomizations,
                    'customization_id' => $customizationId,
                    'calculation_detail' => $multiplier > 1
                        ? "LKR {$unitPrice} × {$quantity} × {$multiplier} = LKR {$totalPrice}"
                        : "LKR {$unitPrice} × {$quantity} = LKR {$totalPrice}"
                ];

                $addons[] = $addonData;
                $addonsTotal += $totalPrice;
                $addonsTotalWithoutCustomizations += $priceWithoutCustomizations;
            } catch (\Exception $e) {
                throw $e;
                Log::error("Error processing addon {$selectedAddon['id']}: " . $e->getMessage());
                continue;
            }
        }

        return [
            'addons' => $addons,
            'addons_total' => $addonsTotal,
            'addons_total_without_customizations' => $addonsTotalWithoutCustomizations
        ];
    }

    /**
     * Create vehicle and driver assignments for booking
     */
    protected function createBookingAssignments(Booking $booking, array $params, string $status = 'pending_approval'): void
    {
        // Load relationships if not already loaded
        if (!$booking->relationLoaded('customer')) {
            $booking->load('customer');
        }
        if (!$booking->relationLoaded('bookingItems')) {
            $booking->load('bookingItems');
        }

        // Get first booking item - all assignments are based on the first item
        $bookingItem = $booking->bookingItems()->first();
        if (!$bookingItem) {
            Log::warning("No booking items found for booking {$booking->id}. Skipping assignments.");
            return;
        }

        $customer = $booking->customer;

        // Get customer name - handle different possible field names
        $customerName = 'Unknown Customer';
        if ($customer) {
            $customerName = $customer->name ??
                $customer->customer_name ??
                $customer->full_name ??
                ($customer->first_name && $customer->last_name ?
                    "{$customer->first_name} {$customer->last_name}" :
                    'Unknown Customer');
        }

        $assignmentParams = [
            'booking_id' => $booking->id,
            'customer_name' => $customerName,
            'service_type' => $bookingItem->serviceType ? $bookingItem->serviceType->name : ($params['service_type'] ?? 'Unknown Service'),
            'assigned_from' => $bookingItem->from_date,
            'assigned_to' => $bookingItem->to_date,
            'assignment_type' => $this->determineAssignmentType($params),
            'status' => $status,
            'requires_approval' => $status === 'pending_approval' || $booking->requires_approval,
            'override_reasons' => $params['override_reasons'] ?? [],
            'assigned_by' => Auth::id() ?? ($params['assigned_by'] ?? ($params['created_user_id'] ?? null)),
            'created_user_id' => Auth::id() ?? ($params['assigned_by'] ?? ($params['created_user_id'] ?? null)),
        ];

        // Create vehicle assignment if vehicle is selected
        if ($bookingItem->vehicle_id) {
            try {
                $vehicleAssignmentParams = array_merge($assignmentParams, [
                    'vehicle_id' => $bookingItem->vehicle_id,
                ]);

                // Check for overlaps and set overlap details
                $vehicle = Vehicle::find($bookingItem->vehicle_id);
                if ($vehicle) {
                    $conflicts = $vehicle->getAssignmentConflicts($bookingItem->from_date, $bookingItem->to_date, $booking->id);
                    if (!empty($conflicts)) {
                        $vehicleAssignmentParams['overlap_type'] = 'concurrent';
                        $vehicleAssignmentParams['overlap_details'] = $conflicts;
                    }
                }

                $vehicleAssignment = $this->assignmentService->createVehicleAssignment($vehicleAssignmentParams);

                // Track assignment activity
                $this->logAssignmentActivity($booking, 'vehicle_assigned', [
                    'vehicle_id' => $bookingItem->vehicle_id,
                    'vehicle_name' => $vehicle->name ?? $vehicle->title ?? 'Unknown Vehicle',
                    'license_plate' => $vehicle->license_plate ?? null,
                    'assignment_id' => $vehicleAssignment->id,
                    'assignment_type' => $assignmentParams['assignment_type'],
                    'status' => $status,
                ]);
            } catch (\Exception $e) {
                Log::error("Failed to create vehicle assignment for booking {$booking->id}: " . $e->getMessage());
                Log::error("Vehicle assignment params: ", $vehicleAssignmentParams ?? []);
                // Re-throw so we can see the issue
                throw $e;
            }
        }

        // Create driver assignment if driver is selected and not self-driven
        if ($bookingItem->driver_id && !($params['is_self_driven'] ?? false)) {
            try {
                $driverAssignmentParams = array_merge($assignmentParams, [
                    'driver_id' => $bookingItem->driver_id,
                    'hourly_rate' => $params['driver_hourly_rate'] ?? null,
                    'overtime_applicable' => $params['overtime_applicable'] ?? false,
                ]);

                // Check for driver conflicts
                $driver = Driver::find($bookingItem->driver_id);
                if ($driver) {
                    $conflicts = $driver->getAssignmentConflicts($bookingItem->from_date, $bookingItem->to_date, $booking->id);
                    if (!empty($conflicts)) {
                        $driverAssignmentParams['overlap_type'] = 'override'; // Drivers don't typically support concurrent
                        $driverAssignmentParams['overlap_details'] = $conflicts;
                        $driverAssignmentParams['requires_approval'] = true;
                    }
                }

                $driverAssignment = $this->assignmentService->createDriverAssignment($driverAssignmentParams);

                // Track assignment activity
                $this->logAssignmentActivity($booking, 'driver_assigned', [
                    'driver_id' => $bookingItem->driver_id,
                    'driver_name' => $driver->name ?? 'Unknown Driver',
                    'license_number' => $driver->license_number ?? $driver->license_no ?? null,
                    'assignment_id' => $driverAssignment->id,
                    'assignment_type' => $assignmentParams['assignment_type'],
                    'status' => $status,
                ]);
            } catch (\Exception $e) {
                Log::error("Failed to create driver assignment for booking {$booking->id}: " . $e->getMessage());
                Log::error("Driver assignment params: ", $driverAssignmentParams ?? []);
                // Re-throw so we can see the issue
                throw $e;
            }
        }
    }

    /**
     * Determine assignment type based on booking parameters
     */
    protected function determineAssignmentType(array $params): string
    {
        // Check if this is an override scenario
        if (!empty($params['override_reasons'])) {
            return 'override';
        }

        // Check if concurrent assignments are being created
        if (!empty($params['concurrent_assignments'])) {
            return 'concurrent';
        }

        return 'primary';
    }

    /**
     * Update booking assignments when vehicle/driver or dates change
     */
    protected function updateBookingAssignments(Booking $booking, array $params, Booking $original): void
    {
        // Close/end existing assignments if vehicle or driver changed
        if ($original->vehicle_id !== $booking->vehicle_id) {
            // End previous vehicle assignment
            if ($original->vehicle_id) {
                $vehicle = Vehicle::find($original->vehicle_id);
                VehicleAssignment::where('booking_id', $booking->id)
                    ->where('vehicle_id', $original->vehicle_id)
                    ->whereNull('actual_end')
                    ->update([
                        'actual_end' => now(),
                        'status' => 'completed',
                        'ended_by' => Auth::id(),
                        'end_reason' => 'vehicle_changed'
                    ]);

                // Log vehicle change
                $this->logAssignmentActivity($booking, 'vehicle_unassigned', [
                    'vehicle_id' => $original->vehicle_id,
                    'vehicle_name' => $vehicle->name ?? $vehicle->title ?? 'Unknown Vehicle',
                    'reason' => 'vehicle_changed',
                    'new_vehicle_id' => $booking->vehicle_id,
                ]);
            }
        }

        if ($original->driver_id !== $booking->driver_id) {
            // End previous driver assignment
            if ($original->driver_id) {
                $driver = Driver::find($original->driver_id);
                DriverAssignment::where('booking_id', $booking->id)
                    ->where('driver_id', $original->driver_id)
                    ->whereNull('actual_end')
                    ->update([
                        'actual_end' => now(),
                        'status' => 'completed',
                        'ended_by' => Auth::id(),
                        'end_reason' => 'driver_changed'
                    ]);

                // Log driver change
                $this->logAssignmentActivity($booking, 'driver_unassigned', [
                    'driver_id' => $original->driver_id,
                    'driver_name' => $driver->name ?? 'Unknown Driver',
                    'reason' => 'driver_changed',
                    'new_driver_id' => $booking->driver_id,
                ]);
            }
        }

        // Create new assignments for updated booking
        $this->createBookingAssignments($booking, $params, $booking->status === 'confirmed' ? 'active' : 'pending_approval');
    }

    /**
     * Enhanced vehicle selection with assignment details
     */
    public function selectVehicleWithAssignmentDetails(string $vehicleId, array $params): array
    {
        $fromDate = Carbon::parse($params['from_date']);
        $toDate = Carbon::parse($params['to_date']);
        $serviceType = $params['service_type'];
        $excludeBookingId = $params['exclude_booking_id'] ?? null;

        return $this->assignmentService->handleVehicleSelection(
            $vehicleId,
            $fromDate,
            $toDate,
            $serviceType,
            $excludeBookingId
        );
    }

    /**
     * Get alternative assignments when vehicle/driver conflicts exist
     */
    public function getAlternativeAssignments(array $params): array
    {
        $vehicleGroupId = $params['vehicle_group_id'];
        $fromDate = Carbon::parse($params['from_date']);
        $toDate = Carbon::parse($params['to_date']);
        $serviceType = $params['service_type'];
        $excludeBookingId = $params['exclude_booking_id'] ?? null;

        return $this->assignmentService->getAlternativeAssignments(
            $vehicleGroupId,
            $fromDate,
            $toDate,
            $serviceType,
            $excludeBookingId
        );
    }

    /**
     * Log assignment activity for tracking changes
     */
    protected function logAssignmentActivity(Booking $booking, string $action, array $metadata = []): void
    {
        try {
            // Use Spatie Activity Log if available
            if (class_exists('\Spatie\Activitylog\Models\Activity')) {
                $description = $this->getAssignmentActivityDescription($action, $metadata);

                activity()
                    ->performedOn($booking)
                    ->causedBy(Auth::user())
                    ->withProperties($metadata)
                    ->log($description);
            }

            // Also log to Laravel log for debugging
            Log::info("Assignment Activity: {$action} for booking {$booking->id}", [
                'action' => $action,
                'booking_id' => $booking->id,
                'metadata' => $metadata,
                'user_id' => Auth::id(),
                'timestamp' => now()->toISOString(),
            ]);
        } catch (\Exception $e) {
            // Don't let logging errors break the main flow
            Log::error("Failed to log assignment activity: " . $e->getMessage(), [
                'action' => $action,
                'booking_id' => $booking->id,
                'metadata' => $metadata,
            ]);
        }
    }

    /**
     * Generate human-readable description for assignment activities
     */
    protected function getAssignmentActivityDescription(string $action, array $metadata): string
    {
        $user = Auth::user();
        $userName = $user ? $user->name : 'System';

        switch ($action) {
            case 'vehicle_assigned':
                $vehicleName = $metadata['vehicle_name'] ?? 'Unknown Vehicle';
                $licensePlate = $metadata['license_plate'] ?? '';
                $plateInfo = $licensePlate ? " ({$licensePlate})" : '';
                return "{$userName} assigned vehicle {$vehicleName}{$plateInfo} to booking";

            case 'driver_assigned':
                $driverName = $metadata['driver_name'] ?? 'Unknown Driver';
                $licenseNumber = $metadata['license_number'] ?? '';
                $licenseInfo = $licenseNumber ? " (License: {$licenseNumber})" : '';
                return "{$userName} assigned driver {$driverName}{$licenseInfo} to booking";

            case 'vehicle_unassigned':
                $vehicleName = $metadata['vehicle_name'] ?? 'Unknown Vehicle';
                $reason = $metadata['reason'] ?? 'unknown reason';
                return "{$userName} unassigned vehicle {$vehicleName} from booking (Reason: {$reason})";

            case 'driver_unassigned':
                $driverName = $metadata['driver_name'] ?? 'Unknown Driver';
                $reason = $metadata['reason'] ?? 'unknown reason';
                return "{$userName} unassigned driver {$driverName} from booking (Reason: {$reason})";

            case 'assignment_swap':
                return "{$userName} performed assignment swap for booking";

            case 'assignment_breakdown':
                return "{$userName} recorded breakdown for booking assignment";

            case 'assignment_approved':
                return "{$userName} approved assignment for booking";

            case 'assignment_conflict_resolved':
                return "{$userName} resolved assignment conflict for booking";

            default:
                return "{$userName} performed assignment action: {$action}";
        }
    }

    /**
     * Get comprehensive assignment history for a booking
     */
    public function getAssignmentHistory(string $bookingId): array
    {
        $history = [];

        try {
            // Get assignment-related activities from activity log
            if (class_exists('\Spatie\Activitylog\Models\Activity')) {
                $Activity = \Spatie\Activitylog\Models\Activity::class;
                $activities = $Activity::query()
                    ->where('subject_type', Booking::class)
                    ->where('subject_id', $bookingId)
                    ->whereIn('description', [
                        'vehicle_assigned',
                        'driver_assigned',
                        'vehicle_unassigned',
                        'driver_unassigned',
                        'assignment_swap',
                        'assignment_breakdown',
                        'assignment_approved',
                        'assignment_conflict_resolved'
                    ])
                    ->orWhere('description', 'like', '%assigned%')
                    ->orWhere('description', 'like', '%vehicle%')
                    ->orWhere('description', 'like', '%driver%')
                    ->orderByDesc('created_at')
                    ->get();

                foreach ($activities as $activity) {
                    $history[] = [
                        'id' => (string) $activity->id,
                        'type' => 'assignment_activity',
                        'action' => $activity->description,
                        'description' => $activity->description,
                        'user_name' => optional($activity->causer)->name ?? 'System',
                        'user_id' => $activity->causer_id,
                        'created_at' => $activity->created_at->toISOString(),
                        'metadata' => $activity->properties ?? [],
                    ];
                }
            }

            // Get assignment records
            $vehicleAssignments = VehicleAssignment::where('booking_id', $bookingId)
                ->with(['vehicle', 'assignedBy'])
                ->orderByDesc('created_at')
                ->get();

            foreach ($vehicleAssignments as $assignment) {
                $history[] = [
                    'id' => (string) $assignment->id,
                    'type' => 'vehicle_assignment',
                    'action' => $assignment->status,
                    'description' => "Vehicle assignment: {$assignment->status}",
                    'user_name' => optional($assignment->assignedBy)->name ?? 'System',
                    'user_id' => $assignment->assigned_by,
                    'created_at' => $assignment->created_at->toISOString(),
                    'metadata' => [
                        'vehicle_id' => $assignment->vehicle_id,
                        'vehicle_name' => optional($assignment->vehicle)->name ?? optional($assignment->vehicle)->title,
                        'license_plate' => optional($assignment->vehicle)->license_plate,
                        'assignment_type' => $assignment->assignment_type,
                        'overlap_type' => $assignment->overlap_type,
                        'requires_approval' => $assignment->requires_approval,
                        'actual_start' => $assignment->actual_start?->toISOString(),
                        'actual_end' => $assignment->actual_end?->toISOString(),
                    ],
                ];
            }

            $driverAssignments = DriverAssignment::where('booking_id', $bookingId)
                ->with(['driver', 'assignedBy'])
                ->orderByDesc('created_at')
                ->get();

            foreach ($driverAssignments as $assignment) {
                $history[] = [
                    'id' => (string) $assignment->id,
                    'type' => 'driver_assignment',
                    'action' => $assignment->status,
                    'description' => "Driver assignment: {$assignment->status}",
                    'user_name' => optional($assignment->assignedBy)->name ?? 'System',
                    'user_id' => $assignment->assigned_by,
                    'created_at' => $assignment->created_at->toISOString(),
                    'metadata' => [
                        'driver_id' => $assignment->driver_id,
                        'driver_name' => optional($assignment->driver)->name,
                        'license_number' => optional($assignment->driver)->license_number ?? optional($assignment->driver)->license_no,
                        'assignment_type' => $assignment->assignment_type,
                        'overlap_type' => $assignment->overlap_type,
                        'requires_approval' => $assignment->requires_approval,
                        'actual_start' => $assignment->actual_start?->toISOString(),
                        'actual_end' => $assignment->actual_end?->toISOString(),
                    ],
                ];
            }

            // Sort by created_at descending
            usort($history, function ($a, $b) {
                return strtotime($b['created_at']) - strtotime($a['created_at']);
            });
        } catch (\Exception $e) {
            Log::error("Failed to get assignment history for booking {$bookingId}: " . $e->getMessage());
        }

        return $history;
    }
}
