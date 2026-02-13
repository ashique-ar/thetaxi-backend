<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Airport;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class AirportController extends Controller
{
    /**
     * Display a listing of airports
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = Airport::query();

            // Filter by active status
            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }

            // Search by name or code
            if ($request->has('search')) {
                $search = $request->input('search');
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'ILIKE', "%{$search}%")
                      ->orWhere('code', 'ILIKE', "%{$search}%")
                      ->orWhere('city', 'ILIKE', "%{$search}%");
                });
            }

            // Order by sort_order and name
            $query->ordered();

            $airports = $query->get();

            return response()->json([
                'status' => 'success',
                'data' => $airports,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve airports',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Store a newly created airport
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'code' => 'required|string|max:10|unique:airports,code',
                'city' => 'required|string|max:255',
                'country' => 'nullable|string|max:255',
                'latitude' => 'required|numeric|between:-90,90',
                'longitude' => 'required|numeric|between:-180,180',
                'is_default' => 'boolean',
                'is_active' => 'boolean',
                'sort_order' => 'nullable|integer',
                'description' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $airport = Airport::create($validator->validated());

            return response()->json([
                'status' => 'success',
                'message' => 'Airport created successfully',
                'data' => $airport,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create airport',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the specified airport
     */
    public function show(string $id): JsonResponse
    {
        try {
            $airport = Airport::findOrFail($id);

            return response()->json([
                'status' => 'success',
                'data' => $airport,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Airport not found',
            ], 404);
        }
    }

    /**
     * Update the specified airport
     */
    public function update(Request $request, string $id): JsonResponse
    {
        try {
            $airport = Airport::findOrFail($id);

            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|required|string|max:255',
                'code' => 'sometimes|required|string|max:10|unique:airports,code,' . $id,
                'city' => 'sometimes|required|string|max:255',
                'country' => 'nullable|string|max:255',
                'latitude' => 'sometimes|required|numeric|between:-90,90',
                'longitude' => 'sometimes|required|numeric|between:-180,180',
                'is_default' => 'boolean',
                'is_active' => 'boolean',
                'sort_order' => 'nullable|integer',
                'description' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $airport->update($validator->validated());

            return response()->json([
                'status' => 'success',
                'message' => 'Airport updated successfully',
                'data' => $airport,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update airport',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove the specified airport
     */
    public function destroy(string $id): JsonResponse
    {
        try {
            $airport = Airport::findOrFail($id);
            $airport->delete();

            return response()->json([
                'status' => 'success',
                'message' => 'Airport deleted successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete airport',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get active airports for dropdown/selection
     */
    public function getActive(): JsonResponse
    {
        try {
            $airports = Airport::active()->ordered()->get();

            return response()->json([
                'status' => 'success',
                'data' => $airports,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve active airports',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
