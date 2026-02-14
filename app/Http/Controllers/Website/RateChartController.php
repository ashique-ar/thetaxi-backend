<?php

namespace App\Http\Controllers\Website;

use App\Http\Controllers\Controller;
use App\Models\Vehicle\VehicleGroup;
use App\Models\Service\ServiceType;
use App\Services\BookingFlowService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class RateChartController extends Controller
{
    protected BookingFlowService $bookingFlowService;

    public function __construct(BookingFlowService $bookingFlowService)
    {
        $this->bookingFlowService = $bookingFlowService;
    }

    /**
     * Display the rate chart page with daily and monthly rental rates
     */
    public function index(): View
    {
        try {
            // Get day rental service type (correct code is 'day_rental')
            $dayRentalService = ServiceType::where('code', 'day_rental')
                ->where('is_active', true)
                ->first();

            if (!$dayRentalService) {
                Log::warning('Day rental service type not found');
                return view('rate-chart', [
                    'vehicleGroups' => [],
                    'error' => 'Day rental service is not configured'
                ]);
            }

            // Get all active vehicle groups with relationships
            $vehicleGroups = VehicleGroup::with([
                'category:id,name',
                'make:id,name',
                'model:id,name',
                'grade:id,name',
                'class:id,name',
                'fuelType:id,name',
                'transmission:id,name',
                'vehicles' => function ($query) {
                    $query->where('is_active', true)
                        ->select('id', 'vehicle_group_id', 'thumbnail')
                        ->limit(1);
                }
            ])
                ->where('is_active', true)
                ->orderBy('name')
                ->get();

            $rateData = [];

            foreach ($vehicleGroups as $group) {
                // Calculate daily rate (1 day)
                $dailyRate = $this->calculateRate($group, $dayRentalService, 1);
                
                // Calculate monthly rate (30 days)
                $monthlyRate = $this->calculateRate($group, $dayRentalService, 30);

                // Get vehicle thumbnail
                $thumbnail = $group->thumbnail ?? null;
                if (!$thumbnail && $group->vehicles->isNotEmpty()) {
                    $vehicle = $group->vehicles->first();
                    $thumbnail = $vehicle->thumbnail;
                }

                $rateData[] = [
                    'id' => $group->id,
                    'name' => $group->name,
                    'description' => $group->description,
                    'category' => $group->category->name ?? 'N/A',
                    'make' => $group->make->name ?? 'N/A',
                    'model' => $group->model->name ?? 'N/A',
                    'grade' => $group->grade->name ?? 'N/A',
                    'class' => $group->class->name ?? 'N/A',
                    'fuel_type' => $group->fuelType->name ?? 'N/A',
                    'transmission' => $group->transmission->name ?? 'N/A',
                    'seating_capacity' => $group->seating_capacity ?? $group->passengers_count ?? 'N/A',
                    'passengers_count' => $group->passengers_count,
                    'hand_luggages' => $group->hand_luggages,
                    'air_conditioning' => $group->air_conditioning,
                    'thumbnail' => $thumbnail,
                    'daily_rate' => $dailyRate,
                    'monthly_rate' => $monthlyRate,
                    'has_pricing' => $dailyRate['amount'] > 0 || $monthlyRate['amount'] > 0,
                ];
            }

            // Sort by daily rate (lowest first), then by name
            usort($rateData, function ($a, $b) {
                if ($a['daily_rate']['amount'] == $b['daily_rate']['amount']) {
                    return strcmp($a['name'], $b['name']);
                }
                return $a['daily_rate']['amount'] <=> $b['daily_rate']['amount'];
            });

            return view('rate-chart', [
                'vehicleGroups' => $rateData,
                'lastUpdated' => Carbon::now()->format('F d, Y')
            ]);

        } catch (\Exception $e) {
            Log::error('Rate chart error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            return view('rate-chart', [
                'vehicleGroups' => [],
                'error' => 'Unable to load rate chart. Please try again later.'
            ]);
        }
    }

    /**
     * Calculate rate for a vehicle group for specified duration
     */
    private function calculateRate(VehicleGroup $group, ServiceType $serviceType, int $days): array
    {
        try {
            $fromDate = Carbon::today();
            $toDate = Carbon::today()->addDays($days);

            $params = [
                'service_type' => $serviceType->id,
                'service_type_id' => $serviceType->id,
                'vehicle_group_id' => $group->id,
                'from_date' => $fromDate->format('Y-m-d'),
                'to_date' => $toDate->format('Y-m-d'),
                'from_time' => '10:00',
                'to_time' => '10:00',
                'mode' => 'preview'
            ];

            $availability = $this->bookingFlowService->getAvailableVehicleGroups($params, true);

            if (isset($availability['data']) && count($availability['data']) > 0) {
                $vehicleData = collect($availability['data'])->firstWhere('id', $group->id);
                
                if ($vehicleData && isset($vehicleData['pricing_info']['base_amount'])) {
                    return [
                        'amount' => $vehicleData['pricing_info']['base_amount'],
                        'currency' => 'LKR',
                        'per_day' => $days > 1 ? round($vehicleData['pricing_info']['base_amount'] / $days, 2) : $vehicleData['pricing_info']['base_amount'],
                        'breakdown' => $vehicleData['pricing_info']['breakdown'] ?? null,
                    ];
                }
            }

            return [
                'amount' => 0,
                'currency' => 'LKR',
                'per_day' => 0,
                'breakdown' => null,
            ];

        } catch (\Exception $e) {
            Log::warning("Rate calculation failed for vehicle group {$group->id}, {$days} days: " . $e->getMessage());
            
            return [
                'amount' => 0,
                'currency' => 'LKR',
                'per_day' => 0,
                'breakdown' => null,
            ];
        }
    }
}
