<?php

namespace App\Http\Controllers\Website;

use App\Http\Controllers\Controller;
use App\Models\Website\CmsContent;
use App\Models\Website\CmsContentType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class CmsController extends Controller
{
    protected \App\Services\BookingFlowService $bookingFlowService;
    protected \App\Services\CurrencyService $currencyService;
    protected \App\Services\DiscountService $discountService;

    public function __construct(
        \App\Services\BookingFlowService $bookingFlowService,
        \App\Services\CurrencyService $currencyService,
        \App\Services\DiscountService $discountService
    ) {
        $this->bookingFlowService = $bookingFlowService;
        $this->currencyService = $currencyService;
        $this->discountService = $discountService;
    }

    /**
     * Display a listing of content for a specific content type
     */
    public function index(string $contentTypeSlug, Request $request): View
    {
        Log::info("CMS Index: type={$contentTypeSlug}", $request->all());

        $contentType = CmsContentType::where('slug', $contentTypeSlug)
            ->where('is_active', true)
            ->firstOrFail();

        $query = CmsContent::published()
            ->byType($contentTypeSlug)
            ->with(['contentType']);

        // Search functionality
        if ($request->filled('search')) {
            $searchTerm = $request->get('search');
            $query->where(function ($q) use ($searchTerm) {
                $q->where('title', 'like', '%' . $searchTerm . '%')
                    ->orWhere('excerpt', 'like', '%' . $searchTerm . '%')
                    ->orWhere('body', 'like', '%' . $searchTerm . '%')
                    ->orWhere('author', 'like', '%' . $searchTerm . '%');
            });
        }

        // Featured filter
        if ($request->filled('featured') && $request->get('featured') === '1') {
            $query->where('is_featured', true);
        }

        // Sorting
        $sort = $request->get('sort', 'latest');
        switch ($sort) {
            case 'oldest':
                $query->orderBy('published_at', 'asc')
                    ->orderBy('created_at', 'asc');
                break;
            case 'title':
                $query->orderBy('title', 'asc');
                break;
            case 'latest':
            default:
                $query->orderBy('is_featured', 'desc')
                    ->orderBy('published_at', 'desc')
                    ->orderBy('created_at', 'desc');
                break;
        }

        $contents = $query->paginate(12)->appends($request->query());

        return view('cms.index', compact('contentType', 'contents'));
    }

    /**
     * Display the specified content
     */
    public function show(string $contentTypeSlug, string $contentSlug): View
    {
        Log::info("CMS Show: type={$contentTypeSlug}, slug={$contentSlug}");
        $contentType = CmsContentType::where('slug', $contentTypeSlug)
            ->where('is_active', true)
            ->firstOrFail();

        $content = CmsContent::published()
            ->byType($contentTypeSlug)
            ->where('slug', $contentSlug)
            ->with(['contentType'])
            ->firstOrFail();

        // Increment view count
        $content->incrementViews();

        // Get related content (same content type, excluding current)
        $relatedContents = CmsContent::published()
            ->byType($contentTypeSlug)
            ->where('id', '!=', $content->id)
            ->orderBy('is_featured', 'desc')
            ->orderBy('published_at', 'desc')
            ->limit(6)
            ->get();

        // --- Booking Integration ---

        // 1. Get current search parameters from session
        $sessionSearchParams = session()->get('current_search_params', []);
        $sessionService = session()->get('frontend_service');
        $pricingContext = session()->get('pricing_context');

        // 2. Merge with CMS content booking defaults
        // CMS content values take precedence for location, session takes precedence for dates
        // Note: content->service_type may be stored as a numeric ID or a code string. Resolve both.
        $serviceTypeRaw = $content->service_type ?? ($sessionSearchParams['service_type'] ?? 'airport_transfers'); // Raw value from content/session

        // Resolve canonical service type code for the frontend (booking form expects a code like 'airport_transfers')
        $serviceTypeForView = $serviceTypeRaw;
        try {
            // First try to match by code (string comparison)
            $resolved = \App\Models\Service\ServiceType::where('code', $serviceTypeRaw)->first();

            // If not found by code and it looks like a UUID, try by ID
            if (!$resolved && \Illuminate\Support\Str::isUuid($serviceTypeRaw)) {
                $resolved = \App\Models\Service\ServiceType::find($serviceTypeRaw);
            }

            if ($resolved) {
                $serviceTypeForView = $resolved->code;
            }
        } catch (\Exception $e) {
            Log::warning('CMS: failed to resolve service type for view', ['raw' => $serviceTypeRaw, 'error' => $e->getMessage()]);
        }

        // If content has specific pickup/dropoff, use them. Otherwise fallback to session.
        $pickupLocation = $content->pickup_location ?: ($sessionSearchParams['pickup_location'] ?? null);
        $dropoffLocation = $content->dropoff_location ?: ($sessionSearchParams['dropoff_location'] ?? null);

        // Dates: Session dates usually override defaults; otherwise use current date.
        // CMS content defines a minimum number of days (min_days); pick-up defaults to today when not in session.
        $pickupDate = $sessionSearchParams['from_date'] ?? \Carbon\Carbon::today()->format('Y-m-d');
        // Use session-provided time where available, otherwise default to 10:00
        $pickupTime = $sessionSearchParams['from_time'] ?? ($content->pickup_time ?? '10:00');

        // Compute dropoff date based on min_days when applicable
        $minDays = $content->min_days ?? 1;
        $dropoffDate = null;
        if (!empty($minDays) && (int) $minDays > 1) {
            try {
                $dropoffDate = \Carbon\Carbon::parse($pickupDate)->addDays(((int) $minDays) - 1)->format('Y-m-d');
            } catch (\Exception $e) {
                $dropoffDate = null;
            }
        }

        // Construct the effective search params with structured location data (use raw value for backend calls)
        // Helper to safely extract location data from session (which might be string or array)
        $extractLocationData = function ($sessionLocation, $addressKey = 'address', $latKey = 'latitude', $lngKey = 'longitude') {
            if (empty($sessionLocation)) {
                return ['address' => null, 'latitude' => null, 'longitude' => null];
            }
            
            if (is_string($sessionLocation)) {
                return ['address' => $sessionLocation, 'latitude' => null, 'longitude' => null];
            }
            
            if (is_array($sessionLocation)) {
                return [
                    'address' => $sessionLocation[$addressKey] ?? $sessionLocation['address'] ?? null,
                    'latitude' => $sessionLocation[$latKey] ?? $sessionLocation['lat'] ?? $sessionLocation['latitude'] ?? null,
                    'longitude' => $sessionLocation[$lngKey] ?? $sessionLocation['lng'] ?? $sessionLocation['longitude'] ?? null,
                ];
            }
            
            return ['address' => null, 'latitude' => null, 'longitude' => null];
        };

        $sessionPickup = $extractLocationData($sessionSearchParams['pickup_location'] ?? null);
        $sessionDropoff = $extractLocationData($sessionSearchParams['dropoff_location'] ?? null);

        $searchParams = array_merge($sessionSearchParams, [
            'service_type' => $serviceTypeRaw,
            'pickup_location' => [
                'address' => $content->pickup_location ?: $sessionPickup['address'],
                'latitude' => $content->pickup_lat ?: $sessionPickup['latitude'],
                'longitude' => $content->pickup_lng ?: $sessionPickup['longitude'],
            ],
            'dropoff_location' => [
                'address' => $content->dropoff_location ?: $sessionDropoff['address'],
                'latitude' => $content->dropoff_lat ?: $sessionDropoff['latitude'],
                'longitude' => $content->dropoff_lng ?: $sessionDropoff['longitude'],
            ],
            'from_date' => $pickupDate,
            'from_time' => $pickupTime,
            'passengers' => $sessionSearchParams['passengers'] ?? 1,
        ]);

        // If we have enough info, try to fetch suggestions
        $suggestedVehicles = [];
        $vehiclePagination = null;

        // We need at least a date and service type to check availability
        if (!empty($searchParams['from_date']) && !empty($searchParams['service_type'])) {
            try {
                // Ensure we have a valid ServiceType ID if it's a code
                // Use the raw value (may be code or id) to resolve backend record
                $backendServiceType = \App\Models\Service\ServiceType::where(function ($query) use ($serviceTypeRaw) {
                    $query->where('code', $serviceTypeRaw);
                    // Only check by ID if it's a valid UUID
                    if (\Illuminate\Support\Str::isUuid($serviceTypeRaw)) {
                        $query->orWhere('id', $serviceTypeRaw);
                    }
                })
                    ->where('is_active', true)
                    ->first();

                if ($backendServiceType) {
                    // Use backend ID for availability lookup
                    $searchParams['service_type'] = $backendServiceType->id; // Use ID for service

                    // Fetch availability
                    $availabilityData = $this->bookingFlowService->getAvailableVehicleGroups($searchParams, true);
                    $suggestedVehicles = $availabilityData['data'] ?? [];

                    // Debug log to inspect why groups may be quotation-only or empty
                    try {
                        $groupSummaries = array_map(function ($g) {
                            return [
                                'id' => $g['id'] ?? null,
                                'name' => $g['name'] ?? null,
                                'quotation_only' => $g['quotation_only'] ?? null,
                                'quotation_only_reasons' => $g['quotation_only_reasons'] ?? null,
                                'pricing_info' => isset($g['pricing_info']) ? ['base_amount' => $g['pricing_info']['base_amount'] ?? null, 'has_discount' => $g['pricing_info']['has_discount'] ?? false] : null,
                                'available_count' => $g['available_count'] ?? null,
                                'total_count' => $g['total_count'] ?? null,
                            ];
                        }, $suggestedVehicles ?: []);

                        Log::debug('CMS Booking Suggestion Details', [
                            'search_params' => $searchParams,
                            'result_count' => count($suggestedVehicles),
                            'groups' => $groupSummaries
                        ]);

                        // If no suggestions or all groups are quotation-only, attempt a relaxed fallback
                        $allQuoted = true;
                        foreach ($suggestedVehicles as $g) {
                            if (!($g['quotation_only'] ?? false) && ($g['allow_booking'] ?? false)) {
                                $allQuoted = false;
                                break;
                            }
                        }

                        if (count($suggestedVehicles) === 0 || $allQuoted) {
                            Log::info('CMS Show: No suitable suggested vehicles or all quoted; attempting relaxed search without pickup/dropoff to broaden results');
                            $fallbackParams = $searchParams;
                            unset($fallbackParams['pickup_location'], $fallbackParams['dropoff_location']);
                            try {
                                $fallbackAvailability = $this->bookingFlowService->getAvailableVehicleGroups($fallbackParams, true);
                                $fallbackGroups = $fallbackAvailability['data'] ?? [];
                                Log::debug('CMS Booking Suggestion Fallback Details', [
                                    'fallback_result_count' => count($fallbackGroups),
                                ]);
                                if (count($fallbackGroups) > 0) {
                                    $suggestedVehicles = $fallbackGroups;
                                    Log::info('CMS Show: Using fallback vehicle suggestions for display', ['count' => count($suggestedVehicles)]);
                                }
                            } catch (\Exception $e) {
                                Log::warning('CMS Show fallback availability failed: ' . $e->getMessage());
                            }
                        }
                    } catch (\Exception $e) {
                        Log::debug('CMS Booking Suggestion - logging failed', ['error' => $e->getMessage()]);
                    }
                } else {
                    // Helpful debug log for missing service type mapping
                    Log::debug('CMS Show: service type not found for availability lookup', ['service_type_raw' => $serviceTypeRaw]);
                }
            } catch (\Exception $e) {
                Log::warning("CMS Booking Suggestion Failed: " . $e->getMessage());
                // Fail silently for the suggestion part, don't break the page
            }
        }

        // Sort suggested vehicles by price (ascending). Priced groups first, unpriced last.
        if (!empty($suggestedVehicles) && is_array($suggestedVehicles)) {
            usort($suggestedVehicles, function ($a, $b) {
                $pa = $a['pricing_info']['base_amount'] ?? $a['pricing']['base_amount'] ?? null;
                $pb = $b['pricing_info']['base_amount'] ?? $b['pricing']['base_amount'] ?? null;
                $pa = $pa !== null ? (float) $pa : null;
                $pb = $pb !== null ? (float) $pb : null;

                if ($pa === null && $pb === null)
                    return 0;
                if ($pa === null)
                    return 1; // a goes after b
                if ($pb === null)
                    return -1; // b goes after a

                return $pa <=> $pb;
            });
        }

        // Prepare search object for the view (compatible with booking-form component)
        $search = (object) [
            'service_type' => $serviceTypeForView, // use canonical code for front-end
            'pickup_location' => $pickupLocation,
            'dropoff_location' => $dropoffLocation,
            'from_date' => $pickupDate,
            'pickup_date' => $pickupDate, // Alias
            'from_time' => $pickupTime,
            'pickup_time' => $pickupTime, // Alias
            'to_date' => $dropoffDate ?? ($searchParams['to_date'] ?? $pickupDate), // Fallback to computed dropoff or provided
            'dropoff_date' => $dropoffDate ?? ($searchParams['to_date'] ?? $pickupDate), // Alias
            'passengers' => $searchParams['passengers'] ?? 1,
            // Add lat/lng if available in content (assuming we might add those later or parse them)
        ];

        // Ensure we have a persistent search id so the vehicle cards can show Book Now and send the correct search context
        if (!session()->has('current_search_id')) {
            session(['current_search_id' => (string) \Illuminate\Support\Str::uuid()]);
        }

        // Persist current search params in session so cart and booking flows can use them
        session(['current_search_params' => $searchParams]);

        return view('cms.show', compact('contentType', 'content', 'relatedContents', 'search', 'suggestedVehicles'));
    }

    /**
     * Display featured content across all content types
     */
    public function featured(): View
    {
        $featuredContent = CmsContent::published()
            ->featured()
            ->with(['contentType'])
            ->orderBy('published_at', 'desc')
            ->paginate(12);

        return view('cms.featured', compact('featuredContent'));
    }

    /**
     * Search content across all types
     */
    public function search(Request $request): View
    {
        $query = $request->get('q', '');
        $contentTypeSlug = $request->get('type', '');

        $contents = CmsContent::published()
            ->with(['contentType'])
            ->when($query, function ($q) use ($query) {
                $q->where(function ($subQuery) use ($query) {
                    $subQuery->where('title', 'like', "%{$query}%")
                        ->orWhere('excerpt', 'like', "%{$query}%")
                        ->orWhere('body', 'like', "%{$query}%");
                });
            })
            ->when($contentTypeSlug, function ($q) use ($contentTypeSlug) {
                $q->byType($contentTypeSlug);
            })
            ->orderBy('published_at', 'desc')
            ->paginate(12);

        $contentTypes = CmsContentType::where('is_active', true)
            ->orderBy('title')
            ->get();

        // --- Booking Integration for Search Page ---
        // Reuse similar logic as 'show' to populate search form
        $sessionSearchParams = session()->get('current_search_params', []);
        $search = (object) $sessionSearchParams;

        return view('cms.search', compact('contents', 'contentTypes', 'query', 'contentTypeSlug', 'search'));
    }
}
