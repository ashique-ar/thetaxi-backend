<?php

// app/Http/Controllers/Api/StateController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\State;
use App\Http\Requests\State\CreateStateRequest;
use App\Http\Requests\State\UpdateStateRequest;
use App\Http\Resources\StateResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StateController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:states.view')->only(['index', 'show']);
        $this->middleware('permission:states.create')->only(['store']);
        $this->middleware('permission:states.edit')->only(['update']);
        $this->middleware('permission:states.delete')->only(['destroy']);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $q = State::with('country');
        if ($request->filled('search')) {
            $q->where('name', 'like', '%' . $request->search . '%');
        }
        return StateResource::collection(
            $q->paginate($request->per_page ?? 15)
        );
    }

    public function store(CreateStateRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['created_user_id'] = $request->user()->id;
        $state = State::create($data);

        return response()->json([
            'status' => 'success',
            'message' => 'State created',
            'data' => ['state' => new StateResource($state)]
        ], 201);
    }

    public function show(State $state): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => ['state' => new StateResource($state)]
        ]);
    }

    public function update(UpdateStateRequest $request, State $state): JsonResponse
    {
        $data = $request->validated();
        $data['updated_user_id'] = $request->user()->id;
        $state->update($data);

        return response()->json([
            'status' => 'success',
            'message' => 'State updated',
            'data' => ['state' => new StateResource($state)]
        ]);
    }

    public function destroy(State $state): JsonResponse
    {
        $state->delete();
        return response()->json([
            'status' => 'success',
            'message' => 'State deleted'
        ]);
    }
}
