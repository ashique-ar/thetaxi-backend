<?php

namespace App\Services;

use App\Models\BookingSearch;
use App\Models\Vehicle\VehicleGroup;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class BookingSearchService
{
    /**
     * Search for available vehicles based on criteria
     */
    public function searchVehicles(array $searchCriteria): Collection
    {
        $query = VehicleGroup::where('is_active', true);
        
        // Apply search filters
        if (!empty($searchCriteria['location'])) {
            // Add location-based filtering logic here
            $query->where('available_locations', 'LIKE', '%' . $searchCriteria['location'] . '%');
        }
        
        if (!empty($searchCriteria['vehicle_type'])) {
            $query->where('vehicle_type', $searchCriteria['vehicle_type']);
        }
        
        if (!empty($searchCriteria['date_from']) && !empty($searchCriteria['date_to'])) {
            // Add availability checking logic here
            // This would typically check against bookings table
        }
        
        return $query->get();
    }
    
    /**
     * Get featured vehicles
     */
    public function getFeaturedVehicles(int $limit = 8): Collection
    {
        return VehicleGroup::where('is_active', true)
            ->where('is_featured', true)
            ->limit($limit)
            ->get();
    }
    
    /**
     * Process search request from form
     */
    public function processSearchRequest(Request $request): array
    {
        $searchData = [
            'pickup_location' => $request->input('pickup_location'),
            'return_location' => $request->input('return_location'),
            'pickup_date' => $request->input('pickup_date'),
            'return_date' => $request->input('return_date'),
            'vehicle_type' => $request->input('vehicle_type'),
            'passengers' => $request->input('passengers', 1)
        ];
        
        // Store search in session for later use
        session()->put('last_search', $searchData);
        
        return $searchData;
    }
    
    /**
     * Get last search criteria from session
     */
    public function getLastSearch(): array
    {
        return session()->get('last_search', []);
    }
    
    /**
     * Save search criteria to database (for tracking/analytics)
     */
    public function saveSearchCriteria(array $criteria): ?BookingSearch
    {
        try {
            return BookingSearch::create([
                'pickup_location' => $criteria['pickup_location'] ?? null,
                'return_location' => $criteria['return_location'] ?? null,
                'pickup_date' => $criteria['pickup_date'] ?? null,
                'return_date' => $criteria['return_date'] ?? null,
                'vehicle_type' => $criteria['vehicle_type'] ?? null,
                'passengers' => $criteria['passengers'] ?? 1,
                'search_ip' => request()->ip(),
                'user_agent' => request()->userAgent()
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to save search criteria: ' . $e->getMessage());
            return null;
        }
    }
}