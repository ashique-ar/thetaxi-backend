<?php

namespace App\Http\Controllers\Api\Driver;

use App\Http\Controllers\Controller;
use App\Http\Requests\Driver\DriverBattaRuleRequest;
use App\Http\Resources\Driver\DriverBattaRuleResource;
use App\Models\Driver\DriverBattaRule;
use Illuminate\Http\Request;

class DriverBattaRuleController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:driver-batta-rules.view')->only(['index', 'show']);
        $this->middleware('permission:driver-batta-rules.create')->only(['store']);
        $this->middleware('permission:driver-batta-rules.edit')->only(['update']);
        $this->middleware('permission:driver-batta-rules.delete')->only(['destroy']);
    }

    public function index(Request $request)
    {
        $query = DriverBattaRule::with('vehicleGroup')
            ->when($request->filled('vehicle_group_id'), fn ($q) => $q->where('vehicle_group_id', $request->vehicle_group_id))
            ->when($request->filled('batta_category'), fn ($q) => $q->where('batta_category', $request->batta_category))
            ->when($request->filled('is_active'), fn ($q) => $q->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOL)))
            ->orderBy('batta_category')
            ->orderByDesc('effective_from');

        return DriverBattaRuleResource::collection($query->paginate($request->per_page ?? 25));
    }

    public function store(DriverBattaRuleRequest $request)
    {
        $rule = DriverBattaRule::create($request->validated());

        return response()->json([
            'status' => 'success',
            'message' => 'Driver batta rule created',
            'data' => new DriverBattaRuleResource($rule->load('vehicleGroup')),
        ], 201);
    }

    public function show(DriverBattaRule $driverBattaRule)
    {
        return response()->json([
            'status' => 'success',
            'data' => new DriverBattaRuleResource($driverBattaRule->load('vehicleGroup')),
        ]);
    }

    public function update(DriverBattaRuleRequest $request, DriverBattaRule $driverBattaRule)
    {
        $driverBattaRule->update($request->validated());

        return response()->json([
            'status' => 'success',
            'message' => 'Driver batta rule updated',
            'data' => new DriverBattaRuleResource($driverBattaRule->refresh()->load('vehicleGroup')),
        ]);
    }

    public function destroy(DriverBattaRule $driverBattaRule)
    {
        $driverBattaRule->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Driver batta rule deleted',
        ]);
    }
}
