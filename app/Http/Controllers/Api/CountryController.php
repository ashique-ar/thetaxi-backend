<?php
// app/Http/Controllers/Api/CountryController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Http\Requests\Country\CreateCountryRequest;
use App\Http\Requests\Country\UpdateCountryRequest;
use App\Http\Resources\CountryResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CountryController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:countries.view')->only(['index', 'show']);
        $this->middleware('permission:countries.create')->only(['store']);
        $this->middleware('permission:countries.edit')->only(['update']);
        $this->middleware('permission:countries.delete')->only(['destroy']);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $q = Country::query();
        if ($request->filled('search')) {
            $q->where('name', 'like', '%' . $request->search . '%');
        }
        return CountryResource::collection(
            $q->paginate($request->per_page ?? 15)
        );
    }

    public function store(CreateCountryRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['created_user_id'] = $request->user()->id;
        $country = Country::create($data);

        return response()->json([
            'status' => 'success',
            'message' => 'Country created',
            'data' => ['country' => new CountryResource($country)]
        ], 201);
    }

    public function show(Country $country): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => ['country' => new CountryResource($country)]
        ]);
    }

    public function update(UpdateCountryRequest $request, Country $country): JsonResponse
    {
        $data = $request->validated();
        $data['updated_user_id'] = $request->user()->id;
        $country->update($data);

        return response()->json([
            'status' => 'success',
            'message' => 'Country updated',
            'data' => ['country' => new CountryResource($country)]
        ]);
    }

    public function destroy(Country $country): JsonResponse
    {
        $country->delete();
        return response()->json([
            'status' => 'success',
            'message' => 'Country deleted'
        ]);
    }
}