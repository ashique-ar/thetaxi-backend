<?php
// app/Http/Controllers/Api/PhoneCallController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PhoneCall;
use App\Http\Requests\PhoneCall\CreatePhoneCallRequest;
use App\Http\Requests\PhoneCall\UpdatePhoneCallRequest;
use App\Http\Resources\PhoneCallResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PhoneCallController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:phone-calls.view')->only(['index','show']);
        $this->middleware('permission:phone-calls.create')->only(['store']);
        $this->middleware('permission:phone-calls.edit')->only(['update']);
        $this->middleware('permission:phone-calls.delete')->only(['destroy']);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $q = PhoneCall::query();
        return PhoneCallResource::collection($q->paginate($request->per_page ?? 15));
    }

    public function store(CreatePhoneCallRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['created_user_id'] = $request->user()->id;
        $call = PhoneCall::create($data);

        return response()->json([
            'status'=>'success',
            'message'=>'Phone call logged',
            'data'=>['call'=>new PhoneCallResource($call)]
        ],201);
    }

    public function show(PhoneCall $phoneCall): JsonResponse
    {
        return response()->json([
            'status'=>'success',
            'data'=>['call'=>new PhoneCallResource($phoneCall)]
        ]);
    }

    public function update(UpdatePhoneCallRequest $request, PhoneCall $phoneCall): JsonResponse
    {
        $data = $request->validated();
        $data['updated_user_id'] = $request->user()->id;
        $phoneCall->update($data);

        return response()->json([
            'status'=>'success',
            'message'=>'Phone call updated',
            'data'=>['call'=>new PhoneCallResource($phoneCall)]
        ]);
    }

    public function destroy(PhoneCall $phoneCall): JsonResponse
    {
        $phoneCall->delete();
        return response()->json([
            'status'=>'success',
            'message'=>'Phone call deleted'
        ]);
    }
}
