<?php

namespace App\Http\Controllers;

use App\Models\Vehicle\VehicleGroup;
use App\Models\BookingSearch;
use App\Services\BookingSearchService;
use Illuminate\Http\Request;

class VehicleController extends Controller
{
    protected BookingSearchService $searchService;
    
    public function __construct(BookingSearchService $searchService)
    {
        $this->searchService = $searchService;
    }
    
    /**
     * Display vehicle group details
     */
    public function show(string $id, Request $request)
    {
        $vehicleGroup = VehicleGroup::with([
            'grade',
            'make',
            'model',
            'transmission',
            'fuelType',
            'category',
            'class',
            'vehicles' => function($query) {
                $query->where('is_active', true);
            }
        ])->findOrFail($id);
        
        // Get search context if provided
        $search = null;
        $pricing = null;
        
        if ($request->has('search')) {
            $search = $this->searchService->getSearch($request->input('search'));
            
            if ($search) {
                // Calculate pricing for this specific group
                $results = $this->searchService->searchVehicleGroups($search);
                
                foreach ($results as $result) {
                    if ($result['vehicle_group']->id === $id) {
                        $pricing = $result['pricing'];
                        break;
                    }
                }
            }
        }
        
        return view('vehicle-details', compact('vehicleGroup', 'search', 'pricing'));
    }
}
