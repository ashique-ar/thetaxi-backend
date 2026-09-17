<?php
// app/Http/Controllers/Api/StaffController.php
namespace App\Http\Controllers\Api;

use App\Support\SriLankanNic;

use App\Http\Controllers\Controller;
use App\Models\Staff;
use App\Models\User;
use App\Http\Requests\Staff\CreateStaffRequest;
use App\Http\Requests\Staff\UpdateStaffRequest;
use App\Http\Resources\StaffResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use App\Services\PaymentMethodSyncService;
use App\Services\UserContextService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StaffController extends Controller
{
    public function __construct(private UserContextService $contextService)
    {
        $this->middleware('permission:staff.view')->only(['index','show']);
        $this->middleware('permission:staff.create')->only(['store']);
        $this->middleware('permission:staff.edit')->only(['update']);
        $this->middleware('permission:staff.delete')->only(['destroy']);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $q = Staff::with(['user', 'country', 'state', 'paymentMethods']);
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

        $sortable = ['employee_id', 'name', 'nic', 'role', 'email', 'status', 'created_at'];
        $sortBy = in_array($request->get('sort_by'), $sortable, true) ? $request->get('sort_by') : null;
        $sortDirection = strtolower($request->get('sort_direction', 'asc')) === 'desc' ? 'desc' : 'asc';

        switch ($sortBy) {
            case 'employee_id':
                $q->orderBy('code', $sortDirection);
                break;
            case 'name':
                $q->orderBy(User::select('first_name')->whereColumn('users.id', 'staff.user_id'), $sortDirection)
                    ->orderBy(User::select('last_name')->whereColumn('users.id', 'staff.user_id'), $sortDirection);
                break;
            case 'nic':
                $q->orderBy('nic', $sortDirection);
                break;
            case 'role':
                $q->orderBy('staff_type', $sortDirection);
                break;
            case 'email':
                $q->orderBy(User::select('email')->whereColumn('users.id', 'staff.user_id'), $sortDirection);
                break;
            case 'status':
                $q->orderBy(User::select('is_active')->whereColumn('users.id', 'staff.user_id'), $sortDirection);
                break;
            case 'created_at':
                $q->orderBy('created_at', $sortDirection);
                break;
            default:
                $q->orderBy('created_at', 'desc');
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
        if ($dob = SriLankanNic::dateOfBirth($data['nic'] ?? null)) $data['dob'] = $dob;
        $data['created_user_id'] = $request->user()->id;
        $paymentMethods = $data['payment_methods'] ?? null;
        unset($data['payment_methods']);
        $staff = DB::transaction(function () use ($data) {
            $user = !empty($data['user_id'])
                ? User::findOrFail($data['user_id'])
                : User::create([
                    'first_name' => $data['first_name'] ?? null,
                    'last_name' => $data['last_name'] ?? null,
                    'email' => !empty($data['email']) ? strtolower(trim($data['email'])) : null,
                    'phone' => $data['phone'] ?? null,
                    'password' => bcrypt(Str::random(12)),
                    'email_verified_at' => !empty($data['email']) ? now() : null,
                    'is_active' => ($data['status'] ?? 'active') === 'active',
                ]);

            $contextData = collect($data)->only([
                'staff_type', 'collection_commission_enabled', 'collection_commission_rate',
                'code', 'nic', 'dob', 'license_no', 'license_expiry', 'address',
                'country_id', 'state_id', 'city', 'created_user_id',
                'gender', 'postal_code', 'department', 'position', 'joining_date', 'reporting_to', 'emergency_contact',
            ])->all();
            $context = $this->contextService->switchContext($user, 'staff', $contextData);

            return Staff::findOrFail($context->context_id);
        });

        if ($paymentMethods !== null) {
            app(PaymentMethodSyncService::class)->syncMany($staff, $paymentMethods, $request->user()->id);
        }

        $staff->load(['user', 'country', 'state', 'paymentMethods']);

        return response()->json([
            'status'=>'success',
            'message'=>'Staff created',
            'data'=>['staff'=>new StaffResource($staff)]
        ],201);
    }

    public function show(Staff $staff): JsonResponse
    {
        $staff->load(['user', 'country', 'state', 'paymentMethods']);

        return response()->json([
            'status'=>'success',
            'data'=>['staff'=>new StaffResource($staff)]
        ]);
    }

    public function update(UpdateStaffRequest $request, Staff $staff): JsonResponse
    {
        $data = $request->validated();
        if ($dob = SriLankanNic::dateOfBirth($data['nic'] ?? null)) $data['dob'] = $dob;
        $data['updated_user_id'] = $request->user()->id;
        $paymentMethods = $data['payment_methods'] ?? null;
        unset($data['payment_methods']);
        $userData = collect($data)->only(['first_name', 'last_name', 'email', 'phone'])->all();
        if (array_key_exists('status', $data)) {
            $userData['is_active'] = $data['status'] === 'active';
        }
        unset($data['first_name'], $data['last_name'], $data['email'], $data['phone'], $data['status']);
        if ($userData !== []) {
            $staff->user()->update($userData);
        }
        $staff->update($data);

        if ($paymentMethods !== null) {
            app(PaymentMethodSyncService::class)->syncMany($staff, $paymentMethods, $request->user()->id);
        }

        $staff->load(['user', 'country', 'state', 'paymentMethods']);

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
