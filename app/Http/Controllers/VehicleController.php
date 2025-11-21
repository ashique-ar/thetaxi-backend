<?php

namespace App\Http\Controllers;

use App\Models\Vehicle\VehicleGroup;
use App\Models\BookingSearch;
use App\Models\ServiceType;
use App\Services\BookingFlowService;
use App\Services\CartService;
use Illuminate\Http\Request;

class VehicleController extends Controller
{
    protected BookingFlowService $bookingFlowService;
    protected CartService $cartService;

    public function __construct(BookingFlowService $bookingFlowService, CartService $cartService)
    {
        $this->bookingFlowService = $bookingFlowService;
        $this->cartService = $cartService;
    }
    
    /**
     * Display vehicle details with booking functionality
     */
    public function show(Request $request, string $id)
    {
        // Find the vehicle group
        $vehicleGroup = VehicleGroup::with([
            'category',
            'class',
            'transmission',
            'fuelType',
        ])->findOrFail($id);

        // Get search context if provided
        $searchId = $request->query('search');
        $search = null;
        $searchData = [];
        
        if ($searchId) {
            $search = BookingSearch::find($searchId);
            if ($search) {
                $searchData = [
                    'service_type' => $search->service_type ?? 'point_to_point',
                    'pickup_date' => $search->from_date ?? now()->format('Y-m-d'),
                    'return_date' => $search->to_date ?? now()->addDay()->format('Y-m-d'),
                    'pickup_time' => $search->from_time ?? '10:00',
                    'return_time' => $search->to_time ?? '18:00',
                    'pickup_location' => $search->pickup_location ?? '',
                    'dropoff_location' => $search->dropoff_location ?? '',
                    'pickup_lat' => $search->pickup_lat,
                    'pickup_lng' => $search->pickup_lng,
                    'dropoff_lat' => $search->dropoff_lat,
                    'dropoff_lng' => $search->dropoff_lng,
                ];
            }
        }
        
        // Default search data if no search context
        if (empty($searchData)) {
            $searchData = [
                'service_type' => 'point_to_point',
                'pickup_date' => now()->format('Y-m-d'),
                'return_date' => now()->addDay()->format('Y-m-d'),
                'pickup_time' => '10:00',
                'return_time' => '18:00',
                'pickup_location' => '',
                'dropoff_location' => '',
                'pickup_lat' => null,
                'pickup_lng' => null,
                'dropoff_lat' => null,
                'dropoff_lng' => null,
            ];
        }

        // Get available service types
        $serviceTypes = ServiceType::whereNull('deleted_at')
            ->select('id', 'code', 'name', 'description')
            ->get();

        // Calculate initial pricing based on search data
        $pricing = $this->calculatePricing($vehicleGroup, $searchData);
        
        return view('vehicle-details', compact(
            'vehicleGroup',
            'searchData',
            'serviceTypes',
            'pricing',
            'search'
        ));
    }

    /**
     * Calculate pricing for vehicle with given parameters
     */
    private function calculatePricing($vehicleGroup, $searchData)
    {
        try {
            // Get service type
            $serviceType = ServiceType::where('code', $searchData['service_type'])->first();
            if (!$serviceType) {
                return ['base_amount' => 0, 'currency' => 'LKR', 'error' => 'Invalid service type'];
            }

            // Build pricing parameters
            $pricingParams = [
                'service_type' => $serviceType->id,
                'vehicle_groups' => [$vehicleGroup->id],
                'from_date' => $searchData['pickup_date'],
                'from_time' => $searchData['pickup_time'],
                'to_date' => $searchData['return_date'],
                'to_time' => $searchData['return_time'],
                'pickup_location' => [
                    'address' => $searchData['pickup_location'],
                    'latitude' => $searchData['pickup_lat'],
                    'longitude' => $searchData['pickup_lng']
                ],
                'dropoff_location' => [
                    'address' => $searchData['dropoff_location'],
                    'latitude' => $searchData['dropoff_lat'],
                    'longitude' => $searchData['dropoff_lng']
                ]
            ];

            // Get pricing from BookingFlowService
            $availabilityData = $this->bookingFlowService->getAvailableVehicleGroups($pricingParams);

            foreach ($availabilityData as $vehicleData) {
                if ($vehicleData['id'] == $vehicleGroup->id) {
                    return $vehicleData['pricing_info'] ?? ['base_amount' => 0, 'currency' => 'LKR'];
                }
            }

            return ['base_amount' => 0, 'currency' => 'LKR', 'error' => 'Pricing not available'];
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Vehicle pricing calculation error', [
                'vehicle_id' => $vehicleGroup->id,
                'search_data' => $searchData,
                'error' => $e->getMessage()
            ]);
            
            return ['base_amount' => 0, 'currency' => 'LKR', 'error' => $e->getMessage()];
        }
    }

    /**
     * Update pricing via AJAX when search parameters change
     */
    public function updatePricing(Request $request, string $id)
    {
        $vehicleGroup = VehicleGroup::findOrFail($id);
        
        $searchData = [
            'service_type' => $request->input('service_type', 'point_to_point'),
            'pickup_date' => $request->input('pickup_date', now()->format('Y-m-d')),
            'return_date' => $request->input('return_date', now()->addDay()->format('Y-m-d')),
            'pickup_time' => $request->input('pickup_time', '10:00'),
            'return_time' => $request->input('return_time', '18:00'),
            'pickup_location' => $request->input('pickup_location', ''),
            'dropoff_location' => $request->input('dropoff_location', ''),
            'pickup_lat' => $request->input('pickup_lat'),
            'pickup_lng' => $request->input('pickup_lng'),
            'dropoff_lat' => $request->input('dropoff_lat'),
            'dropoff_lng' => $request->input('dropoff_lng'),
        ];

        $pricing = $this->calculatePricing($vehicleGroup, $searchData);
        
        return response()->json([
            'success' => true,
            'pricing' => $pricing,
            'search_data' => $searchData
        ]);
    }
}
