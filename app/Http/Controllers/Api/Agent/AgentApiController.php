<?php
// app/Http/Controllers/Api/AgentApiController.php
namespace App\Http\Controllers\Api\Agent;

use App\Http\Controllers\Controller;
use App\Models\Agent\AgentApi;
use App\Http\Requests\Agent\AgentApi\CreateAgentApiRequest;
use App\Http\Requests\Agent\AgentApi\UpdateAgentApiRequest;
use App\Http\Resources\Agent\AgentApiResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AgentApiController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:agent-apis.view')->only(['index', 'show']);
        $this->middleware('permission:agent-apis.create')->only(['store']);
        $this->middleware('permission:agent-apis.edit')->only(['update']);
        $this->middleware('permission:agent-apis.delete')->only(['destroy']);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $q = AgentApi::query();
        if ($request->filled('search')) {
            $q->where('title', 'like', '%' . $request->search . '%');
        }
        return AgentApiResource::collection($q->paginate($request->per_page ?? 15));
    }

    public function store(CreateAgentApiRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['created_user_id'] = $request->user()->id;
        $api = AgentApi::create($data);
        return response()->json([
            'status' => 'success',
            'message' => 'Agent API created',
            'data' => ['agent_api' => new AgentApiResource($api)]
        ], 201);
    }

    public function show(AgentApi $agentApi): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => ['agent_api' => new AgentApiResource($agentApi)]
        ]);
    }

    public function update(UpdateAgentApiRequest $request, AgentApi $agentApi): JsonResponse
    {
        $data = $request->validated();
        $data['updated_user_id'] = $request->user()->id;
        $agentApi->update($data);
        return response()->json([
            'status' => 'success',
            'message' => 'Agent API updated',
            'data' => ['agent_api' => new AgentApiResource($agentApi)]
        ]);
    }

    public function destroy(AgentApi $agentApi): JsonResponse
    {
        $agentApi->delete();
        return response()->json([
            'status' => 'success',
            'message' => 'Agent API deleted'
        ]);
    }
}
