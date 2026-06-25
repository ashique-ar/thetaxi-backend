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
use Illuminate\Support\Str;

class AgentApiController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:agent-apis.view')->only(['index', 'show']);
        $this->middleware('permission:agent-apis.create')->only(['store']);
        $this->middleware('permission:agent-apis.edit')->only(['update', 'revoke', 'activate']);
        $this->middleware('permission:agent-apis.delete')->only(['destroy']);
        $this->middleware('permission:agent-apis.view')->only(['stats', 'usage', 'logs']);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $q = AgentApi::with('agent.user');
        if ($request->filled('search')) {
            $search = $request->search;
            $q->where(function ($query) use ($search) {
                $query->where('title', 'like', '%' . $search . '%')
                    ->orWhere('description', 'like', '%' . $search . '%')
                    ->orWhere('api_key', 'like', '%' . $search . '%')
                    ->orWhereHas('agent.user', function ($userQuery) use ($search) {
                        $userQuery->where('first_name', 'like', '%' . $search . '%')
                            ->orWhere('last_name', 'like', '%' . $search . '%');
                    });
            });
        }
        if ($request->filled('status')) {
            $q->where('status', $request->status);
        }
        return AgentApiResource::collection($q->paginate($request->per_page ?? 15));
    }

    public function store(CreateAgentApiRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['api_key'] = Str::random(64);
        $data['status'] = 'active';
        $data['created_user_id'] = $request->user()->id;
        $api = AgentApi::create($data)->load('agent.user');
        return response()->json([
            'status' => 'success',
            'message' => 'Agent API created',
            'data' => ['agent_api' => new AgentApiResource($api)]
        ], 201);
    }

    public function show(AgentApi $agentApi): JsonResponse
    {
        $agentApi->load('agent.user');
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
        $agentApi->load('agent.user');
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

    public function stats(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => [
                'totalApiKeys' => AgentApi::count(),
                'activeKeys' => AgentApi::where('status', 'active')->count(),
                'totalRequests' => AgentApi::sum('total_requests'),
                'failedRequests' => 0,
            ],
        ]);
    }

    public function revoke(AgentApi $agentApi): JsonResponse
    {
        $agentApi->update(['status' => 'revoked', 'updated_user_id' => request()->user()->id]);
        return response()->json(['status' => 'success', 'message' => 'API key revoked']);
    }

    public function activate(AgentApi $agentApi): JsonResponse
    {
        $agentApi->update(['status' => 'active', 'updated_user_id' => request()->user()->id]);
        return response()->json(['status' => 'success', 'message' => 'API key activated']);
    }

    public function usage(AgentApi $agentApi): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => [
                'total_requests' => $agentApi->total_requests,
                'last_used_at' => $agentApi->last_used_at,
            ],
        ]);
    }

    public function logs(AgentApi $agentApi): JsonResponse
    {
        $sessions = $agentApi->sessions()->latest('last_access')->paginate(request('per_page', 25));
        return response()->json([
            'status' => 'success',
            'data' => $sessions->items(),
            'meta' => [
                'current_page' => $sessions->currentPage(),
                'last_page' => $sessions->lastPage(),
                'per_page' => $sessions->perPage(),
                'total' => $sessions->total(),
            ],
        ]);
    }
}
