<?php

// app/Http/Controllers/Api/CurrencyController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Http\Requests\Currency\CreateCurrencyRequest;
use App\Http\Requests\Currency\UpdateCurrencyRequest;
use App\Http\Resources\CurrencyResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CurrencyController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:currencies.view')->only(['index', 'show']);
        $this->middleware('permission:currencies.create')->only(['store']);
        $this->middleware('permission:currencies.edit')->only(['update']);
        $this->middleware('permission:currencies.delete')->only(['destroy']);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $q = Currency::with('country');
        if ($request->filled('search')) {
            $q->where('name', 'like', '%' . $request->search . '%')
                ->orWhere('code', 'like', '%' . $request->search . '%');
        }
        return CurrencyResource::collection(
            $q->paginate($request->per_page ?? 15)
        );
    }

    public function store(CreateCurrencyRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['created_user_id'] = $request->user()->id;
        $currency = Currency::create($data);

        return response()->json([
            'status' => 'success',
            'message' => 'Currency created',
            'data' => ['currency' => new CurrencyResource($currency)]
        ], 201);
    }

    public function show(Currency $currency): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => ['currency' => new CurrencyResource($currency)]
        ]);
    }

    public function update(UpdateCurrencyRequest $request, Currency $currency): JsonResponse
    {
        $data = $request->validated();
        $data['updated_user_id'] = $request->user()->id;
        $currency->update($data);

        return response()->json([
            'status' => 'success',
            'message' => 'Currency updated',
            'data' => ['currency' => new CurrencyResource($currency)]
        ]);
    }

    public function destroy(Currency $currency): JsonResponse
    {
        $currency->delete();
        return response()->json([
            'status' => 'success',
            'message' => 'Currency deleted'
        ]);
    }
}
