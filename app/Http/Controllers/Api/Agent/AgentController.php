<?php
// app/Http/Controllers/Api/AgentController.php
namespace App\Http\Controllers\Api\Agent;

use App\Http\Controllers\Controller;
use App\Models\Agent\Agent;
use App\Http\Requests\Agent\Agent\CreateAgentRequest;
use App\Http\Requests\Agent\Agent\UpdateAgentRequest;
use App\Http\Resources\Agent\AgentResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AgentController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:agents.view')->only(['index','show']);
        $this->middleware('permission:agents.create')->only(['store']);
        $this->middleware('permission:agents.edit')->only(['update']);
        $this->middleware('permission:agents.delete')->only(['destroy']);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $q = Agent::with('user');
        if ($request->filled('search')) {
            $q->where('code','like','%'.$request->search.'%');
        }
        return AgentResource::collection($q->paginate($request->per_page ?? 15));
    }

    public function store(CreateAgentRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['created_user_id'] = $request->user()->id;
        $agent = Agent::create($data);
        return response()->json([
            'status'=>'success',
            'message'=>'Agent created',
            'data'=>['agent'=>new AgentResource($agent)]
        ],201);
    }

    public function show(Agent $agent): JsonResponse
    {
        return response()->json([
            'status'=>'success',
            'data'=>['agent'=>new AgentResource($agent)]
        ]);
    }

    public function update(UpdateAgentRequest $request, Agent $agent): JsonResponse
    {
        $data = $request->validated();
        $data['updated_user_id'] = $request->user()->id;
        $agent->update($data);
        return response()->json([
            'status'=>'success',
            'message'=>'Agent updated',
            'data'=>['agent'=>new AgentResource($agent)]
        ]);
    }

    public function destroy(Agent $agent): JsonResponse
    {
        $agent->delete();
        return response()->json([
            'status'=>'success',
            'message'=>'Agent deleted'
        ]);
    }
}
