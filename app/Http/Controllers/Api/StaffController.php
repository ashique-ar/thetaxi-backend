<?php
// app/Http/Controllers/Api/StaffController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Staff;
use App\Http\Requests\Staff\CreateStaffRequest;
use App\Http\Requests\Staff\UpdateStaffRequest;
use App\Http\Resources\StaffResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StaffController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:staff.view')->only(['index','show']);
        $this->middleware('permission:staff.create')->only(['store']);
        $this->middleware('permission:staff.edit')->only(['update']);
        $this->middleware('permission:staff.delete')->only(['destroy']);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $q = Staff::with('user');
        if ($request->filled('search')) {
            $q->where('staff_type','like','%'.$request->search.'%')
              ->orWhere('code','like','%'.$request->search.'%');
        }
        return StaffResource::collection(
            $q->paginate($request->per_page ?? 15)
        );
    }

    public function store(CreateStaffRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['created_user_id'] = $request->user()->id;
        $staff = Staff::create($data);

        return response()->json([
            'status'=>'success',
            'message'=>'Staff created',
            'data'=>['staff'=>new StaffResource($staff)]
        ],201);
    }

    public function show(Staff $staff): JsonResponse
    {
        return response()->json([
            'status'=>'success',
            'data'=>['staff'=>new StaffResource($staff)]
        ]);
    }

    public function update(UpdateStaffRequest $request, Staff $staff): JsonResponse
    {
        $data = $request->validated();
        $data['updated_user_id'] = $request->user()->id;
        $staff->update($data);

        return response()->json([
            'status'=>'success',
            'message'=>'Staff updated',
            'data'=>['staff'=>new StaffResource($staff)]
        ]);
    }

    public function destroy(Staff $staff): JsonResponse
    {
        $staff->delete();
        return response()->json([
            'status'=>'success',
            'message'=>'Staff deleted'
        ]);
    }
}
