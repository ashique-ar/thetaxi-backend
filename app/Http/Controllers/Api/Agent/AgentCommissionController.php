<?php

namespace App\Http\Controllers\Api\Agent;

use App\Http\Controllers\Controller;
use App\Models\Agent\Agent;
use App\Models\Agent\AgentCommission;
use App\Http\Requests\AgentCommission\CreateAgentCommissionRequest;
use App\Http\Requests\AgentCommission\UpdateAgentCommissionRequest;
use App\Http\Resources\Agent\AgentCommissionResource;
use App\Services\AgentCommissionService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AgentCommissionController extends Controller
{
    public function __construct(
        private readonly AgentCommissionService $commissionService,
    ) {
        $this->middleware('auth:api');
        $this->middleware('permission:agent-commissions.view')->only(['index', 'show', 'statement']);
        $this->middleware('permission:agent-commissions.create')->only(['store']);
        $this->middleware('permission:agent-commissions.edit')->only(['update', 'settle']);
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
            'status'  => 'success',
            'message' => 'Commission record deleted',
        ]);
    }

    /**
     * Run a settlement — mark all unpaid commissions as paid for the given period.
     * POST /api/agent-commissions/settle
     */
    public function settle(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from'     => 'required|date',
            'to'       => 'required|date|after_or_equal:from',
            'agent_id' => 'nullable|uuid|exists:agents,id',
        ]);

        $from    = Carbon::parse($validated['from']);
        $to      = Carbon::parse($validated['to']);
        $results = $this->commissionService->runSettlement($from, $to, $validated['agent_id'] ?? null);

        return response()->json([
            'status'  => 'success',
            'message' => 'Settlement completed',
            'data'    => [
                'period'          => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
                'agents_settled'  => count($results),
                'total_paid'      => array_sum(array_column($results, 'total_amount')),
                'breakdown'       => $results,
            ],
        ]);
    }

    /**
     * Get commission statement for an agent over a date range.
     * GET /api/agents/{agentId}/commission-statement
     */
    public function statement(Request $request, string $agentId): JsonResponse
    {
        $validated = $request->validate([
            'from' => 'required|date',
            'to'   => 'required|date|after_or_equal:from',
        ]);

        $statement = $this->commissionService->getStatement(
            $agentId,
            Carbon::parse($validated['from']),
            Carbon::parse($validated['to'])
        );

        return response()->json([
            'status' => 'success',
            'data'   => $statement,
        ]);
    }
}
