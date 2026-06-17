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
use Illuminate\Support\Facades\Cache;

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
            $q->where(function ($query) use ($request) {
                $query->whereLikeInsensitive('name', $request->search)
                    ->orWhereLikeInsensitive('code', $request->search);
            });
        }

        if (!$request->filled('search')) {
            $perPage  = (int) ($request->per_page ?? 15);
            $page     = (int) ($request->page ?? 1);
            $v        = (int) Cache::get('ref.currencies.v', 0);
            $cacheKey = "ref.currencies.v{$v}.p{$perPage}.pg{$page}";
            $results  = Cache::remember($cacheKey, 3600, fn () => $q->paginate($perPage));

            return CurrencyResource::collection($results);
        }

        return CurrencyResource::collection($q->paginate($request->per_page ?? 15));
    }

    public function store(CreateCurrencyRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['created_user_id'] = $request->user()->id;
        $currency = Currency::create($data);

        Cache::put('ref.currencies.v', ((int) Cache::get('ref.currencies.v', 0)) + 1, 86400);

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

        Cache::put('ref.currencies.v', ((int) Cache::get('ref.currencies.v', 0)) + 1, 86400);

        return response()->json([
            'status' => 'success',
            'message' => 'Currency updated',
            'data' => ['currency' => new CurrencyResource($currency)]
        ]);
    }

    public function destroy(Currency $currency): JsonResponse
    {
        $currency->delete();

        Cache::put('ref.currencies.v', ((int) Cache::get('ref.currencies.v', 0)) + 1, 86400);

        return response()->json([
            'status' => 'success',
            'message' => 'Currency deleted'
        ]);
    }
}
