<?php

// app/Http/Controllers/Api/StaffController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\CreateStaffRequest;
use App\Http\Requests\Staff\UpdateStaffRequest;
use App\Http\Resources\StaffResource;
use App\Models\Staff;
use App\Models\Sales\SalesProfile;
use App\Models\User;
use App\Services\UserContextService;
use App\Services\StaffIdentityService;
use App\Services\StaffAccessService;
use App\Services\Hr\PeopleCoreService;
use App\Services\Hr\StaffDefaultCompanyService;
use App\Services\Hr\Recruitment\RecruitmentConversionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class StaffController extends Controller
{
    private const SENSITIVE_PERSONAL_FIELDS = ['nic', 'dob', 'license_no', 'license_expiry', 'address'];

    public function __construct(
        private readonly UserContextService $contextService,
        private readonly StaffIdentityService $identityService,
        private readonly StaffAccessService $accessService,
        private readonly PeopleCoreService $peopleCore,
        private readonly StaffDefaultCompanyService $defaultCompany,
        private readonly RecruitmentConversionService $recruitmentConversion,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $q = $this->accessService->scope(Staff::with(['user', 'company', 'country', 'state']), $request->user());
        $canSearchSensitivePersonal = $request->user()->can('staff-sensitive-personal.view');
        if ($request->filled('search')) {
            $search = trim((string) $request->get('search'));
            $q->where(function ($query) use ($search, $canSearchSensitivePersonal) {
                $query->whereLikeInsensitive('id', $search)
                    ->orWhereLikeInsensitive('user_id', $search)
                    ->orWhereLikeInsensitive('staff_type', $search)
                    ->orWhereLikeInsensitive('code', $search)
                    ->orWhereLikeInsensitive('city', $search)
                    ->orWhereHas('user', function ($userQuery) use ($search) {
                        $userQuery->whereLikeInsensitive('id', $search)
                            ->orWhereLikeInsensitive('first_name', $search)
                            ->orWhereLikeInsensitive('last_name', $search)
                            ->orWhereLikeInsensitive('email', $search)
                            ->orWhereLikeInsensitive('phone', $search);
                    });
                if ($canSearchSensitivePersonal) {
                    $query->orWhere('license_no_fingerprint', hash('sha256', mb_strtolower(trim($search))))
                        ->orWhere('nic_fingerprint', hash('sha256', mb_strtolower(trim($search))));
                }
            });
        }

        if ($request->filled('staff_type') || $request->filled('role_id')) {
            $q->where('staff_type', $request->get('staff_type', $request->get('role_id')));
        }

        if ($request->filled('status')) {
            $q->whereHas('user', fn ($query) => $query->where('is_active', $request->get('status') === 'active'));
        }

        $sortable = ['employee_id', 'name', 'role', 'email', 'status', 'created_at'];
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

    public function types(): JsonResponse
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

    public function availableUsers(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('staff.create-all'), 403, 'Creating Staff for another User requires Staff create-all permission.');
        $search = trim((string) $request->get('search', ''));

        $users = User::query()
            ->select(['id', 'first_name', 'last_name', 'email', 'phone'])
            ->where('is_active', true)
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('staff')
                ->whereColumn('staff.user_id', 'users.id'))
            ->whereDoesntHave('contexts', fn ($query) => $query
                ->where('context_type', 'staff')
                ->where('is_active', true))
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($userQuery) use ($search) {
                    $userQuery->whereLikeInsensitive('first_name', $search)
                        ->orWhereLikeInsensitive('last_name', $search)
                        ->orWhereLikeInsensitive('email', $search)
                        ->orWhereLikeInsensitive('phone', $search);
                });
            })
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->limit(50)
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => trim($user->first_name.' '.$user->last_name),
                'email' => $user->email,
                'phone' => $user->phone,
            ])
            ->values();

        return response()->json(['status' => 'success', 'data' => $users]);
    }

    public function store(CreateStaffRequest $request): JsonResponse
    {
        $data = $this->defaultCompany->apply($request->validated());
        $data['created_user_id'] = $request->user()->id;
        abort_unless(
            $data['user_id'] === $request->user()->id || $request->user()->can('staff.create-all'),
            403,
            'Creating Staff for another User requires Staff create-all permission.'
        );
        $data = $this->restrictSensitivePersonalFields($data, $data['user_id'], $request->user());
        $user = User::query()->findOrFail($data['user_id']);

        $staff = DB::transaction(function () use ($user, $data) {
            $contextData = array_intersect_key($data, array_flip((new Staff())->getFillable()));
            $context = $this->contextService->switchContext(
                $user,
                'staff',
                $contextData,
                $data['created_user_id'],
            );

            $staff = Staff::query()->findOrFail($context->context_id);
            $staff = $this->peopleCore->initializeStaff($staff, $data, $data['created_user_id']);
            $this->recruitmentConversion->complete($data['recruitment_application_id'] ?? null, $staff, $data['created_user_id']);
            return $staff;
        });

        $staff->load(['user', 'company', 'country', 'state']);

        return response()->json([
            'status' => 'success',
            'message' => 'Staff created',
            'data' => ['staff' => new StaffResource($staff)],
        ], 201);
    }

    public function show(Request $request, Staff $staff): JsonResponse
    {
        $this->accessService->authorize($request->user(), $staff, 'view');
        $staff->load(['user', 'company', 'country', 'state']);
        $viewer = $request->user();
        if ($viewer->id !== $staff->user_id && $viewer->can('staff-sensitive-personal.view')) {
            activity('staff-sensitive-data')
                ->causedBy($viewer)
                ->performedOn($staff)
                ->withProperties(['ip' => $request->ip()])
                ->log('staff_personal_details_viewed');
        }

        return response()->json([
            'status' => 'success',
            'data' => ['staff' => new StaffResource($staff)],
        ]);
    }

    public function update(UpdateStaffRequest $request, Staff $staff): JsonResponse
    {
        $this->accessService->authorize($request->user(), $staff, 'edit');
        $data = $this->defaultCompany->apply($request->validated());
        $data = $this->restrictSensitivePersonalFields($data, $staff->user_id, $request->user());
        $data['updated_user_id'] = $request->user()->id;
        $staff = DB::transaction(function () use ($staff, $data): Staff {
            $staff = Staff::query()->lockForUpdate()->findOrFail($staff->id);
            if (array_key_exists('company_id', $data)
                && (string) ($data['company_id'] ?? '') !== (string) ($staff->company_id ?? '')) {
                $hasOpenSalesProfile = SalesProfile::query()
                    ->withTrashed()
                    ->where('staff_id', $staff->id)
                    ->where(fn ($profile) => $profile
                        ->whereNull('effective_until')
                        ->orWhere('effective_until', '>', now()))
                    ->lockForUpdate()
                    ->exists();
                abort_if(
                    $hasOpenSalesProfile,
                    422,
                    'End or transfer every current/future Sales Profile before changing the Staff legal entity.',
                );
            }
            if (array_key_exists('staff_type', $data)
                && mb_strtolower(trim((string) $data['staff_type'])) !== mb_strtolower(trim((string) $staff->staff_type))) {
                $hasOpenSalesProfile = SalesProfile::query()
                    ->withTrashed()
                    ->where('staff_id', $staff->id)
                    ->where(fn ($profile) => $profile
                        ->whereNull('effective_until')
                        ->orWhere('effective_until', '>', now()))
                    ->lockForUpdate()
                    ->exists();
                abort_if($hasOpenSalesProfile, 422,
                    'End the current/future Sales Profile before changing this Staff category.');
            }
            $staff->update($data);

            return $staff;
        });
        $staff->load(['user', 'company', 'country', 'state']);

        return response()->json([
            'status' => 'success',
            'message' => 'Staff updated',
            'data' => ['staff' => new StaffResource($staff)],
        ]);
    }

    public function destroy(Request $request, Staff $staff): JsonResponse
    {
        $this->accessService->authorize($request->user(), $staff, 'terminate');
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:2000', 'not_regex:/^\s*$/'],
            'idempotency_key' => ['required', 'uuid'],
        ]);
        $result = $this->identityService->terminate(
            $staff,
            $request->user(),
            $data['reason'],
            $data['idempotency_key'],
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Staff context terminated; the User remains available for other authorized contexts.',
            'data' => $result,
        ]);
    }

    /**
     * Drop nic/dob/license/address from a create or update payload unless the
     * actor is the Staff owner or holds staff-sensitive-personal.view, so a
     * generic-scope editor can never blindly overwrite fields they cannot see.
     */
    private function restrictSensitivePersonalFields(array $data, ?string $ownerUserId, User $actor): array
    {
        if ($ownerUserId === $actor->id || $actor->can('staff-sensitive-personal.view')) {
            return $data;
        }

        return array_diff_key($data, array_flip(self::SENSITIVE_PERSONAL_FIELDS));
    }
}
