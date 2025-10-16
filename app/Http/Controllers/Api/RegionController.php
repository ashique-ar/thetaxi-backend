<?php
// app/Http/Controllers/Api/RegionController.php
namespace App\Http\Controllers\Api\Company;

use App\Http\Controllers\Controller;
use App\Models\Region;
use App\Http\Requests\Region\CreateRegionRequest;
use App\Http\Requests\Region\UpdateRegionRequest;
use App\Http\Resources\RegionResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RegionController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:regions.view')->only(['index','show']);
        $this->middleware('permission:regions.create')->only(['store']);
        $this->middleware('permission:regions.edit')->only(['update']);
        $this->middleware('permission:regions.delete')->only(['destroy']);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $q = Region::query();
        if ($request->filled('search')) {
            $q->where('name','like','%'.$request->search.'%');
        }
        return RegionResource::collection(
            $q->paginate($request->per_page ?? 15)
        );
    }

    public function store(CreateRegionRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['created_user_id'] = $request->user()->id;
        $region = Region::create($data);

        return response()->json([
            'status'  => 'success',
            'message' => 'Region created',
            'data'    => ['region' => new RegionResource($region)]
        ], 201);
    }

    public function show(Region $region): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data'   => ['region' => new RegionResource($region)]
        ]);
    }

    public function update(UpdateRegionRequest $request, Region $region): JsonResponse
    {
        $data = $request->validated();
        $data['updated_user_id'] = $request->user()->id;
        $region->update($data);

        return response()->json([
            'status'  => 'success',
            'message' => 'Region updated',
            'data'    => ['region' => new RegionResource($region)]
        ]);
    }

    public function destroy(Region $region): JsonResponse
    {
        $region->delete();

        return response()->json([
            'status'  => 'success',
            'message' => 'Region deleted'
        ]);
    }
}
