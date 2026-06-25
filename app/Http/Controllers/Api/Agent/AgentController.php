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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use App\Models\User;
use App\Models\Agent\AgentCommission;

class AgentController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:agents.view')->only(['index','show','dashboardStats','topPerformers']);
        $this->middleware('permission:agents.create')->only(['store']);
        $this->middleware('permission:agents.edit')->only(['update']);
        $this->middleware('permission:agents.delete')->only(['destroy']);
    }

    public function dashboardStats(): JsonResponse
    {
        $totalAgents = Agent::count();
        $activeAgents = Agent::whereHas('user', fn ($query) => $query->where('is_active', true))->count();

        return response()->json([
            'status' => 'success',
            'data' => [
                'totalAgents' => $totalAgents,
                'activeAgents' => $activeAgents,
                'totalCommissions' => (float) AgentCommission::sum('amount'),
                'pendingCommissions' => (float) AgentCommission::where('paid', false)->sum('amount'),
                'totalBookings' => Agent::withCount('bookings')->get()->sum('bookings_count'),
                'avgCommissionRate' => round((float) Agent::avg('commission_rate'), 2),
            ],
        ]);
    }

    public function topPerformers(Request $request): AnonymousResourceCollection
    {
        $limit = min(max((int) $request->integer('limit', 5), 1), 25);
        $agents = Agent::with('user')
            ->withCount('bookings')
            ->withSum('commissions', 'amount')
            ->orderByDesc('commissions_sum_amount')
            ->orderByDesc('bookings_count')
            ->limit($limit)
            ->get();

        return AgentResource::collection($agents);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $q = Agent::with('user');
        if ($request->filled('search')) {
            $search = $request->search;
            $q->where(function ($query) use ($search) {
                $query->where('code', 'like', '%'.$search.'%')
                    ->orWhereHas('user', function ($userQuery) use ($search) {
                        $userQuery->where('first_name', 'like', '%'.$search.'%')
                            ->orWhere('last_name', 'like', '%'.$search.'%')
                            ->orWhere('email', 'like', '%'.$search.'%')
                            ->orWhere('phone', 'like', '%'.$search.'%');
                    });
            });
        }
        if ($request->filled('status')) {
            $isActive = $request->status === 'active';
            $q->whereHas('user', fn ($userQuery) => $userQuery->where('is_active', $isActive));
        }
        return AgentResource::collection($q->paginate($request->per_page ?? 15));
    }

    public function store(CreateAgentRequest $request): JsonResponse
    {
        $data = $request->validated();
        $agent = DB::transaction(function () use ($data, $request) {
            if (!empty($data['user_id'])) {
                $user = User::findOrFail($data['user_id']);
            } else {
                $user = User::create([
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'] ?? null,
                    'email' => $data['email'],
                    'phone' => $data['phone'] ?? null,
                    'password' => Hash::make($data['password'] ?? Str::random(16)),
                    'email_verified_at' => now(),
                    'is_active' => $data['is_active'] ?? true,
                    'created_user_id' => $request->user()->id,
                ]);
            }

            return Agent::create([
                'user_id' => $user->id,
                'code' => $data['code'] ?? null,
                'commission_rate' => $data['commission_rate'],
                'branding_config' => $data['branding_config'] ?? null,
                'created_user_id' => $request->user()->id,
            ])->load('user');
        });
        return response()->json([
            'status'=>'success',
            'message'=>'Agent created',
            'data'=>['agent'=>new AgentResource($agent)]
        ],201);
    }

    public function show(Request $request, Agent $agent): JsonResponse
    {
        $allowedRelations = ['user', 'apis', 'commissions'];
        $requestedRelations = array_filter(explode(',', (string) $request->query('include')));
        $agent->load(array_values(array_intersect($allowedRelations, $requestedRelations ?: ['user'])));

        return response()->json([
            'status'=>'success',
            'data'=>['agent'=>new AgentResource($agent)]
        ]);
    }

    public function update(UpdateAgentRequest $request, Agent $agent): JsonResponse
    {
        $data = $request->validated();
        $isActive = $data['is_active'] ?? null;
        $userData = collect($data)->only(['first_name', 'last_name', 'email', 'phone'])->toArray();
        if (!empty($data['password'])) {
            $userData['password'] = Hash::make($data['password']);
        }
        $agentData = collect($data)->only(['user_id', 'code', 'commission_rate', 'branding_config'])->toArray();
        $agentData['updated_user_id'] = $request->user()->id;

        DB::transaction(function () use ($agent, $agentData, $userData, $isActive) {
            $agent->update($agentData);
            if ($userData) {
                $agent->user()->update($userData);
            }
            if ($isActive !== null) {
                $agent->user()->update(['is_active' => $isActive]);
            }
        });
        $agent->load('user');
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
