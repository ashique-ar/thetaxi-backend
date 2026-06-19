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
use App\Services\PaymentMethodSyncService;

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
        $q = Staff::with(['user', 'paymentMethods']);
        if ($request->filled('search')) {
            $search = trim((string) $request->get('search'));
            $q->where(function ($query) use ($search) {
                $query->whereLikeInsensitive('id', $search)
                    ->orWhereLikeInsensitive('user_id', $search)
                    ->orWhereLikeInsensitive('staff_type', $search)
                    ->orWhereLikeInsensitive('code', $search)
                    ->orWhereLikeInsensitive('nic', $search)
                    ->orWhereLikeInsensitive('license_no', $search)
                    ->orWhereLikeInsensitive('address', $search)
                    ->orWhereLikeInsensitive('city', $search)
                    ->orWhereHas('user', function ($userQuery) use ($search) {
                        $userQuery->whereLikeInsensitive('id', $search)
                            ->orWhereLikeInsensitive('first_name', $search)
                            ->orWhereLikeInsensitive('last_name', $search)
                            ->orWhereLikeInsensitive('email', $search)
                            ->orWhereLikeInsensitive('phone', $search);
                    });
            });
        }

        if ($request->filled('role_id')) {
            $q->where('staff_type', $request->get('role_id'));
        }

        if ($request->filled('status')) {
            $q->whereHas('user', fn ($query) => $query->where('is_active', $request->get('status') === 'active'));
        }

        return StaffResource::collection(
            $q->paginate($request->per_page ?? 15)
        );
    }

    public function roles(): JsonResponse
    {
        $roles = Staff::query()
            ->select('staff_type')
            ->whereNotNull('staff_type')
            ->distinct()
            ->orderBy('staff_type')
            ->pluck('staff_type')
            ->map(fn (string $staffType) => [
                'id' => $staffType,
                'name' => $staffType,
                'display_name' => $staffType,
            ])
            ->values();

        return response()->json([
            'status' => 'success',
            'data' => $roles,
        ]);
    }

    public function store(CreateStaffRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['created_user_id'] = $request->user()->id;
        $paymentMethods = $data['payment_methods'] ?? null;
        unset($data['payment_methods']);
        $staff = Staff::create($data);

        if ($paymentMethods !== null) {
            app(PaymentMethodSyncService::class)->syncMany($staff, $paymentMethods, $request->user()->id);
            $staff->load('paymentMethods');
        }

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
        $paymentMethods = $data['payment_methods'] ?? null;
        unset($data['payment_methods']);
        $staff->update($data);

        if ($paymentMethods !== null) {
            app(PaymentMethodSyncService::class)->syncMany($staff, $paymentMethods, $request->user()->id);
        }

        $staff->load('paymentMethods');

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
