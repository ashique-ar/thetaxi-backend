<?php
// app/Http/Controllers/Api/AgentCommissionController.php
namespace App\Http\Controllers\Api\Agent;

use App\Http\Controllers\Controller;
use App\Models\Agent\AgentCommission;
use App\Http\Requests\AgentCommission\CreateAgentCommissionRequest;
use App\Http\Requests\AgentCommission\UpdateAgentCommissionRequest;
use App\Http\Resources\Agent\AgentCommissionResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AgentCommissionController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:agent-commissions.view')->only(['index','show']);
        $this->middleware('permission:agent-commissions.create')->only(['store']);
        $this->middleware('permission:agent-commissions.edit')->only(['update']);
        $this->middleware('permission:agent-commissions.delete')->only(['destroy']);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $q = AgentCommission::with(['agent','booking']);
        return AgentCommissionResource::collection($q->paginate($request->per_page ?? 15));
    }

    public function store(CreateAgentCommissionRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['created_user_id'] = $request->user()->id;
        $comm = AgentCommission::create($data);
        return response()->json([
            'status'=>'success',
            'message'=>'Commission record created',
            'data'=>['commission'=>new AgentCommissionResource($comm)]
        ],201);
    }

    public function show(AgentCommission $agentCommission): JsonResponse
    {
        return response()->json([
            'status'=>'success',
            'data'=>['commission'=>new AgentCommissionResource($agentCommission)]
        ]);
    }

    public function update(UpdateAgentCommissionRequest $request, AgentCommission $agentCommission): JsonResponse
    {
        $data = $request->validated();
        $data['updated_user_id'] = $request->user()->id;
        $agentCommission->update($data);
        return response()->json([
            'status'=>'success',
            'message'=>'Commission record updated',
            'data'=>['commission'=>new AgentCommissionResource($agentCommission)]
        ]);
    }

    public function destroy(AgentCommission $agentCommission): JsonResponse
    {
        $agentCommission->delete();
        return response()->json([
            'status'=>'success',
            'message'=>'Commission record deleted'
        ]);
    }
}
