<?php

namespace App\Http\Controllers\Website;

use App\Http\Controllers\Controller;
use App\Models\Vehicle\VehicleGroup;
use App\Models\Service\ServiceType;
use App\Models\Vehicle\VehiclePricing\VehicleGroupServicePricingSetting;
use App\Services\BookingFlowService;
use App\Services\CurrencyService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class RateChartController extends Controller
{
    protected BookingFlowService $bookingFlowService;
    protected CurrencyService $currencyService;

    public function __construct(BookingFlowService $bookingFlowService, CurrencyService $currencyService)
    {
        $this->bookingFlowService = $bookingFlowService;
        $this->currencyService = $currencyService;
    }

    /**
     * Display the rate chart page with daily and monthly rental rates
     */
    public function index(): View
    {
        try {
            // Get day rental service type (correct code is 'day_rental')
            $dayRentalService = ServiceType::publicContext()
                ->where('code', 'day_rental')
                ->where('is_active', true)
                ->first();

            if (!$dayRentalService) {
                Log::warning('Day rental service type not found');
                return view('rate-chart', [
                    'vehicleGroups' => [],
                    'error' => 'Day rental service is not configured'
                ]);
            }

            $selectedCurrency = $this->currencyService->getSelectedCurrency();
            $cacheDate = Carbon::today()->format('Ymd');
            $vehicleGroupCacheToken = md5(json_encode([
                VehicleGroup::withTrashed()->max('updated_at'),
                VehicleGroup::withTrashed()->max('deleted_at'),
            ]));
            $cacheKey = "rate_chart:day_rental:v6:{$dayRentalService->id}:{$selectedCurrency}:{$cacheDate}:{$vehicleGroupCacheToken}";

            $cachedRateChart = Cache::store('file')->remember($cacheKey, now()->addHours(4), function () use ($dayRentalService, $selectedCurrency) {
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
                $serviceSettings = VehicleGroupServicePricingSetting::query()
                    ->where('service_type_id', $dayRentalService->id)
                    ->get()
                    ->keyBy('vehicle_group_id');

                $rateData = [];

                foreach ($vehicleGroups as $group) {
                    $serviceSetting = $serviceSettings->get($group->id);

                    if ($serviceSetting?->is_hidden) {
                        continue;
                    }

                    $isInquiryOnly = (bool) ($serviceSetting?->is_inquiry_only ?? false);

                    // Calculate daily rate (1 day)
                    $dailyRate = $this->calculateRate($group, $dayRentalService, 1, $selectedCurrency);

                    // Calculate monthly rate (30 days)
                    $monthlyRate = $this->calculateRate($group, $dayRentalService, 30, $selectedCurrency);

                    if ($dailyRate['amount'] == 0) {
                        Log::warning("Rate chart: No pricing for vehicle group", [
                            'vehicle_group_id' => $group->id,
                            'vehicle_group_name' => $group->name,
                            'daily_rate_response' => $dailyRate,
                            'monthly_rate_response' => $monthlyRate
                        ]);
                    }

                    // Get vehicle thumbnail - handle array or string format
                    $thumbnail = null;
                    $thumbnailRaw = $group->thumbnail ?? null;

                    if ($thumbnailRaw) {
                        if (is_array($thumbnailRaw)) {
                            // Handle array format: ['path' => '...'] or [0 => '...']
                            $thumbnail = $thumbnailRaw['path'] ?? ($thumbnailRaw[0] ?? null);
                        } else {
                            $thumbnail = $thumbnailRaw;
                        }
                    }

                    // Fallback to first vehicle's thumbnail if group has no thumbnail
                    if (!$thumbnail && $group->vehicles->isNotEmpty()) {
                        $vehicle = $group->vehicles->first();
                        $vehicleThumbnailRaw = $vehicle->thumbnail ?? null;

                        if ($vehicleThumbnailRaw) {
                            if (is_array($vehicleThumbnailRaw)) {
                                $thumbnail = $vehicleThumbnailRaw['path'] ?? ($vehicleThumbnailRaw[0] ?? null);
                            } else {
                                $thumbnail = $vehicleThumbnailRaw;
                            }
                        }
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
                        'extra_km_rate' => $dailyRate['extra_km_price'] ?? $monthlyRate['extra_km_price'] ?? null,
                        'extra_km_rate_lkr' => $dailyRate['extra_km_price_lkr'] ?? $monthlyRate['extra_km_price_lkr'] ?? null,
                        'has_pricing' => $dailyRate['amount'] > 0 || $monthlyRate['amount'] > 0,
                        'is_inquiry_only' => $isInquiryOnly,
                    ];
                }

                // Sort priced vehicles by daily rate first; keep quotation-only/no-rate rows after them by name.
                usort($rateData, function ($a, $b) {
                    $aHasRate = ($a['daily_rate']['amount'] ?? 0) > 0;
                    $bHasRate = ($b['daily_rate']['amount'] ?? 0) > 0;

                    if ($aHasRate !== $bHasRate) {
                        return $aHasRate ? -1 : 1;
                    }

                    if ($a['daily_rate']['amount'] == $b['daily_rate']['amount']) {
                        return strcmp($a['name'], $b['name']);
                    }
                    return $a['daily_rate']['amount'] <=> $b['daily_rate']['amount'];
                });

                return [
                    'vehicle_groups' => $rateData,
                    'generated_at' => Carbon::now()->format('F d, Y h:i A'),
                ];
            });

            return view('rate-chart', [
                'vehicleGroups' => $cachedRateChart['vehicle_groups'] ?? [],
                'lastUpdated' => $cachedRateChart['generated_at'] ?? Carbon::now()->format('F d, Y h:i A'),
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
    private function calculateRate(VehicleGroup $group, ServiceType $serviceType, int $days, ?string $selectedCurrency = null): array
    {
        try {
            $fromDate = Carbon::today()->startOfDay();
            $toDate = Carbon::today()->startOfDay();
            
            // For day rental pricing, we need to account for how the system calculates days
            // The system uses: diffInDays() + 1
            // So for 1 day: same date = 0 diff + 1 = 1 day ✓
            // For 30 days: we need 29 days diff + 1 = 30 days
            if ($days > 1) {
                $toDate = Carbon::today()->addDays($days - 1)->startOfDay();
            }

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


            $pricingResult = $this->bookingFlowService->calculatePricing([
                'service_type' => $serviceType->id,
                'service_type_id' => $serviceType->id,
                'vehicle_group_id' => $group->id,
                'from_date' => $params['from_date'],
                'to_date' => $params['to_date'],
                'from_time' => $params['from_time'],
                'to_time' => $params['to_time'],
                'currency' => 'LKR',
                'base_currency' => 'LKR',
                'is_preview_calculation' => true,
            ]);

            $baseAmountLKR = (float) ($pricingResult['summary']['total'] ?? 0);

            if ($baseAmountLKR > 0) {
                $selectedCurrency = $selectedCurrency ?: $this->currencyService->getSelectedCurrency();
                $convertedAmount = $this->currencyService->convertFromLKR($baseAmountLKR, $selectedCurrency);
                $perDayAmount = $days > 1 ? round($convertedAmount / $days, 2) : $convertedAmount;

                $basePricing = $pricingResult['base_pricing'] ?? [];
                $distanceDetails = $basePricing['distance_details'] ?? [];
                $extraKmPriceLkr = isset($distanceDetails['extra_km_price']) && $distanceDetails['extra_km_price'] !== null
                    ? (float) $distanceDetails['extra_km_price']
                    : null;
                $extraKmPrice = null;

                if ($extraKmPriceLkr !== null && $extraKmPriceLkr > 0) {
                    $extraKmPrice = $this->currencyService->convertFromLKR($extraKmPriceLkr, $selectedCurrency);
                }

                return [
                    'amount' => $convertedAmount,
                    'amount_lkr' => $baseAmountLKR,
                    'currency' => $selectedCurrency,
                    'per_day' => $perDayAmount,
                    'breakdown' => $basePricing['breakdown'] ?? null,
                    'extra_km_price' => $extraKmPrice,
                    'extra_km_price_lkr' => $extraKmPriceLkr,
                ];
            }

            $selectedCurrency = $selectedCurrency ?: $this->currencyService->getSelectedCurrency();
            return [
                'amount' => 0,
                'amount_lkr' => 0,
                'currency' => $selectedCurrency,
                'per_day' => 0,
                'breakdown' => null,
                'extra_km_price' => null,
                'extra_km_price_lkr' => null,
            ];

        } catch (\Exception $e) {
            Log::error("Rate calculation failed for vehicle group {$group->id}, {$days} days: " . $e->getMessage(), [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            $selectedCurrency = $selectedCurrency ?: $this->currencyService->getSelectedCurrency();
            return [
                'amount' => 0,
                'amount_lkr' => 0,
                'currency' => $selectedCurrency,
                'per_day' => 0,
                'breakdown' => null,
                'extra_km_price' => null,
                'extra_km_price_lkr' => null,
            ];
        }
    }
}
