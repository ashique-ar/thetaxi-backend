<?php
// app/Http/Controllers/Api/AgentApiSessionController.php
namespace App\Http\Controllers\Api\Agent;

use App\Http\Controllers\Controller;
use App\Models\Agent\AgentApiSession;
use App\Http\Requests\AgentApiSession\CreateAgentApiSessionRequest;
use App\Http\Requests\AgentApiSession\UpdateAgentApiSessionRequest;
use App\Http\Resources\Agent\AgentApiSessionResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AgentApiSessionController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:agent-api-sessions.view')->only(['index','show']);
        $this->middleware('permission:agent-api-sessions.create')->only(['store']);
        $this->middleware('permission:agent-api-sessions.edit')->only(['update']);
        $this->middleware('permission:agent-api-sessions.delete')->only(['destroy']);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $q = AgentApiSession::with(['agent','agentApi']);
        return AgentApiSessionResource::collection($q->paginate($request->per_page ?? 15));
    }

    public function store(CreateAgentApiSessionRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['created_user_id'] = $request->user()->id;
        $session = AgentApiSession::create($data);
        return response()->json([
            'status'=>'success',
            'message'=>'API session created',
            'data'=>['session'=>new AgentApiSessionResource($session)]
        ],201);
    }

    public function show(AgentApiSession $agentApiSession): JsonResponse
    {
        return response()->json([
            'status'=>'success',
            'data'=>['session'=>new AgentApiSessionResource($agentApiSession)]
        ]);
    }

    public function update(UpdateAgentApiSessionRequest $request, AgentApiSession $agentApiSession): JsonResponse
    {
        $data = $request->validated();
        $data['updated_user_id'] = $request->user()->id;
        $agentApiSession->update($data);
        return response()->json([
            'status'=>'success',
            'message'=>'API session updated',
            'data'=>['session'=>new AgentApiSessionResource($agentApiSession)]
        ]);
    }

    public function destroy(AgentApiSession $agentApiSession): JsonResponse
    {
        $agentApiSession->delete();
        return response()->json([
            'status'=>'success',
            'message'=>'API session deleted'
        ]);
    }
}
