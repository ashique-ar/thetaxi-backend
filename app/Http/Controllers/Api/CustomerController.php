<?php
// app/Http/Controllers/Api/CustomerController.php
namespace App\Http\Controllers\Api;

use App\Support\SriLankanNic;
use App\Support\PhoneNumberMatch;

use App\Http\Controllers\Controller;
use App\Models\Booking\Booking;
use App\Models\Customer;
use App\Models\IntegrationContactMessage;
use App\Models\IntegrationWebhookEvent;
use App\Models\Document;
use App\Http\Requests\Customer\CreateCustomerRequest;
use App\Http\Requests\Customer\UpdateCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Services\UserContextService;
use App\Services\AuthService;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Services\PaymentMethodSyncService;
use App\Services\Sales\SalesAccessScope;
use App\Services\SingleCompanyScope;
use App\Services\CustomerMergeService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomerController extends Controller
{
    protected $contextService;

    public function __construct(UserContextService $contextService, private readonly SalesAccessScope $salesScope)
    {
        $this->contextService = $contextService;
        $this->middleware('permission:customers.view')->only(['index', 'show']);
        $this->middleware('permission:customers.create')->only(['store']);
        $this->middleware('permission:customers.edit')->only(['update']);
        $this->middleware('permission:customers.edit')->only(['markPossibleMatchAsDifferent']);
        $this->middleware('permission:customers.edit')->only(['mergePossibleMatch']);
        $this->middleware('permission:customers.view')->only(['getCustomerTimeline']);
        $this->middleware('permission:customers.edit')->only(['relationshipOwnerOptions', 'assignRelationshipOwner']);
        $this->middleware('permission:customers.delete')->only(['destroy']);
        $this->middleware('permission:customers.export')->only(['exportCustomers']);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'owner_status' => ['nullable', 'in:assigned,unassigned'],
            'owner_staff_id' => ['nullable', 'uuid'],
            'possible_phone_match' => ['nullable', 'boolean'],
            'possible_duplicate' => ['nullable', 'boolean'],
        ]);
        $q = Customer::query()->select('customers.*')
            ->leftJoin('users as customer_phone', 'customer_phone.id', '=', 'customers.user_id')
            ->with(['user', 'state', 'paymentMethods']);
        $companyId = app(SingleCompanyScope::class)->activeDefaultCompany()?->id;
        abort_unless($companyId, 409, 'Select an active company before viewing customers.');
        \App\Services\CompanyCustomerScope::constrain($q, 'customers', $companyId);
        $completedBookingCount = DB::table('bookings as completed_booking')->selectRaw('COUNT(*)')
            ->whereColumn('completed_booking.customer_id', 'customers.id')->whereNotNull('completed_booking.completed_at')
            ->whereNull('completed_booking.deleted_at');
        $this->constrainBookingsToCompany($completedBookingCount, 'completed_booking', $companyId);
        $q->addSelect(['completed_booking_count' => $completedBookingCount]);
        // Ignore common display punctuation; shared numbers still require human review, never an automatic merge.
        $normalizedPhoneSql = PhoneNumberMatch::sqlExpression(...);
        $phoneMatches = DB::table('customers as other_customer')
            ->join('users as other_user', 'other_user.id', '=', 'other_customer.user_id')
            ->selectRaw('1')->whereRaw($normalizedPhoneSql('other_user.phone').' = '.$normalizedPhoneSql('customer_phone.phone'))
            ->whereColumn('other_customer.id', '!=', 'customers.id')
            ->whereNull('other_customer.deleted_at')->whereNull('other_user.deleted_at')
            ->whereNotNull('customer_phone.phone')->whereRaw($normalizedPhoneSql('customer_phone.phone')." <> ''")
            ->whereNotExists(fn ($decision) => $decision->selectRaw('1')->from('customer_duplicate_decisions as decision')
                ->where('decision.company_id', $companyId)->where('decision.decision', 'different_people')
                ->whereColumn('decision.customer_id', 'customers.id')->whereColumn('decision.other_customer_id', 'other_customer.id'))
            ->limit(1);
        if ($companyId) {
            \App\Services\CompanyCustomerScope::constrain($phoneMatches, 'other_customer', $companyId);
        } else {
            $phoneMatches->whereRaw('1 = 0');
        }
        $normalizedEmailSql = static fn (string $column): string => "LOWER(TRIM({$column}))";
        $emailMatches = DB::table('customers as other_customer')
            ->join('users as other_user', 'other_user.id', '=', 'other_customer.user_id')
            ->selectRaw('1')->whereRaw($normalizedEmailSql('other_user.email').' = '.$normalizedEmailSql('customer_phone.email'))
            ->whereColumn('other_customer.id', '!=', 'customers.id')
            ->whereNull('other_customer.deleted_at')->whereNull('other_user.deleted_at')
            ->whereNotNull('customer_phone.email')->whereRaw($normalizedEmailSql('customer_phone.email')." <> ''")
            ->whereNotExists(fn ($decision) => $decision->selectRaw('1')->from('customer_duplicate_decisions as decision')
                ->where('decision.company_id', $companyId)->where('decision.decision', 'different_people')
                ->whereColumn('decision.customer_id', 'customers.id')->whereColumn('decision.other_customer_id', 'other_customer.id'))
            ->limit(1);
        if ($companyId) {
            \App\Services\CompanyCustomerScope::constrain($emailMatches, 'other_customer', $companyId);
        } else {
            $emailMatches->whereRaw('1 = 0');
        }
        $possibleDuplicates = DB::query()->selectRaw('1')
            ->where(fn ($matches) => $matches->whereExists(clone $phoneMatches)->orWhereExists(clone $emailMatches))
            ->limit(1);
        $q->addSelect(['possible_phone_match' => clone $phoneMatches]);
        if ($request->boolean('possible_phone_match')) $q->whereExists($phoneMatches);
        $q->addSelect(['possible_duplicate' => clone $possibleDuplicates]);
        if ($request->boolean('possible_duplicate')) $q->whereExists($possibleDuplicates);
        if ($companyId) {
            $q->addSelect(['relationship_owner_name' => DB::table('customer_relationship_owner_assignments as assignment')
                ->join('sales_profiles as profile', 'profile.id', '=', 'assignment.owner_sales_profile_id')
                ->join('staff as owner_staff', 'owner_staff.id', '=', 'profile.staff_id')
                ->join('users as owner', 'owner.id', '=', 'owner_staff.user_id')
                ->selectRaw("CONCAT_WS(' ', owner.first_name, owner.last_name)")
                ->whereColumn('assignment.customer_id', 'customers.id')->where('assignment.company_id', $companyId)
                ->whereNull('assignment.ended_at')->where('assignment.effective_from', '<=', now())->whereNotNull('assignment.owner_sales_profile_id')
                ->where('profile.company_id', $companyId)->where('profile.status', 'active')->whereNull('profile.deleted_at')
                ->where('profile.effective_from', '<=', now())->where(fn ($query) => $query->whereNull('profile.effective_until')->orWhere('profile.effective_until', '>', now()))
                ->where('owner_staff.company_id', $companyId)->whereNull('owner_staff.deleted_at')
                ->where(fn ($query) => $query->whereNull('owner_staff.employment_ended_at')->orWhere('owner_staff.employment_ended_at', '>', now()))
                ->where('owner.is_active', true)->whereNull('owner.deleted_at')
                ->orderByDesc('assignment.effective_from')->limit(1)]);
        }

        if ($request->filled('owner_status')) {
            $companyId = app(SingleCompanyScope::class)->activeDefaultCompany()?->id;
            abort_unless($companyId, 409, 'No active default company is configured.');
            \App\Services\CompanyCustomerScope::constrain($q, 'customers', $companyId);
            $ownership = DB::table('customer_relationship_owner_assignments as assignment')
                ->join('sales_profiles as profile', 'profile.id', '=', 'assignment.owner_sales_profile_id')
                ->join('staff as owner_staff', 'owner_staff.id', '=', 'profile.staff_id')
                ->join('users as owner_user', 'owner_user.id', '=', 'owner_staff.user_id')
                ->selectRaw('1')->whereColumn('assignment.customer_id', 'customers.id')
                ->where('assignment.company_id', $companyId)->whereNull('assignment.ended_at')->where('assignment.effective_from', '<=', now())
                ->where('profile.company_id', $companyId)->where('profile.status', 'active')->whereNull('profile.deleted_at')
                ->where('profile.effective_from', '<=', now())->where(fn ($query) => $query->whereNull('profile.effective_until')->orWhere('profile.effective_until', '>', now()))
                ->where('owner_staff.company_id', $companyId)->whereNull('owner_staff.deleted_at')
                ->where(fn ($query) => $query->whereNull('owner_staff.employment_ended_at')->orWhere('owner_staff.employment_ended_at', '>', now()))
                ->where('owner_user.is_active', true)->whereNull('owner_user.deleted_at');
            $request->get('owner_status') === 'assigned'
                ? $q->whereExists($ownership)
                : $q->whereNotExists($ownership);
        }

        if ($request->filled('owner_staff_id')) {
            $owner = DB::table('customer_relationship_owner_assignments as assignment')
                ->join('sales_profiles as profile', 'profile.id', '=', 'assignment.owner_sales_profile_id')
                ->join('staff as owner_staff', 'owner_staff.id', '=', 'profile.staff_id')
                ->selectRaw('1')->whereColumn('assignment.customer_id', 'customers.id')
                ->where('assignment.company_id', $companyId)->whereNull('assignment.ended_at')
                ->whereNotNull('assignment.owner_sales_profile_id')->where('owner_staff.id', $request->input('owner_staff_id'))
                ->where('profile.company_id', $companyId)->whereNull('profile.deleted_at')
                ->where('owner_staff.company_id', $companyId)->whereNull('owner_staff.deleted_at')
                ->whereNull('owner_staff.employment_ended_at');
            $q->whereExists($owner);
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->get('search'));
            $q->where(function ($builder) use ($search) {
                $builder->whereHas('user', function ($query) use ($search) {
                    $query->whereLikeInsensitive('first_name', $search)
                        ->orWhereLikeInsensitive('last_name', $search)
                        ->orWhereLikeInsensitive('email', $search)
                        ->orWhereLikeInsensitive('phone', $search);
                })
                    ->orWhereExists(function ($alias) use ($search): void {
                        $alias->selectRaw('1')->from('customer_user_aliases as customer_alias')
                            ->join('users as alias_user', 'alias_user.id', '=', 'customer_alias.user_id')
                            ->whereColumn('customer_alias.customer_id', 'customers.id')
                            ->where(function ($identity) use ($search): void {
                                $identity->whereLikeInsensitive('alias_user.first_name', $search)
                                    ->orWhereLikeInsensitive('alias_user.last_name', $search)
                                    ->orWhereLikeInsensitive('alias_user.email', $search)
                                    ->orWhereLikeInsensitive('alias_user.phone', $search);
                            });
                    })
                    ->orWhereLikeInsensitive('code', $search)
                    ->orWhereLikeInsensitive('nic', $search)
                    ->orWhereLikeInsensitive('passport_number', $search)
                    ->orWhereLikeInsensitive('license_no', $search)
                    ->orWhereLikeInsensitive('license_type', $search)
                    ->orWhereLikeInsensitive('type', $search)
                    ->orWhereLikeInsensitive('sub_type', $search)
                    ->orWhereLikeInsensitive('category', $search)
                    ->orWhereLikeInsensitive('gender', $search)
                    ->orWhereLikeInsensitive('address', $search)
                    ->orWhereLikeInsensitive('postal_code', $search)
                    ->orWhereLikeInsensitive('country', $search)
                    ->orWhereLikeInsensitive('city', $search);
            });
        }

        if ($request->filled('status')) {
            $isActive = $request->get('status') === 'active';
            $q->whereHas('user', fn ($query) => $query->where('is_active', $isActive));
        }

        if ($request->has('is_verified')) {
            $verified = filter_var($request->get('is_verified'), FILTER_VALIDATE_BOOLEAN);
            $q->whereHas('user', function ($query) use ($verified) {
                if ($verified) {
                    $query->where(fn ($verifiedBy) => $verifiedBy->whereNotNull('email_verified_at')->orWhereNotNull('phone_verified_at'));
                } else {
                    $query->whereNull('email_verified_at')->whereNull('phone_verified_at');
                }
            });
        }

        $sortable = ['created_at', 'updated_at', 'code', 'name', 'email', 'phone', 'status'];
        $sort = in_array($request->get('sort'), $sortable, true)
            ? $request->get('sort')
            : 'created_at';
        $direction = $request->get('direction') === 'asc' ? 'asc' : 'desc';

        switch ($sort) {
            case 'name':
                $q->orderBy(User::select('first_name')->whereColumn('users.id', 'customers.user_id'), $direction)
                    ->orderBy(User::select('last_name')->whereColumn('users.id', 'customers.user_id'), $direction);
                break;
            case 'email':
                $q->orderBy(User::select('email')->whereColumn('users.id', 'customers.user_id'), $direction);
                break;
            case 'phone':
                $q->orderBy(User::select('phone')->whereColumn('users.id', 'customers.user_id'), $direction);
                break;
            case 'status':
                $q->orderBy(User::select('is_active')->whereColumn('users.id', 'customers.user_id'), $direction);
                break;
            default:
                $q->orderBy('customers.'.$sort, $direction);
        }

        return CustomerResource::collection(
            $q->paginate($request->per_page ?? 15)
        );
    }

    public function search(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $search = $request->get('q', '');
        $limit = (int) $request->get('limit', 20);

        $companyId = app(SingleCompanyScope::class)->activeDefaultCompany()?->id;
        abort_unless($companyId, 409, 'Select an active company before searching customers.');
        $customers = Customer::with('user')
            ->where(function ($query) use ($companyId): void {
                \App\Services\CompanyCustomerScope::constrain($query, 'customers', $companyId);
            })
            ->when($search !== '', function ($query) use ($search) {
                $query->whereHas('user', function ($userQuery) use ($search) {
                    $userQuery->whereLikeInsensitive('first_name', $search)
                        ->orWhereLikeInsensitive('last_name', $search)
                        ->orWhereLikeInsensitive('email', $search)
                        ->orWhereLikeInsensitive('phone', $search);
                })
                    ->orWhereLikeInsensitive('code', $search)
                    ->orWhereLikeInsensitive('nic', $search)
                    ->orWhereLikeInsensitive('passport_number', $search)
                    ->orWhereLikeInsensitive('license_no', $search);
            })
            ->limit($limit)
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => CustomerResource::collection($customers),
        ]);
    }

    public function checkEmail(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'except_customer_id' => ['nullable', 'uuid'],
        ]);

        $email = strtolower(trim($data['email']));

        $companyId = app(SingleCompanyScope::class)->activeDefaultCompany()?->id;
        abort_unless($companyId, 409, 'Select an active company before checking customer email.');
        $customerQuery = Customer::with('user')
            ->whereHas('user', fn ($query) => $query->whereRaw('LOWER(email) = ?', [$email]))
            ->when(!empty($data['except_customer_id']), fn ($query) => $query->where('id', '!=', $data['except_customer_id']));
        \App\Services\CompanyCustomerScope::constrain($customerQuery, 'customers', $companyId);
        $customer = $customerQuery->first();

        return response()->json([
            'status' => 'success',
            'data' => [
                'exists' => (bool) $customer,
                'customer' => $customer ? new CustomerResource($customer) : null,
            ],
        ]);
    }

    public function store(CreateCustomerRequest $request): JsonResponse
    {
        $data = $request->validated();
        abort_unless(! array_key_exists('payment_methods', $data) || $request->user()->can('payment-methods.manage'), 403,
            'Payment method management permission is required.');
        if ($dob = SriLankanNic::dateOfBirth($data['nic'] ?? null)) $data['dob'] = $dob;
        $data['created_user_id'] = $request->user()->id;
        $companyId = app(SingleCompanyScope::class)->activeDefaultCompany()?->id;
        if (! $companyId) {
            return response()->json(['status' => 'error', 'message' => 'Select an active company before creating a customer.'], 409);
        }

        try {
            return DB::transaction(function () use ($data, $companyId) {
                $existingUser = !empty($data['user_id'])
                    ? User::lockForUpdate()->findOrFail($data['user_id'])
                    : (!empty($data['email']) ? User::whereRaw('LOWER(email) = ?', [strtolower(trim($data['email']))])->lockForUpdate()->first() : null);
            
                if ($existingUser) {
                    $existingContext = \App\Models\UserContext::where('user_id', $existingUser->id)
                        ->where('context_type', 'customer')
                        ->where('is_active', true)
                        ->first();
                
                if ($existingContext) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'This user is already registered as a customer.',
                        'errors' => [
                            'email' => ['This email is already registered as a customer.']
                        ]
                    ], 422);
                }

                $contextData = [
                    'code' => $data['code'] ?? null,
                    'type' => $data['type'] ?? null,
                    'sub_type' => $data['sub_type'] ?? null,
                    'category' => $data['category'] ?? null,
                    'nic' => $data['nic'] ?? null,
                    'passport_number' => $data['passport_number'] ?? null,
                    'license_no' => $data['license_no'] ?? null,
                    'license_expiry' => $data['license_expiry'] ?? null,
                    'license_type' => $data['license_type'] ?? null,
                    'gender' => $data['gender'] ?? null,
                    'dob' => $data['dob'] ?? null,
                    'address' => $data['address'] ?? null,
                    'postal_code' => $data['postal_code'] ?? null,
                    'country_id' => $data['country_id'] ?? null,
                    'state_id' => $data['state_id'] ?? null,
                    'city' => $data['city'] ?? null,
                    'wedding_date' => $data['wedding_date'] ?? null,
                    'default_payment_arrangement' => $data['default_payment_arrangement'] ?? 'account_credit',
                ];

                $context = $this->contextService->switchContext($existingUser, 'customer', $contextData);
                $customer = Customer::with(['user', 'state', 'paymentMethods'])->find($context->getAttribute('context_id'));
                $this->linkCustomerToCompany($customer, $companyId, $data['created_user_id']);

                if (array_key_exists('payment_methods', $data)) {
                    app(PaymentMethodSyncService::class)->syncMany($customer, $data['payment_methods'] ?? [], $data['created_user_id']);
                    $customer->load('paymentMethods');
                }

                return response()->json([
                    'status' => 'success',
                    'message' => 'Customer context created for existing user',
                    'data' => new CustomerResource($customer)
                ], 201);

            } else {
                // Create new user
                $user = User::create([
                    'first_name' => $data['first_name'] ?? null,
                    'last_name' => $data['last_name'] ?? null,
                    'email' => $data['email'] ?? null,
                    'password' => bcrypt($data['password'] ?? Str::random(12)),
                    'phone' => $data['phone'] ?? null,
                    'email_verified_at' => !empty($data['email']) ? now() : null,
                    'is_active' => true,
                ]);

                // Create customer context
                $contextData = [
                    'code' => $data['code'] ?? null,
                    'type' => $data['type'] ?? null,
                    'sub_type' => $data['sub_type'] ?? null,
                    'category' => $data['category'] ?? null,
                    'nic' => $data['nic'] ?? null,
                    'passport_number' => $data['passport_number'] ?? null,
                    'license_no' => $data['license_no'] ?? null,
                    'license_expiry' => $data['license_expiry'] ?? null,
                    'license_type' => $data['license_type'] ?? null,
                    'gender' => $data['gender'] ?? null,
                    'dob' => $data['dob'] ?? null,
                    'address' => $data['address'] ?? null,
                    'postal_code' => $data['postal_code'] ?? null,
                    'country_id' => $data['country_id'] ?? null,
                    'state_id' => $data['state_id'] ?? null,
                    'city' => $data['city'] ?? null,
                    'wedding_date' => $data['wedding_date'] ?? null,
                    'default_payment_arrangement' => $data['default_payment_arrangement'] ?? 'account_credit',
                ];

                $context = $this->contextService->switchContext($user, 'customer', $contextData);
                $customer = Customer::with(['user', 'state', 'paymentMethods'])->find($context->getAttribute('context_id'));
                $this->linkCustomerToCompany($customer, $companyId, $data['created_user_id']);

                if (array_key_exists('payment_methods', $data)) {
                    app(PaymentMethodSyncService::class)->syncMany($customer, $data['payment_methods'] ?? [], $data['created_user_id']);
                    $customer->load('paymentMethods');
                }

                return response()->json([
                    'status' => 'success',
                    'message' => 'Customer created successfully',
                    'data' => new CustomerResource($customer)
                ], 201);
            }
            });

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create customer',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function linkCustomerToCompany(Customer $customer, string $companyId, string $actorId): void
    {
        if (DB::table('customer_relationship_owner_assignments')->where('customer_id', $customer->id)
            ->where('company_id', $companyId)->exists()) {
            return;
        }

        $now = now();
        DB::table('customer_relationship_owner_assignments')->insert([
            'id' => (string) Str::uuid(), 'customer_id' => $customer->id, 'company_id' => $companyId,
            'owner_sales_profile_id' => null, 'assigned_by_user_id' => $actorId,
            'reason' => 'Customer registered by staff; relationship owner pending assignment.',
            'effective_from' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    public function show(Customer $customer): JsonResponse
    {
        $customer = $this->requireCompanyCustomer($customer);
        $companyId = $this->requireActiveCompanyId();
        $completedBookings = DB::table('bookings as booking')->where('booking.customer_id', $customer->id)
            ->whereNotNull('booking.completed_at')->whereNull('booking.deleted_at');
        $this->constrainBookingsToCompany($completedBookings, 'booking', $companyId);
        $completedBookingCount = $completedBookings->count();
        $customer->load(['user', 'state', 'paymentMethods']);
        $customer->setAttribute('completed_booking_count', $completedBookingCount);
        $customer->setAttribute('customer_lifecycle', $completedBookingCount > 0 ? 'returning' : 'no_completed_booking');
        $customer->setAttribute('relationship_owner', $this->currentRelationshipOwner($customer));
        $customer->setAttribute('relationship_owner_history', $this->relationshipOwnerHistory($customer));
        return response()->json([
            'status' => 'success',
            'data' => new CustomerResource($customer)
        ]);
    }

    public function possibleMatches(Customer $customer): JsonResponse
    {
        $customer = $this->requireCompanyCustomer($customer);
        $companyId = $this->requireActiveCompanyId();
        $currentIdentities = $this->customerIdentityDetails($customer);
        $emails = $currentIdentities->pluck('email')->map(fn ($value) => strtolower(trim((string) $value)))->filter()->unique()->values()->all();
        $phones = $currentIdentities->pluck('phone')->map(fn ($value) => PhoneNumberMatch::normalized((string) $value))->filter()->unique()->values()->all();
        if (! $emails && ! $phones) return response()->json(['status' => 'success', 'data' => []]);

        $matches = $this->companyCustomers($companyId)->with('user')
            ->where('customers.id', '!=', $customer->id)
            ->where(function ($identities) use ($emails, $phones): void {
                $identities->whereHas('user', fn ($user) => $this->matchCustomerIdentityQuery($user, $emails, $phones))
                    ->orWhereExists(function ($alias) use ($emails, $phones): void {
                        $alias->selectRaw('1')->from('customer_user_aliases as customer_alias')
                            ->join('users as alias_user', 'alias_user.id', '=', 'customer_alias.user_id')
                            ->whereColumn('customer_alias.customer_id', 'customers.id')
                            ->where(fn ($user) => $this->matchCustomerIdentityQuery($user, $emails, $phones));
                });
            })
            ->whereNotExists(fn ($decision) => $decision->selectRaw('1')->from('customer_duplicate_decisions as decision')
                ->where('decision.company_id', $companyId)->where('decision.decision', 'different_people')
                ->where('decision.customer_id', $customer->id)->whereColumn('decision.other_customer_id', 'customers.id'))
            ->limit(20)->get(); // shortcut: show up to 20 matches, expand to paginated review if this queue grows.

        $matches = $matches->map(function (Customer $match) use ($emails, $phones): array {
            $identities = $this->customerIdentityDetails($match);
            $matchedEmail = $identities->first(fn ($identity) => in_array(strtolower(trim((string) $identity->email)), $emails, true))?->email;
            $matchedPhone = $identities->first(fn ($identity) => in_array(PhoneNumberMatch::normalized((string) $identity->phone), $phones, true))?->phone;
            return [
                'id' => (string) $match->id,
                'name' => trim(($match->user?->first_name ?? '').' '.($match->user?->last_name ?? '')),
                'email' => $matchedEmail ?: $match->user?->email,
                'phone' => $matchedPhone ?: $match->user?->phone,
                'created_at' => $match->created_at,
                'match_reasons' => array_values(array_filter([
                    $matchedEmail ? 'email' : null,
                    $matchedPhone ? 'phone' : null,
                ])),
            ];
        })->values();

        return response()->json(['status' => 'success', 'data' => $matches]);
    }

    public function markPossibleMatchAsDifferent(Request $request, Customer $customer, string $matchedCustomerId): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $data['reason'] = trim($data['reason']);
        abort_if(mb_strlen($data['reason']) < 3, 422, 'Enter a short reason for keeping these customer records separate.');
        $customer = $this->requireCompanyCustomer($customer);
        $companyId = $this->requireActiveCompanyId();
        $matchedCustomer = $this->companyCustomers($companyId)->whereKey($matchedCustomerId)->firstOrFail();
        abort_unless($customer->id !== $matchedCustomer->id, 422, 'Choose a different customer record.');

        DB::transaction(function () use ($customer, $matchedCustomer, $companyId, $request, $data): void {
            $now = now();
            $rows = collect([[$customer->id, $matchedCustomer->id], [$matchedCustomer->id, $customer->id]])
                ->map(fn (array $pair): array => [
                    'id' => (string) Str::uuid(), 'company_id' => $companyId,
                    'customer_id' => (string) $pair[0], 'other_customer_id' => (string) $pair[1],
                    'reviewed_by_user_id' => (string) $request->user()->id,
                    'decision' => 'different_people', 'reason' => trim($data['reason']),
                    'decided_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                ])->all();
            DB::table('customer_duplicate_decisions')->insertOrIgnore($rows);
        });

        return response()->json(['status' => 'success', 'message' => 'These customer records will stay separate and will no longer be flagged as a possible match.']);
    }

    public function mergePossibleMatch(Request $request, Customer $customer, string $matchedCustomerId, CustomerMergeService $merger): JsonResponse
    {
        $data = $request->validate([
            'confirm_same_person' => ['required', 'accepted'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        $data['reason'] = trim($data['reason']);
        abort_if(mb_strlen($data['reason']) < 3, 422, 'Enter a short reason for merging these customer records.');
        $primary = $this->requireCompanyCustomer($customer);
        $duplicate = $this->companyCustomers($this->requireActiveCompanyId())->whereKey($matchedCustomerId)->firstOrFail();
        abort_unless($primary->id !== $duplicate->id, 422, 'Choose a different customer record.');

        $companyId = $this->requireActiveCompanyId();
        try {
            $merged = $merger->merge($primary, $duplicate, $companyId, (string) $request->user()->id, $data['reason']);
        } catch (QueryException $exception) {
            \Illuminate\Support\Facades\Log::warning('Customer merge rolled back because linked records conflict', [
                'primary_customer_id' => $primary->id, 'duplicate_customer_id' => $duplicate->id,
                'company_id' => $companyId, 'sql_state' => $exception->getCode(),
            ]);
            return response()->json(['status' => 'error', 'message' => 'Some linked records conflict. Nothing was merged; ask a manager to review loyalty, device or promotion history.'], 409);
        }

        return response()->json(['status' => 'success', 'message' => 'The duplicate history was moved to this customer. Both accounts keep their current sign-in status.',
            'data' => ['customer' => new CustomerResource($merged)]]);
    }

    public function relationshipOwnerOptions(): JsonResponse
    {
        $companyId = app(SingleCompanyScope::class)->activeDefaultCompany()?->id;
        abort_unless($companyId, 409, 'No active default company is configured.');

        $options = DB::table('sales_profiles as profile')
            ->join('staff', 'staff.id', '=', 'profile.staff_id')
            ->join('users', 'users.id', '=', 'staff.user_id')
            ->where('profile.company_id', $companyId)
            ->where('profile.status', 'active')
            ->where('profile.effective_from', '<=', now())
            ->where(fn ($query) => $query->whereNull('profile.effective_until')->orWhere('profile.effective_until', '>', now()))
            ->whereNotNull('profile.reporting_currency')
            ->whereNotNull('profile.staff_category_snapshot')
            ->where(fn ($query) => $query->where('profile.acquisition_eligible', true)->orWhere('profile.collection_eligible', true)->orWhere('profile.commission_eligible', true))
            ->whereNull('staff.deleted_at')->where(fn ($query) => $query->whereNull('staff.employment_ended_at')->orWhere('staff.employment_ended_at', '>', now()))
            ->where('staff.company_id', $companyId)->where('users.is_active', true)->whereNull('users.deleted_at')
            ->orderBy('users.first_name')->orderBy('users.last_name')
            ->get(['profile.id', 'profile.sales_code', 'users.first_name', 'users.last_name']);

        return response()->json(['status' => 'success', 'data' => $options]);
    }

    public function assignRelationshipOwner(Request $request, Customer $customer): JsonResponse
    {
        $data = $request->validate([
            'owner_sales_profile_id' => ['nullable', 'uuid'],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        $companyId = app(SingleCompanyScope::class)->activeDefaultCompany()?->id;
        abort_unless($companyId, 409, 'No active default company is configured.');
        $ownerId = $data['owner_sales_profile_id'] ?? null;

        $owner = DB::transaction(function () use ($customer, $companyId, $ownerId, $data, $request) {
            Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $companyCustomer = Customer::query()->whereKey($customer->id);
            \App\Services\CompanyCustomerScope::constrain($companyCustomer, 'customers', $companyId);
            abort_unless($companyCustomer->exists(), 404);
            if ($ownerId) {
                $validOwner = DB::table('sales_profiles as profile')
                    ->join('staff', 'staff.id', '=', 'profile.staff_id')
                    ->join('users', 'users.id', '=', 'staff.user_id')
                    ->where('profile.id', $ownerId)->where('profile.company_id', $companyId)
                    ->where('profile.status', 'active')->where('profile.effective_from', '<=', now())
                    ->where(fn ($query) => $query->whereNull('profile.effective_until')->orWhere('profile.effective_until', '>', now()))
                    ->whereNotNull('profile.reporting_currency')->whereNotNull('profile.staff_category_snapshot')
                    ->where(fn ($query) => $query->where('profile.acquisition_eligible', true)->orWhere('profile.collection_eligible', true)->orWhere('profile.commission_eligible', true))
                    ->whereNull('staff.deleted_at')->where(fn ($query) => $query->whereNull('staff.employment_ended_at')->orWhere('staff.employment_ended_at', '>', now()))
                    ->where('staff.company_id', $companyId)
                    ->where('users.is_active', true)->whereNull('users.deleted_at')->lockForUpdate()->exists();
                abort_unless($validOwner, 422, 'Choose an active customer owner from this company.');
            }

            $current = DB::table('customer_relationship_owner_assignments')->where('customer_id', $customer->id)
                ->where('company_id', $companyId)->whereNull('ended_at')->lockForUpdate()->first();
            if ($current && $current->owner_sales_profile_id === $ownerId) return $this->currentRelationshipOwner($customer);

            $now = now();
            if ($current) DB::table('customer_relationship_owner_assignments')->where('id', $current->id)->update([
                'ended_at' => $now, 'ended_by_user_id' => $request->user()->id, 'updated_at' => $now,
            ]);
            DB::table('customer_relationship_owner_assignments')->insert([
                'id' => (string) Str::uuid(), 'customer_id' => $customer->id, 'company_id' => $companyId,
                'owner_sales_profile_id' => $ownerId, 'assigned_by_user_id' => $request->user()->id,
                'reason' => trim($data['reason']), 'effective_from' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
            return $this->currentRelationshipOwner($customer);
        });

        if ($ownerId) {
            $latestCompletedBooking = Booking::query()->where('company_id', $companyId)
                ->where('customer_id', $customer->id)->where('status', 'completed')->whereNotNull('completed_at')
                ->orderByDesc('completed_at')->first();
            if ($latestCompletedBooking) {
                try {
                    app(\App\Services\Sales\SalesCrmService::class)
                        ->createRepeatBookingFollowup($latestCompletedBooking, (string) $request->user()->id);
                } catch (\Throwable $e) {
                    Log::error('Repeat-booking follow-up task could not be created after customer assignment', [
                        'customer_id' => $customer->id,
                        'booking_id' => $latestCompletedBooking->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return response()->json(['status' => 'success', 'data' => [
            'relationship_owner' => $owner,
            'relationship_owner_history' => $this->relationshipOwnerHistory($customer),
        ]]);
    }

    public function activateVerifiedPortal(Request $request, Customer $customer): JsonResponse
    {
        abort_unless($request->user()->can('users.manage'), 403);
        $request->validate(['confirm_verified_contact' => ['required', 'accepted']]);
        $companyId = app(SingleCompanyScope::class)->activeDefaultCompany()?->id;
        abort_unless($companyId, 409, 'No active business is configured.');
        $customer = $this->requireCompanyCustomer($customer);

        $customer = DB::transaction(function () use ($customer, $companyId, $request): Customer {
            $locked = $this->requireCompanyCustomer($customer, true);
            $user = User::query()->whereKey($locked->user_id)->lockForUpdate()->firstOrFail();
            abort_unless($user->email_verified_at || $user->phone_verified_at, 422, 'Verify the customer email or phone before activating portal access.');
            if (! $user->is_active) {
                $user->update(['is_active' => true, 'updated_user_id' => $request->user()->id]);
                activity('customer-access')->causedBy($request->user())->performedOn($user)
                    ->withProperties(['customer_id' => $locked->id, 'company_id' => $companyId, 'verified_email' => (bool) $user->email_verified_at, 'verified_phone' => (bool) $user->phone_verified_at])
                    ->log('customer_portal_activated');
            }
            return $locked->load(['user', 'state', 'paymentMethods']);
        });

        return response()->json(['status' => 'success', 'data' => new CustomerResource($customer)]);
    }

    public function sendPortalVerification(Request $request, Customer $customer, AuthService $auth): JsonResponse
    {
        abort_unless($request->user()->can('users.manage'), 403);
        $companyId = app(SingleCompanyScope::class)->activeDefaultCompany()?->id;
        abort_unless($companyId, 409, 'No active business is configured.');
        $customer = $this->requireCompanyCustomer($customer);
        $user = User::query()->whereKey($customer->user_id)->firstOrFail();
        abort_unless($user->email, 422, 'Add an email address before sending a verification link.');
        abort_unless(! $user->email_verified_at, 409, 'This email address is already verified.');

        $auth->sendEmailVerification($user);
        return response()->json(['status' => 'success', 'message' => 'Verification email queued.']);
    }

    private function currentRelationshipOwner(Customer $customer): ?array
    {
        $companyId = app(SingleCompanyScope::class)->activeDefaultCompany()?->id;
        if (! $companyId) return null;
        $owner = DB::table('customer_relationship_owner_assignments as assignment')
            ->leftJoin('sales_profiles as profile', 'profile.id', '=', 'assignment.owner_sales_profile_id')
            ->leftJoin('staff', 'staff.id', '=', 'profile.staff_id')
            ->leftJoin('users', 'users.id', '=', 'staff.user_id')
            ->where('assignment.customer_id', $customer->id)->where('assignment.company_id', $companyId)
            ->whereNull('assignment.ended_at')->first([
                'assignment.owner_sales_profile_id', 'assignment.reason', 'assignment.effective_from',
                'profile.sales_code', 'users.first_name', 'users.last_name',
            ]);
        return $owner ? (array) $owner : null;
    }

    private function relationshipOwnerHistory(Customer $customer): array
    {
        $companyId = app(SingleCompanyScope::class)->activeDefaultCompany()?->id;
        if (! $companyId) return [];
        return DB::table('customer_relationship_owner_assignments as assignment')
            ->leftJoin('sales_profiles as profile', 'profile.id', '=', 'assignment.owner_sales_profile_id')
            ->leftJoin('staff', 'staff.id', '=', 'profile.staff_id')
            ->leftJoin('users as owner', 'owner.id', '=', 'staff.user_id')
            ->leftJoin('users as assigned_by', 'assigned_by.id', '=', 'assignment.assigned_by_user_id')
            ->where('assignment.customer_id', $customer->id)->where('assignment.company_id', $companyId)
            ->orderByDesc('assignment.effective_from')->limit(20)->get([
                'assignment.id', 'assignment.reason', 'assignment.effective_from', 'assignment.ended_at',
                'profile.sales_code', 'owner.first_name as owner_first_name', 'owner.last_name as owner_last_name',
                'assigned_by.first_name as assigned_by_first_name', 'assigned_by.last_name as assigned_by_last_name',
            ])->map(fn ($row) => (array) $row)->all();
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): JsonResponse
    {
        $customer = $this->requireCompanyCustomer($customer);
        try {
            $data = $request->validated();
            abort_unless(! array_key_exists('payment_methods', $data) || $request->user()->can('payment-methods.manage'), 403,
                'Payment method management permission is required.');
            if ($dob = SriLankanNic::dateOfBirth($data['nic'] ?? null)) $data['dob'] = $dob;
            $data['updated_user_id'] = $request->user()->id;

            $userData = array_intersect_key($data, array_flip([
                'first_name', 'last_name', 'email', 'phone'
            ]));

            $customerData = array_diff_key($data, $userData, ['payment_methods' => true]);

            if (!empty($userData)) {
                $customer->user->update($userData);
            }

            $customer->update($customerData);

            if (array_key_exists('payment_methods', $data)) {
                app(PaymentMethodSyncService::class)->syncMany($customer, $data['payment_methods'] ?? [], $request->user()->id);
            }

            $customer->load(['user', 'state', 'paymentMethods']);

            return response()->json([
                'status' => 'success',
                'message' => 'Customer updated successfully',
                'data' => new CustomerResource($customer)
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update customer',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy(Customer $customer): JsonResponse
    {
        DB::transaction(function () use ($customer) {
            $customer = $this->requireCompanyCustomer($customer, true);
            \App\Models\Activity::create([
                'log_name' => 'Customer', 'description' => 'deleted', 'event' => 'deleted',
                'subject_type' => Customer::class, 'subject_id' => $customer->id,
                'causer_type' => User::class, 'causer_id' => request()->user()->id,
                'properties' => ['old' => $customer->getAttributes()],
            ]);
            $customer->disableLogging();
            $user = User::query()->whereKey($customer->user_id)->lockForUpdate()->first();
            if ($user) {
                $this->contextService->deactivateContext($user, 'customer', $customer->id, request()->user()?->id);
            }
            $customer->delete();

            if ($user && !$user->contexts()->where('is_active', true)->exists()) {
                $user->delete();
            }
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Customer deleted'
        ]);
    }

    /**
     * Get customer bookings
     * GET /api/customers/{customer}/bookings
     */
    public function getCustomerBookings(Request $request, Customer $customer): JsonResponse
    {
        $customer = $this->requireCompanyCustomer($customer);
        $companyId = app(SingleCompanyScope::class)->activeDefaultCompany()?->id;
        abort_unless($companyId, 409, 'Set one active default company before viewing customer bookings.');
        $bookings = $customer->bookings();
        \App\Services\CompanyBookingScope::constrain($bookings, 'bookings', $companyId);
        $currentBookings = clone $bookings;
        $currentBookings = $currentBookings
            ->whereNotIn('status', ['completed', 'cancelled', 'canceled', 'rejected'])
            ->latest()
            ->limit(5)
            ->when($request->user()->can('bookings.view'), fn ($query) => $query->with('responsibleUser:id,first_name,last_name'))
            ->get(['id', 'booking_number', 'booking_date', 'status', 'responsible_user_id'])
            ->map(function (Booking $booking) use ($request): array {
                $row = [
                    'id' => (string) $booking->id,
                    'booking_number' => $booking->booking_number,
                    'booking_date' => $booking->booking_date,
                    'status' => $booking->status,
                ];
                if ($request->user()->can('bookings.view')) {
                    $row['responsible_user'] = $booking->responsibleUser ? [
                        'id' => (string) $booking->responsibleUser->id,
                        'name' => trim($booking->responsibleUser->first_name.' '.$booking->responsibleUser->last_name),
                    ] : null;
                }
                return $row;
            });
        $bookings = $bookings
            ->select(['id', 'booking_number', 'booking_date', 'total_actual', 'total_estimated', 'status'])
            ->latest()
            ->paginate(15);

        return response()->json([
            'status' => 'success',
            'data' => [
                'bookings' => $bookings->items(),
                'current_bookings' => $currentBookings,
                'pagination' => [
                    'current_page' => $bookings->currentPage(),
                    'last_page' => $bookings->lastPage(),
                    'per_page' => $bookings->perPage(),
                    'total' => $bookings->total()
                ]
            ]
        ]);
    }

    public function getCustomerInquiries(Customer $customer): JsonResponse
    {
        $customer = $this->requireCompanyCustomer($customer);
        $companyId = app(SingleCompanyScope::class)->activeDefaultCompany()?->id;
        abort_unless($companyId, 409, 'Set one active default company before viewing customer inquiries.');
        $inquiries = DB::table('inquiries')->where('company_id', $companyId)->where('customer_id', $customer->id)->whereNull('deleted_at')
            ->latest('created_at')->select(['id', 'inquiry_number', 'inquiry_type', 'subject', 'status', 'source', 'created_at'])
            ->paginate(15);

        return response()->json(['status' => 'success', 'data' => [
            'inquiries' => $inquiries->items(),
            'pagination' => [
                'current_page' => $inquiries->currentPage(), 'last_page' => $inquiries->lastPage(),
                'per_page' => $inquiries->perPage(), 'total' => $inquiries->total(),
            ],
        ]]);
    }

    public function getCustomerTimeline(Request $request, Customer $customer): JsonResponse
    {
        $customer = $this->requireCompanyCustomer($customer);
        $request->validate(['page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50']]);
        $companyId = app(SingleCompanyScope::class)->activeDefaultCompany()?->id;
        abort_unless($companyId, 409, 'Set one active default company before viewing the customer timeline.');

        $bookings = DB::table('bookings as booking')->where('booking.customer_id', $customer->id)->whereNull('booking.deleted_at');
        $this->constrainBookingsToCompany($bookings, 'booking', $companyId);
        if (! $request->user()->can('bookings.view')) $bookings->whereRaw('1 = 0');
        $events = $bookings->selectRaw("CONCAT('booking:', booking.id) as id, 'booking' as event_type, 'Booking' as title, booking.status as status, booking.created_at as occurred_at, booking.booking_number as reference, booking.id as booking_id, NULL as detail");

        if ($request->user()->can('bookings.view')) {
            $bookingStatusEvents = DB::table('booking_statuses as status')->whereNull('status.deleted_at')
                ->whereExists(function ($booking) use ($customer, $companyId): void {
                    $booking->selectRaw('1')->from('bookings as linked_booking')
                        ->whereColumn('linked_booking.id', 'status.booking_id')
                        ->where('linked_booking.customer_id', $customer->id)->whereNull('linked_booking.deleted_at');
                    $this->constrainBookingsToCompany($booking, 'linked_booking', $companyId);
                })
                ->selectRaw("CONCAT('booking-status:', status.id) as id, 'booking_status' as event_type, 'Booking status changed' as title, status.new_status as status, status.created_at as occurred_at, NULL as reference, status.booking_id as booking_id, CONCAT(COALESCE(status.old_status, 'New'), ' → ', COALESCE(status.new_status, 'Unknown')) as detail");
            $events->unionAll($bookingStatusEvents);

            $bookingOwnerEvents = DB::table('booking_activities as activity')
                ->join('bookings as linked_booking', 'linked_booking.id', '=', 'activity.booking_id')
                ->where('activity.event_key', 'booking_responsible_user_changed')->whereNull('activity.deleted_at')
                ->where('linked_booking.customer_id', $customer->id)->whereNull('linked_booking.deleted_at')
                ->where(fn ($scope) => $scope->whereNull('activity.company_id')->orWhere('activity.company_id', $companyId))
                ->where(function ($companyScope) use ($companyId) { $this->constrainBookingsToCompany($companyScope, 'linked_booking', $companyId); })
                ->selectRaw("CONCAT('booking-owner:', activity.id) as id, 'booking_assignment' as event_type, CASE WHEN activity.meta->>'to_user_id' IS NULL THEN 'Booking owner cleared' ELSE 'Booking owner assigned' END as title, CASE WHEN activity.meta->>'to_user_id' IS NULL THEN 'unassigned' ELSE 'assigned' END as status, activity.event_at as occurred_at, NULL as reference, activity.booking_id as booking_id, CONCAT(COALESCE((SELECT NULLIF(CONCAT_WS(' ', owner_from.first_name, owner_from.last_name), '') FROM users as owner_from WHERE owner_from.id::text = activity.meta->>'from_user_id'), 'Unassigned'), ' → ', COALESCE((SELECT NULLIF(CONCAT_WS(' ', owner_to.first_name, owner_to.last_name), '') FROM users as owner_to WHERE owner_to.id::text = activity.meta->>'to_user_id'), 'Unassigned'), ' · by ', COALESCE((SELECT NULLIF(CONCAT_WS(' ', event_actor.first_name, event_actor.last_name), '') FROM users as event_actor WHERE event_actor.id::text = activity.meta->>'actor_user_id'), 'System')) as detail");
            $events->unionAll($bookingOwnerEvents);

            $dispatchEvents = DB::table('booking_dispatches as dispatch')
                ->join('bookings as linked_booking', 'linked_booking.id', '=', 'dispatch.booking_id')
                ->leftJoin('users as dispatcher', 'dispatcher.id', '=', 'dispatch.dispatched_by')
                ->where('linked_booking.customer_id', $customer->id)->whereNull('linked_booking.deleted_at')->whereNull('dispatch.deleted_at')
                ->whereNotNull('dispatch.dispatched_at')
                ->where(function ($companyScope) use ($companyId) { $this->constrainBookingsToCompany($companyScope, 'linked_booking', $companyId); })
                ->selectRaw("CONCAT('booking-dispatch:', dispatch.id) as id, 'booking_service' as event_type, 'Vehicle dispatched' as title, 'dispatched' as status, dispatch.dispatched_at as occurred_at, NULL as reference, dispatch.booking_id as booking_id, CONCAT('By ', COALESCE(NULLIF(CONCAT_WS(' ', dispatcher.first_name, dispatcher.last_name), ''), 'System')) as detail");
            $events->unionAll($dispatchEvents);

            $returnEvents = DB::table('booking_dispatches as dispatch')
                ->join('bookings as linked_booking', 'linked_booking.id', '=', 'dispatch.booking_id')
                ->leftJoin('users as returner', 'returner.id', '=', 'dispatch.returned_by')
                ->where('linked_booking.customer_id', $customer->id)->whereNull('linked_booking.deleted_at')->whereNull('dispatch.deleted_at')
                ->whereNotNull('dispatch.actual_return_at')
                ->where(function ($companyScope) use ($companyId) { $this->constrainBookingsToCompany($companyScope, 'linked_booking', $companyId); })
                ->selectRaw("CONCAT('booking-return:', dispatch.id) as id, 'booking_service' as event_type, 'Vehicle returned' as title, 'returned' as status, dispatch.actual_return_at as occurred_at, NULL as reference, dispatch.booking_id as booking_id, CONCAT('By ', COALESCE(NULLIF(CONCAT_WS(' ', returner.first_name, returner.last_name), ''), 'System')) as detail");
            $events->unionAll($returnEvents);

            $inspectionEvents = DB::table('booking_qcs as inspection')
                ->join('bookings as linked_booking', 'linked_booking.id', '=', 'inspection.booking_id')
                ->leftJoin('users as inspector', 'inspector.id', '=', 'inspection.inspector_id')
                ->where('linked_booking.customer_id', $customer->id)->whereNull('linked_booking.deleted_at')->whereNull('inspection.deleted_at')
                ->whereNotNull('inspection.inspection_completed_at')
                ->where(function ($companyScope) use ($companyId) { $this->constrainBookingsToCompany($companyScope, 'linked_booking', $companyId); })
                ->selectRaw("CONCAT('booking-inspection:', inspection.id) as id, 'booking_service' as event_type, 'Return inspection completed' as title, inspection.qc_status as status, inspection.inspection_completed_at as occurred_at, NULL as reference, inspection.booking_id as booking_id, CONCAT('By ', COALESCE(NULLIF(CONCAT_WS(' ', inspector.first_name, inspector.last_name), ''), 'System')) as detail");
            $events->unionAll($inspectionEvents);

            $quoteAcceptances = DB::table('pending_payment_links as quote_link')->where('quote_link.type', 'quotation_conversion')
                ->whereNotNull('quote_link.accepted_at')->whereNull('quote_link.deleted_at')
                ->whereExists(function ($booking) use ($customer, $companyId): void {
                    $booking->selectRaw('1')->from('bookings as linked_booking')
                        ->whereColumn('linked_booking.id', 'quote_link.booking_id')
                        ->where('linked_booking.customer_id', $customer->id)->whereNull('linked_booking.deleted_at');
                    $this->constrainBookingsToCompany($booking, 'linked_booking', $companyId);
                })
                ->selectRaw("CONCAT('quote-accepted:', quote_link.id) as id, 'quote_acceptance' as event_type, 'Quotation accepted' as title, 'accepted' as status, quote_link.accepted_at as occurred_at, NULL as reference, quote_link.booking_id as booking_id, NULL as detail");
            $events->unionAll($quoteAcceptances);
        }

        if ($request->user()->can('customers.view')) {
            $customerOwnerEvents = DB::table('customer_relationship_owner_assignments as assignment')
                ->leftJoin('sales_profiles as owner_profile', function ($join): void { $join->on('owner_profile.id', '=', 'assignment.owner_sales_profile_id')->on('owner_profile.company_id', '=', 'assignment.company_id'); })
                ->leftJoin('staff as owner_staff', function ($join): void { $join->on('owner_staff.id', '=', 'owner_profile.staff_id')->on('owner_staff.company_id', '=', 'assignment.company_id'); })
                ->leftJoin('users as owner_user', 'owner_user.id', '=', 'owner_staff.user_id')
                ->leftJoin('users as assigned_by', 'assigned_by.id', '=', 'assignment.assigned_by_user_id')
                ->where('assignment.company_id', $companyId)->where('assignment.customer_id', $customer->id)
                ->selectRaw("CONCAT('customer-owner:', assignment.id, ':started') as id, 'customer_owner' as event_type, CASE WHEN assignment.owner_sales_profile_id IS NULL THEN 'Customer owner cleared' ELSE 'Customer owner assigned' END as title, CASE WHEN assignment.owner_sales_profile_id IS NULL THEN 'unassigned' ELSE 'assigned' END as status, assignment.effective_from as occurred_at, NULL as reference, NULL as booking_id, CONCAT(COALESCE(NULLIF(CONCAT_WS(' ', owner_user.first_name, owner_user.last_name), ''), 'Unassigned'), CASE WHEN COALESCE(assignment.reason, '') = '' THEN '' ELSE CONCAT(' · ', assignment.reason) END, CASE WHEN assigned_by.id IS NULL THEN '' ELSE CONCAT(' · by ', CONCAT_WS(' ', assigned_by.first_name, assigned_by.last_name)) END) as detail");
            $events->unionAll($customerOwnerEvents);

            $customerOwnerEndEvents = DB::table('customer_relationship_owner_assignments as assignment')
                ->leftJoin('sales_profiles as owner_profile', function ($join): void { $join->on('owner_profile.id', '=', 'assignment.owner_sales_profile_id')->on('owner_profile.company_id', '=', 'assignment.company_id'); })
                ->leftJoin('staff as owner_staff', function ($join): void { $join->on('owner_staff.id', '=', 'owner_profile.staff_id')->on('owner_staff.company_id', '=', 'assignment.company_id'); })
                ->leftJoin('users as owner_user', 'owner_user.id', '=', 'owner_staff.user_id')
                ->leftJoin('users as ended_by', 'ended_by.id', '=', 'assignment.ended_by_user_id')
                ->where('assignment.company_id', $companyId)->where('assignment.customer_id', $customer->id)->whereNotNull('assignment.ended_at')
                ->selectRaw("CONCAT('customer-owner:', assignment.id, ':ended') as id, 'customer_owner' as event_type, 'Customer owner ended' as title, 'ended' as status, assignment.ended_at as occurred_at, NULL as reference, NULL as booking_id, CONCAT(COALESCE(NULLIF(CONCAT_WS(' ', owner_user.first_name, owner_user.last_name), ''), 'Unassigned'), CASE WHEN ended_by.id IS NULL THEN '' ELSE CONCAT(' · by ', CONCAT_WS(' ', ended_by.first_name, ended_by.last_name)) END) as detail");
            $events->unionAll($customerOwnerEndEvents);
        }

        if ($request->user()->can('inquiries.view')) {
            $inquiries = DB::table('inquiries as inquiry')->where('inquiry.company_id', $companyId)->where('inquiry.customer_id', $customer->id)->whereNull('inquiry.deleted_at')
                ->selectRaw("CONCAT('inquiry:', inquiry.id) as id, 'inquiry' as event_type, COALESCE(inquiry.subject, inquiry.inquiry_type, 'Customer request') as title, inquiry.status as status, inquiry.created_at as occurred_at, inquiry.inquiry_number as reference, NULL as booking_id, inquiry.source as detail");
            $events->unionAll($inquiries);

            $inquiryBookingField = $request->user()->can('bookings.view') ? 'inquiry.booking_id' : 'NULL';
            $inquiryStatusEvents = DB::table('inquiry_status_events as status')
                ->join('inquiries as inquiry', 'inquiry.id', '=', 'status.inquiry_id')
                ->leftJoin('users as status_actor', 'status_actor.id', '=', 'status.actor_user_id')
                ->where('status.company_id', $companyId)->where('inquiry.company_id', $companyId)
                ->where('inquiry.customer_id', $customer->id)->whereNull('inquiry.deleted_at')
                ->selectRaw("CONCAT('inquiry-status:', status.id) as id, 'inquiry_status' as event_type, 'Request status changed' as title, status.to_status as status, status.occurred_at as occurred_at, inquiry.inquiry_number as reference, {$inquiryBookingField} as booking_id, CONCAT(COALESCE(status.from_status, 'New'), ' → ', status.to_status, CASE WHEN COALESCE(status.reason, '') = '' THEN '' ELSE CONCAT(' · ', status.reason) END, CASE WHEN status_actor.id IS NULL THEN '' ELSE CONCAT(' · by ', CONCAT_WS(' ', status_actor.first_name, status_actor.last_name)) END) as detail");
            $events->unionAll($inquiryStatusEvents);

            $inquiryAssignmentEvents = DB::table('inquiry_assignment_events as assignment')
                ->join('inquiries as inquiry', 'inquiry.id', '=', 'assignment.inquiry_id')
                ->leftJoin('users as from_user', 'from_user.id', '=', 'assignment.from_user_id')
                ->leftJoin('users as to_user', 'to_user.id', '=', 'assignment.to_user_id')
                ->leftJoin('users as assignment_actor', 'assignment_actor.id', '=', 'assignment.actor_user_id')
                ->where('assignment.company_id', $companyId)->where('inquiry.company_id', $companyId)
                ->where('inquiry.customer_id', $customer->id)->whereNull('inquiry.deleted_at')
                ->selectRaw("CONCAT('inquiry-assignment:', assignment.id) as id, 'inquiry_assignment' as event_type, 'Request employee changed' as title, assignment.event_type as status, assignment.occurred_at as occurred_at, inquiry.inquiry_number as reference, {$inquiryBookingField} as booking_id, CONCAT(COALESCE(NULLIF(CONCAT_WS(' ', from_user.first_name, from_user.last_name), ''), 'Unassigned'), ' → ', COALESCE(NULLIF(CONCAT_WS(' ', to_user.first_name, to_user.last_name), ''), 'Unassigned'), CASE WHEN COALESCE(assignment.reason, '') = '' THEN '' ELSE CONCAT(' · ', assignment.reason) END, CASE WHEN assignment_actor.id IS NULL THEN '' ELSE CONCAT(' · by ', CONCAT_WS(' ', assignment_actor.first_name, assignment_actor.last_name)) END) as detail");
            $events->unionAll($inquiryAssignmentEvents);
        }

        if ($request->user()->can('communication.view')) {
            $respondIoMessages = DB::table('integration_webhook_events as provider_event')
            ->join('inquiries as linked_inquiry', function ($join): void {
                $join->on('linked_inquiry.id', '=', 'provider_event.linked_inquiry_id')
                    ->on('linked_inquiry.company_id', '=', 'provider_event.company_id');
            })
            ->where('provider_event.company_id', $companyId)->where('provider_event.provider', 'respond_io')
            ->where('provider_event.status', 'processed')->whereNotNull('provider_event.external_message_id')
            ->whereRaw('LOWER(provider_event.event_type) LIKE ?', ['%message%'])
            ->whereRaw('LOWER(provider_event.event_type) NOT LIKE ?', ['%status%'])->whereRaw('LOWER(provider_event.event_type) NOT LIKE ?', ['%echo%'])
            ->where('linked_inquiry.customer_id', $customer->id)->whereNull('linked_inquiry.deleted_at')
            ->where(function ($direction): void {
                $direction->whereIn('provider_event.message_direction', ['incoming', 'outgoing'])
                    ->orWhere(function ($unknown): void {
                        $unknown->whereNull('provider_event.message_direction')->where(function ($eventType): void {
                            $eventType->whereRaw('LOWER(provider_event.event_type) LIKE ?', ['%incoming%'])->orWhereRaw('LOWER(provider_event.event_type) LIKE ?', ['%inbound%'])
                                ->orWhereRaw('LOWER(provider_event.event_type) LIKE ?', ['%received%'])->orWhereRaw('LOWER(provider_event.event_type) LIKE ?', ['%outgoing%'])
                                ->orWhereRaw('LOWER(provider_event.event_type) LIKE ?', ['%outbound%'])->orWhereRaw('LOWER(provider_event.event_type) LIKE ?', ['%sent%']);
                        });
                    });
            })
            ->selectRaw("CONCAT('respond-io-message:', provider_event.id) as id, 'respond_io_message' as event_type, CASE WHEN provider_event.message_direction = 'outgoing' OR LOWER(provider_event.event_type) LIKE '%outgoing%' OR LOWER(provider_event.event_type) LIKE '%outbound%' THEN 'Outgoing Respond.io message' ELSE 'Incoming Respond.io message' END as title, NULL as status, provider_event.received_at as occurred_at, NULL as reference, NULL as booking_id, NULL as detail");
            $events->unionAll($respondIoMessages);
        }

        if ($request->user()->can('communication.view')) {
            $syncedRespondIoMessages = DB::table('integration_contact_messages as synced_message')
                ->where('synced_message.company_id', $companyId)->where('synced_message.linked_customer_id', $customer->id)
                ->whereNotExists(function ($existing) use ($customer, $companyId): void {
                    $existing->selectRaw('1')->from('integration_webhook_events as provider_event')
                        ->whereColumn('provider_event.external_message_id', 'synced_message.external_message_id')
                        ->whereColumn('provider_event.company_id', 'synced_message.company_id')->where('provider_event.provider', 'respond_io')
                        ->where('provider_event.status', 'processed')->whereNotNull('provider_event.linked_inquiry_id')
                        ->whereExists(fn ($linkedInquiry) => $linkedInquiry->selectRaw('1')->from('inquiries as linked_inquiry')
                            ->whereColumn('linked_inquiry.id', 'provider_event.linked_inquiry_id')
                            ->where('linked_inquiry.company_id', $companyId)->where('linked_inquiry.customer_id', $customer->id)->whereNull('linked_inquiry.deleted_at'));
                })
                ->selectRaw("CONCAT('respond-io-history:', synced_message.id) as id, 'respond_io_history_message' as event_type, CASE WHEN synced_message.traffic = 'outgoing' THEN 'Outgoing Respond.io message' WHEN synced_message.traffic = 'incoming' THEN 'Incoming Respond.io message' ELSE 'Respond.io message' END as title, synced_message.message_type as status, synced_message.sent_at as occurred_at, NULL as reference, NULL as booking_id, NULL as detail");
            $events->unionAll($syncedRespondIoMessages);
        }

        if ($request->user()->can('phone-calls.view')) {
            $calls = DB::table('phone_calls as call')->leftJoin('users as assigned_user', 'assigned_user.id', '=', 'call.assigned_user_id')
                ->where('call.customer_id', $customer->id)->whereNull('call.deleted_at');
            $companyId ? $calls->where('call.company_id', $companyId) : $calls->whereRaw('1 = 0');
            $callBookingField = $request->user()->can('bookings.view') ? 'call.booking_id' : 'NULL';
            $calls->selectRaw("CONCAT('phone-call:', call.id) as id, 'call' as event_type, CONCAT('Phone call · ', COALESCE(NULLIF(CONCAT_WS(' ', assigned_user.first_name, assigned_user.last_name), ''), 'Unassigned')) as title, COALESCE(call.call_status, 'logged') as status, COALESCE(call.call_started_at, call.call_time) as occurred_at, call.phone as reference, {$callBookingField} as booking_id, call.summary as detail");
            $events->unionAll($calls);

            $callAssignments = DB::table('phone_call_assignments as assignment')
                ->join('phone_calls as call', 'call.id', '=', 'assignment.phone_call_id')
                ->leftJoin('users as from_user', 'from_user.id', '=', 'assignment.from_user_id')
                ->leftJoin('users as to_user', 'to_user.id', '=', 'assignment.to_user_id')
                ->leftJoin('users as actor', 'actor.id', '=', 'assignment.actor_user_id')
                ->where('assignment.company_id', $companyId)->where('call.company_id', $companyId)
                ->where('call.customer_id', $customer->id)->whereNull('call.deleted_at')
                ->selectRaw("CONCAT('call-assignment:', call.id, ':', assignment.id) as id, 'call_assignment' as event_type, 'Call employee changed' as title, 'assigned' as status, assignment.event_at as occurred_at, call.phone as reference, {$callBookingField} as booking_id, CONCAT(COALESCE(NULLIF(CONCAT_WS(' ', from_user.first_name, from_user.last_name), ''), 'Unassigned'), ' → ', COALESCE(NULLIF(CONCAT_WS(' ', to_user.first_name, to_user.last_name), ''), 'Unassigned'), CASE WHEN COALESCE(assignment.reason, '') = '' THEN '' ELSE CONCAT(' · ', assignment.reason) END, CASE WHEN actor.id IS NULL THEN '' ELSE CONCAT(' · by ', CONCAT_WS(' ', actor.first_name, actor.last_name)) END) as detail");
            $events->unionAll($callAssignments);
        }

        $canViewBookings = $request->user()->can('bookings.view');
        $communicationBookingField = $canViewBookings ? 'activity.booking_id' : 'NULL';
        $financeBookingField = $canViewBookings ? 'event.booking_id' : 'NULL';
        if ($request->user()->can('communication.view')) {
            $bookingActivity = DB::table('booking_activities as activity')->whereNull('activity.deleted_at')
            ->where('activity.event_key', '!=', 'booking_responsible_user_changed')
            ->where(function ($scope) use ($customer, $companyId, $canViewBookings) {
                $scope->whereExists(fn ($inquiry) => $inquiry->selectRaw('1')->from('inquiries as linked_inquiry')
                    ->whereColumn('linked_inquiry.id', 'activity.inquiry_id')->where('linked_inquiry.company_id', $companyId)->where('linked_inquiry.customer_id', $customer->id)->whereNull('linked_inquiry.deleted_at'));
                if ($canViewBookings) {
                    $scope->orWhereExists(fn ($booking) => $booking->selectRaw('1')->from('bookings as linked_booking')
                    ->whereColumn('linked_booking.id', 'activity.booking_id')->where('linked_booking.customer_id', $customer->id)->whereNull('linked_booking.deleted_at')
                    ->where(function ($companyScope) use ($companyId) {
                        $this->constrainBookingsToCompany($companyScope, 'linked_booking', $companyId);
                    }));
                }
            })->selectRaw("CONCAT('event:', activity.id) as id, 'communication' as event_type, activity.title as title, activity.result_status as status, activity.event_at as occurred_at, activity.event_key as reference, {$communicationBookingField} as booking_id, activity.channel as detail");
            $events->unionAll($bookingActivity);
        }

        if ($request->user()->can('payments.transactions')) {
            $finance = DB::table('financial_audit_events as event')
                ->where(fn ($scope) => $scope->where('event.company_id', $companyId)->orWhereNull('event.company_id'))
                ->whereExists(function ($booking) use ($customer, $companyId): void {
                    $booking->selectRaw('1')->from('bookings as linked_booking')
                        ->whereColumn('linked_booking.id', 'event.booking_id')->where('linked_booking.customer_id', $customer->id)
                        ->whereNull('linked_booking.deleted_at');
                    $this->constrainBookingsToCompany($booking, 'linked_booking', $companyId);
                })
                ->selectRaw("CONCAT('finance:', event.id) as id, 'payment' as event_type, event.event_type as title, COALESCE(event.to_status, event.from_status) as status, event.occurred_at as occurred_at, NULL as reference, {$financeBookingField} as booking_id, NULL as detail");
            $events->unionAll($finance);
        }

        if ($request->user()->can('sales.crm.view')) {
            $companies = DB::table('sales_activities as activity')
                ->leftJoin('sales_opportunities as opportunity', 'opportunity.id', '=', 'activity.opportunity_id')
                ->where('activity.company_id', $companyId)
                ->where(fn ($linked) => $linked->where('activity.customer_id', $customer->id)
                    ->orWhere(fn ($source) => $source->where('opportunity.customer_id', $customer->id)->whereNull('opportunity.deleted_at')))
                ->distinct()->pluck('activity.company_id');
            $activityQuery = DB::table('sales_activities as activity')
                ->leftJoin('sales_opportunities as opportunity', 'opportunity.id', '=', 'activity.opportunity_id')
                ->where('activity.company_id', $companyId)
                ->where(fn ($linked) => $linked->where('activity.customer_id', $customer->id)
                    ->orWhere(fn ($source) => $source->where('opportunity.customer_id', $customer->id)->whereNull('opportunity.deleted_at')));
            if (! $request->user()->can('sales.crm.view-all')) {
                $allowedProfiles = [];
                foreach ($companies as $companyId) {
                    $allowedProfiles = array_merge($allowedProfiles, $this->salesScope->profileIds(
                        $request->user(), 'sales.crm.view-all', 'sales.crm.view-team', (string) $companyId,
                    ) ?? []);
                }
                $activityQuery->whereIn('activity.sales_profile_id', array_values(array_unique($allowedProfiles)));
            }
            $activityBookingField = $canViewBookings ? 'activity.booking_id' : 'NULL';
            $activityQuery->selectRaw("CONCAT('sales:', activity.id) as id, CASE WHEN activity.activity_type = 'call' THEN 'call' ELSE 'sales_activity' END as event_type, activity.subject as title, activity.outcome as status, activity.occurred_at as occurred_at, activity.activity_type as reference, {$activityBookingField} as booking_id, activity.next_action as detail");
            $events->unionAll($activityQuery);

            $opportunityOwnerIds = $this->salesScope->profileIds(
                $request->user(), 'sales.crm.view-all', 'sales.crm.view-team', $companyId,
            );
            $opportunityBookingField = $canViewBookings ? 'opportunity.won_booking_id' : 'NULL';
            $stageEvents = DB::table('sales_opportunity_stage_events as stage_event')
                ->join('sales_opportunities as opportunity', 'opportunity.id', '=', 'stage_event.opportunity_id')
                ->leftJoin('sales_profiles as from_profile', function ($join) use ($companyId): void {
                    $join->on('from_profile.id', '=', 'stage_event.from_owner_sales_profile_id')->where('from_profile.company_id', '=', $companyId);
                })
                ->leftJoin('staff as from_staff', function ($join) use ($companyId): void {
                    $join->on('from_staff.id', '=', 'from_profile.staff_id')->where('from_staff.company_id', '=', $companyId);
                })
                ->leftJoin('users as from_user', 'from_user.id', '=', 'from_staff.user_id')
                ->join('sales_profiles as to_profile', function ($join) use ($companyId): void {
                    $join->on('to_profile.id', '=', 'stage_event.to_owner_sales_profile_id')->where('to_profile.company_id', '=', $companyId);
                })
                ->join('staff as to_staff', function ($join) use ($companyId): void {
                    $join->on('to_staff.id', '=', 'to_profile.staff_id')->where('to_staff.company_id', '=', $companyId);
                })
                ->join('users as to_user', 'to_user.id', '=', 'to_staff.user_id')
                ->leftJoin('users as actor', 'actor.id', '=', 'stage_event.actor_user_id')
                ->where('opportunity.company_id', $companyId)->whereNull('opportunity.deleted_at')
                ->where(fn ($linked) => $linked->where('opportunity.customer_id', $customer->id)
                    ->orWhereExists(fn ($inquiry) => $inquiry->selectRaw('1')->from('inquiries as source_inquiry')
                        ->whereColumn('source_inquiry.id', 'opportunity.inquiry_id')->where('source_inquiry.company_id', $companyId)
                        ->where('source_inquiry.customer_id', $customer->id)->whereNull('source_inquiry.deleted_at')))
                ->when($opportunityOwnerIds !== null, fn ($query) => $query->where(function ($owners) use ($opportunityOwnerIds): void {
                    $owners->whereIn('stage_event.to_owner_sales_profile_id', $opportunityOwnerIds)
                        ->orWhereIn('stage_event.from_owner_sales_profile_id', $opportunityOwnerIds);
                }))
                ->selectRaw("CONCAT(CASE WHEN stage_event.reason_code = 'owner_transfer' THEN CONCAT(COALESCE(NULLIF(CONCAT_WS(' ', from_user.first_name, from_user.last_name), ''), 'Unassigned'), ' → ', CONCAT_WS(' ', to_user.first_name, to_user.last_name)) ELSE CONCAT(COALESCE(stage_event.from_stage, 'New'), ' → ', stage_event.to_stage) END, CASE WHEN COALESCE(stage_event.reason, '') = '' THEN '' ELSE CONCAT(' · ', stage_event.reason) END, CASE WHEN actor.id IS NULL THEN '' ELSE CONCAT(' · by ', CONCAT_WS(' ', actor.first_name, actor.last_name)) END) as detail");
            $events->unionAll($stageEvents);

            $taskQuery = DB::table('sales_tasks as task')->where('task.company_id', $companyId)
                ->where(function ($related) use ($customer, $companyId): void {
                    $related->where('task.customer_id', $customer->id)
                        ->orWhereExists(fn ($opportunity) => $opportunity->selectRaw('1')->from('sales_opportunities as linked_opportunity')
                            ->whereColumn('linked_opportunity.id', 'task.opportunity_id')->where('linked_opportunity.company_id', $companyId)
                            ->where('linked_opportunity.customer_id', $customer->id)->whereNull('linked_opportunity.deleted_at'))
                        ->orWhereExists(fn ($inquiry) => $inquiry->selectRaw('1')->from('inquiries as linked_inquiry')
                            ->whereColumn('linked_inquiry.id', 'task.inquiry_id')->where('linked_inquiry.company_id', $companyId)
                            ->where('linked_inquiry.customer_id', $customer->id)->whereNull('linked_inquiry.deleted_at'))
                        ->orWhereExists(function ($booking) use ($customer, $companyId): void {
                            $booking->selectRaw('1')->from('bookings as linked_booking')
                                ->whereColumn('linked_booking.id', 'task.booking_id')->where('linked_booking.customer_id', $customer->id)
                                ->whereNull('linked_booking.deleted_at');
                            $this->constrainBookingsToCompany($booking, 'linked_booking', $companyId);
                        });
                });
            if (! $request->user()->can('sales.crm.view-all')) {
                $allowedProfiles = $this->salesScope->profileIds(
                    $request->user(), 'sales.crm.view-all', 'sales.crm.view-team', $companyId,
                ) ?? [];
                $taskQuery->whereIn('task.owner_sales_profile_id', $allowedProfiles);
            }
            $taskBookingField = $canViewBookings ? 'task.booking_id' : 'NULL';
            $taskQuery->selectRaw("CONCAT('task:', task.id) as id, 'follow_up' as event_type, task.title as title, task.status as status, task.due_at as occurred_at, NULL as reference, {$taskBookingField} as booking_id, task.priority as detail");
            $events->unionAll($taskQuery);
        }

        $page = (int) $request->input('page', 1);
        $perPage = (int) $request->input('per_page', 30);
        $timeline = DB::query()->fromSub($events, 'customer_timeline')->orderByDesc('occurred_at')->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);

        if ($request->user()->can('communication.view')) {
            $historyIds = $timeline->getCollection()->filter(fn ($entry) => $entry->event_type === 'respond_io_history_message')
                ->map(fn ($entry) => Str::after($entry->id, 'respond-io-history:'))->all();
            $providerEventIds = $timeline->getCollection()->filter(fn ($entry) => $entry->event_type === 'respond_io_message')
                ->map(fn ($entry) => Str::after($entry->id, 'respond-io-message:'))->all();
            $externalIdsByEvent = $providerEventIds ? IntegrationWebhookEvent::query()->where('company_id', $companyId)
                ->where('provider', 'respond_io')->whereIn('id', $providerEventIds)->pluck('external_message_id', 'id') : collect();
            $externalMessageIds = $externalIdsByEvent->filter()->values();
            $syncedMessages = ($historyIds || $externalMessageIds->isNotEmpty())
                ? IntegrationContactMessage::query()->where('company_id', $companyId)->where('linked_customer_id', $customer->id)
                    ->where(function ($query) use ($historyIds, $externalMessageIds): void {
                        if ($historyIds) $query->whereIn('id', $historyIds);
                        if ($externalMessageIds->isNotEmpty()) $query->orWhereIn('external_message_id', $externalMessageIds);
                    })->get(['id', 'external_message_id', 'content'])
                : collect();
            $contentByHistoryId = $syncedMessages->mapWithKeys(fn (IntegrationContactMessage $message) => [$message->id => $message->content]);
            $contentByExternalId = $syncedMessages->mapWithKeys(fn (IntegrationContactMessage $message) => [$message->external_message_id => $message->content]);
            $timeline->getCollection()->each(function ($entry) use ($contentByHistoryId, $contentByExternalId, $externalIdsByEvent): void {
                if ($entry->event_type === 'respond_io_history_message') {
                    $entry->detail = $contentByHistoryId[Str::after($entry->id, 'respond-io-history:')] ?? null;
                } elseif ($entry->event_type === 'respond_io_message') {
                    $eventId = Str::after($entry->id, 'respond-io-message:');
                    $externalId = $externalIdsByEvent[$eventId] ?? null;
                    $entry->detail = $externalId ? ($contentByExternalId[$externalId] ?? null) : null;
                }
            });
        }

        return response()->json(['status' => 'success', 'data' => $timeline]);
    }

    private function customerIdentityDetails(Customer $customer)
    {
        $userIds = collect([(string) $customer->user_id])
            ->merge(DB::table('customer_user_aliases')->where('customer_id', $customer->id)->pluck('user_id')->map(fn ($id) => (string) $id))
            ->filter()->unique()->values();

        return User::withTrashed()->whereIn('id', $userIds)->get(['id', 'email', 'phone', 'first_name', 'last_name']);
    }

    private function matchCustomerIdentityQuery($query, array $emails, array $phones): void
    {
        $query->where(function ($identity) use ($emails, $phones): void {
            if ($emails) $identity->whereIn(DB::raw('LOWER(TRIM(email))'), $emails);
            if ($phones) {
                $expression = PhoneNumberMatch::sqlExpression('phone');
                $emails ? $identity->orWhereIn(DB::raw($expression), $phones) : $identity->whereIn(DB::raw($expression), $phones);
            }
        });
    }

    private function constrainBookingsToCompany($query, string $bookingAlias, string $companyId): void
    {
        \App\Services\CompanyBookingScope::constrain($query, $bookingAlias, $companyId);
    }

    /**
     * Get customer loyalty info
     * GET /api/customers/{customer}/loyalty
     */
    public function getCustomerLoyalty(Customer $customer): JsonResponse
    {
        $customer = $this->requireCompanyCustomer($customer);
        $user = $customer->user;

        return response()->json([
            'status' => 'success',
            'data' => [
                'points' => $user->getPoints(),
                'tier' => $this->getLoyaltyTier($user->getPoints()),
                'badges' => $user->badges()->get(),
                'rank' => $user->getRank()
            ]
        ]);
    }

    /**
     * Get customer feedback
     * GET /api/customers/{customer}/feedback
     */
    public function getCustomerFeedback(Customer $customer): JsonResponse
    {
        $customer = $this->requireCompanyCustomer($customer);
        $feedback = Booking::query()->where('customer_id', $customer->id)
            ->whereNotNull('customer_rating')->where('status', 'completed')
            ->with(['customer.user', 'serviceType'])->orderByDesc('customer_feedback_submitted_at')->orderByDesc('updated_at');
        \App\Services\CompanyBookingScope::constrain($feedback, 'bookings', $this->requireActiveCompanyId());

        return response()->json([
            'status' => 'success',
            'data' => ['feedback' => $feedback->get()->map(fn (Booking $booking) => $this->formatCustomerFeedback($booking))->values()],
        ]);
    }

    /**
     * Add customer feedback
     * POST /api/customers/{customer}/feedback
     */
    public function addCustomerFeedback(Request $request, Customer $customer): JsonResponse
    {
        $customer = $this->requireCompanyCustomer($customer);
        $data = $request->validate(['booking_id' => ['required', 'uuid'], 'rating' => ['required', 'integer', 'min:1', 'max:5'], 'comment' => ['nullable', 'string', 'max:1000']]);
        $booking = $this->saveBookingFeedback($data['booking_id'], $customer->id, $data);
        return response()->json([
            'status' => 'success',
            'message' => 'Feedback saved.',
            'data' => ['feedback' => $this->formatCustomerFeedback($booking)],
        ]);
    }

    /**
     * Get customer analytics
     * GET /api/customers/analytics
     */
    public function getCustomerAnalytics(): JsonResponse
    {
        $companyId = $this->requireActiveCompanyId();
        $customers = $this->companyCustomers($companyId);
        $totalCustomers = (clone $customers)->count();
        $newThisMonth = (clone $customers)->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();
        $activeCustomers = (clone $customers)->whereHas('bookings', function ($q) use ($companyId) {
            $q->where('created_at', '>=', now()->subDays(30));
            \App\Services\CompanyBookingScope::constrain($q, 'bookings', $companyId);
        })->count();
        $inactiveCustomers = max($totalCustomers - $activeCustomers, 0);
        $customersWithLicense = (clone $customers)->whereNotNull('license_no')
            ->where('license_no', '!=', '')
            ->count();
        $bookings = Booking::query();
        \App\Services\CompanyBookingScope::constrain($bookings, 'bookings', $companyId);
        $totalBookings = $bookings->count();

        $analytics = [
            // Dashboard-compatible keys used by the portal customer dashboard.
            'total' => $totalCustomers,
            'active' => $activeCustomers,
            'inactive' => $inactiveCustomers,
            'withLicense' => $customersWithLicense,
            'totalBookings' => $totalBookings,

            // Analytics keys used by the richer analytics screens.
            'total_customers' => $totalCustomers,
            'new_customers_this_month' => $newThisMonth,
            'active_customers' => $activeCustomers,
            'inactive_customers' => $inactiveCustomers,
            'customers_with_license' => $customersWithLicense,
            'total_bookings' => $totalBookings,
            'totalCustomers' => $totalCustomers,
            'newCustomersThisMonth' => $newThisMonth,
            'activeCustomers' => $activeCustomers,
            'inactiveCustomers' => $inactiveCustomers,
            'customersWithLicense' => $customersWithLicense,
            'averageLifetimeValue' => (float) (Booking::query()
                ->where(function ($q) use ($companyId): void { \App\Services\CompanyBookingScope::constrain($q, 'bookings', $companyId); })
                ->selectRaw('AVG(COALESCE(total_actual, total_estimated, 0)) as average_lifetime_value')
                ->value('average_lifetime_value') ?? 0),
            'customer_growth' => (clone $customers)->selectRaw('DATE(created_at) as date, COUNT(*) as count')
                ->where('created_at', '>=', now()->subDays(30))
                ->groupBy('date')
                ->orderBy('date')
                ->get()
        ];

        return response()->json([
            'status' => 'success',
            'data' => $analytics
        ]);
    }

    public function getCustomerGrowth(Request $request): JsonResponse
    {
        $customers = $this->companyCustomers($this->requireActiveCompanyId());
        $startDate = $request->date('start_date') ?? now()->subDays(30);
        $endDate = $request->date('end_date') ?? now();

        $rows = $customers->selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->whereBetween('created_at', [$startDate->startOfDay(), $endDate->endOfDay()])
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => [
                'dates' => $rows->pluck('date')->values(),
                'values' => $rows->pluck('count')->values(),
            ],
        ]);
    }

    public function getCustomerSegments(): JsonResponse
    {
        $segments = $this->companyCustomers($this->requireActiveCompanyId())->selectRaw("COALESCE(type, 'Unclassified') as name, COUNT(*) as value")
            ->groupBy('type')
            ->orderByDesc('value')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $segments,
        ]);
    }

    public function getCustomerCohort(): JsonResponse
    {
        $customers = $this->companyCustomers($this->requireActiveCompanyId());
        $cohortExpression = DB::connection()->getDriverName() === 'pgsql'
            ? "TO_CHAR(created_at, 'YYYY-MM')"
            : "DATE_FORMAT(created_at, '%Y-%m')";

        $cohorts = $customers->selectRaw("{$cohortExpression} as month, COUNT(*) as customers")
            ->where('created_at', '>=', now()->subMonths(12))
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->map(fn ($cohort) => [
                'month' => $cohort->month,
                'customers' => (int) $cohort->customers,
                'retention' => collect(range(1, 6))
                    ->map(fn ($month) => [
                        'month' => $month,
                        'rate' => 0,
                    ])
                    ->values(),
            ]);

        return response()->json([
            'status' => 'success',
            'data' => $cohorts,
        ]);
    }

    public function getTopCustomers(Request $request): JsonResponse
    {
        $limit = (int) $request->get('limit', 10);
        $companyId = $this->requireActiveCompanyId();

        $customers = $this->companyCustomers($companyId)->with('user')
            ->withCount(['bookings' => fn ($query) => \App\Services\CompanyBookingScope::constrain($query, 'bookings', $companyId)])
            ->withSum(['bookings as total_revenue' => fn ($query) => \App\Services\CompanyBookingScope::constrain($query, 'bookings', $companyId)], 'total_actual')
            ->orderByDesc('bookings_count')
            ->limit(min(max($limit, 1), 50))
            ->get()
            ->map(fn (Customer $customer) => [
                'id' => $customer->id,
                'name' => $customer->full_name,
                'email' => $customer->email,
                'totalBookings' => $customer->bookings_count,
                'totalRevenue' => (float) ($customer->total_revenue ?? 0),
                'averageOrderValue' => $customer->bookings_count > 0
                    ? (float) ($customer->total_revenue ?? 0) / $customer->bookings_count
                    : 0,
                'lastBooking' => $customer->bookings()->where(function ($query) use ($companyId): void {
                    \App\Services\CompanyBookingScope::constrain($query, 'bookings', $companyId);
                })->latest('created_at')->value('created_at'),
            ]);

        return response()->json([
            'status' => 'success',
            'data' => $customers,
        ]);
    }

    /**
     * Export customers
     * GET /api/customers/export
     */
    public function exportCustomers(Request $request): StreamedResponse
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'in:active,inactive'],
            'is_verified' => ['nullable', 'boolean'],
        ]);

        $companyId = $this->requireActiveCompanyId();
        $query = $this->companyCustomers($companyId)->with('user');

        if ($request->filled('search')) {
            $search = trim((string) $request->get('search'));
            $query->where(function ($builder) use ($search) {
                $builder->whereHas('user', function ($userQuery) use ($search) {
                    $userQuery->whereLikeInsensitive('first_name', $search)
                        ->orWhereLikeInsensitive('last_name', $search)
                        ->orWhereLikeInsensitive('email', $search)
                        ->orWhereLikeInsensitive('phone', $search);
                })
                    ->orWhereLikeInsensitive('code', $search)
                    ->orWhereLikeInsensitive('nic', $search)
                    ->orWhereLikeInsensitive('passport_number', $search)
                    ->orWhereLikeInsensitive('license_no', $search)
                    ->orWhereLikeInsensitive('address', $search)
                    ->orWhereLikeInsensitive('country', $search)
                    ->orWhereLikeInsensitive('city', $search);
            });
        }

        if ($request->filled('status')) {
            $isActive = $request->get('status') === 'active';
            $query->whereHas('user', fn ($userQuery) => $userQuery->where('is_active', $isActive));
        }

        if ($request->has('is_verified')) {
            $verified = filter_var($request->get('is_verified'), FILTER_VALIDATE_BOOLEAN);
            $query->whereHas('user', function ($userQuery) use ($verified) {
                if ($verified) {
                    $userQuery->whereNotNull('email_verified_at');
                } else {
                    $userQuery->whereNull('email_verified_at');
                }
            });
        }

        $filename = 'customers-' . now()->format('Y-m-d-His') . '.csv';

        return response()->streamDownload(function () use ($query): void {
            $output = fopen('php://output', 'wb');
            fputcsv($output, [
                'customer_id',
                'code',
                'name',
                'email',
                'phone',
                'status',
                'email_verified',
                'phone_verified',
                'type',
                'nic',
                'passport_number',
                'license_number',
                'country',
                'city',
                'created_at',
            ]);

            $query->orderBy('id')->chunk(500, function ($customers) use ($output): void {
                foreach ($customers as $customer) {
                    fputcsv($output, [
                        $this->csvValue($customer->id),
                        $this->csvValue($customer->code),
                        $this->csvValue($customer->full_name),
                        $this->csvValue($customer->user?->email),
                        $this->csvValue($customer->user?->phone),
                        $customer->user?->is_active ? 'active' : 'inactive',
                        $customer->user?->email_verified_at ? 'yes' : 'no',
                        $customer->user?->phone_verified_at ? 'yes' : 'no',
                        $this->csvValue($customer->type),
                        $this->csvValue($customer->nic),
                        $this->csvValue($customer->passport_number),
                        $this->csvValue($customer->license_no),
                        $this->csvValue($customer->getAttribute('country')),
                        $this->csvValue($customer->city),
                        $customer->created_at?->toIso8601String(),
                    ]);
                }
            });

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache',
        ]);
    }

    private function csvValue(mixed $value): string
    {
        $value = (string) $value;

        return preg_match('/^[=+\-@]/', $value) === 1 ? "'" . $value : $value;
    }

    /**
     * Get feedback statistics
     * GET /api/customers/feedback/stats
     */
    public function getFeedbackStats(): JsonResponse
    {
        $base = Booking::query()->whereNotNull('customer_rating')->where('status', 'completed');
        \App\Services\CompanyBookingScope::constrain($base, 'bookings', $this->requireActiveCompanyId());
        $summary = (clone $base)->selectRaw('COUNT(*) as total_feedback, AVG(customer_rating) as average_rating')->first();
        $distribution = (clone $base)->select('customer_rating')->selectRaw('COUNT(*) as total')
            ->groupBy('customer_rating')->pluck('total', 'customer_rating')->all();
        $total = (int) ($summary->total_feedback ?? 0);
        $pending = (clone $base)->whereNull('customer_feedback_response')->count();
        $ratingDistribution = array_fill_keys(['1', '2', '3', '4', '5'], 0);
        foreach ($distribution as $rating => $count) $ratingDistribution[(string) $rating] = (int) $count;

        return response()->json([
            'status' => 'success',
            'data' => [
                'total_feedback' => $total,
                'average_rating' => round((float) ($summary->average_rating ?? 0), 2),
                'rating_distribution' => $ratingDistribution,
                'pending_reviews' => $pending,
                'response_rate' => $total ? (int) round((($total - $pending) / $total) * 100) : 0,
            ],
        ]);
    }

    /**
     * Get customer feedback (general)
     * GET /api/customers/feedback
     */
    public function getAllCustomerFeedback(Request $request): JsonResponse
    {
        $data = $request->validate(['rating' => ['nullable', 'integer', 'between:1,5'], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $feedback = Booking::query()->with(['customer.user', 'serviceType'])
            ->whereNotNull('customer_rating')->where('status', 'completed')
            ->when(isset($data['rating']), fn ($query) => $query->where('customer_rating', $data['rating']))
            ->orderByDesc('customer_feedback_submitted_at')->orderByDesc('updated_at');
        \App\Services\CompanyBookingScope::constrain($feedback, 'bookings', $this->requireActiveCompanyId());
        $page = $feedback->paginate($data['per_page'] ?? 10, ['*'], 'page', $data['page'] ?? 1);

        return response()->json([
            'status' => 'success',
            'data' => [
                'feedback' => $page->getCollection()->map(fn (Booking $booking) => $this->formatCustomerFeedback($booking))->values(),
                'total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(),
            ]
        ]);
    }

    /**
     * Submit customer feedback (general)
     * POST /api/customers/feedback
     */
    public function submitCustomerFeedback(Request $request): JsonResponse
    {
        $data = $request->validate(['customer_id' => ['required', 'uuid'], 'booking_id' => ['required', 'uuid'], 'rating' => ['required', 'integer', 'min:1', 'max:5'], 'comment' => ['nullable', 'string', 'max:1000']]);
        $customer = $this->requireCompanyCustomer(Customer::query()->findOrFail($data['customer_id']));
        $booking = $this->saveBookingFeedback($data['booking_id'], $customer->id, $data);
        return response()->json([
            'status' => 'success',
            'message' => 'Feedback saved.',
            'data' => ['feedback' => $this->formatCustomerFeedback($booking)],
        ]);
    }

    /**
     * Respond to customer feedback.
     * POST /api/customers/feedback/{feedbackId}/respond
     */
    public function respondToFeedback(Request $request, string $feedbackId): JsonResponse
    {
        $data = $request->validate([
            'response' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        $companyId = $this->requireActiveCompanyId();
        $booking = DB::transaction(function () use ($feedbackId, $data, $request, $companyId): Booking {
            $bookingQuery = Booking::query()->whereKey($feedbackId)->whereNotNull('customer_rating')->where('status', 'completed')->lockForUpdate();
            \App\Services\CompanyBookingScope::constrain($bookingQuery, 'bookings', $companyId);
            $booking = $bookingQuery->firstOrFail();
            $response = trim($data['response']);
            if ($booking->customer_feedback_response) {
                abort_unless($booking->customer_feedback_response === $response, 409, 'A response is already saved for this feedback.');
                return $booking->fresh(['customer.user', 'serviceType']);
            }
            $booking->forceFill([
                'customer_feedback_response' => $response,
                'customer_feedback_responded_at' => now(),
                'customer_feedback_responded_by' => $request->user()?->id,
            ])->save();
            return $booking->fresh(['customer.user', 'serviceType']);
        });
        return response()->json([
            'status' => 'success',
            'message' => 'Response saved to the booking feedback.',
            'data' => ['feedback' => $this->formatCustomerFeedback($booking->fresh(['customer.user', 'serviceType']))],
        ]);
    }

    private function saveBookingFeedback(string $bookingId, string $customerId, array $data): Booking
    {
        $companyId = $this->requireActiveCompanyId();
        return DB::transaction(function () use ($bookingId, $customerId, $data, $companyId): Booking {
            $query = Booking::query()->whereKey($bookingId)->where('customer_id', $customerId)->lockForUpdate();
            \App\Services\CompanyBookingScope::constrain($query, 'bookings', $companyId);
            $booking = $query->firstOrFail();
            abort_unless($booking->status === 'completed', 409, 'Feedback can only be recorded for a completed booking.');
            if ($booking->customer_rating !== null) {
                abort_unless((int) $booking->customer_rating === (int) $data['rating']
                    && (string) ($booking->customer_feedback ?? '') === (string) ($data['comment'] ?? ''),
                    409, 'Feedback is already recorded for this booking. Contact a manager if it needs correction.');
                return $booking->fresh(['customer.user', 'serviceType']);
            }
            $booking->forceFill([
                'customer_rating' => $data['rating'],
                'customer_feedback' => $data['comment'] ?? null,
                'customer_feedback_submitted_at' => now(),
            ])->save();
            return $booking->fresh(['customer.user', 'serviceType']);
        });
    }

    private function formatCustomerFeedback(Booking $booking): array
    {
        $user = $booking->customer?->user;
        return [
            'id' => (string) $booking->id,
            'booking_id' => (string) $booking->id,
            'booking_number' => $booking->booking_number,
            'customer_id' => (string) $booking->customer_id,
            'customer_name' => trim(($user?->first_name ?? '').' '.($user?->last_name ?? '')) ?: 'Customer',
            'service_type' => $booking->serviceType?->name,
            'rating' => (int) $booking->customer_rating,
            'comment' => $booking->customer_feedback,
            'created_at' => $booking->customer_feedback_submitted_at ?? $booking->updated_at,
            'response' => $booking->customer_feedback_response,
            'response_date' => $booking->customer_feedback_responded_at,
            'responseDate' => $booking->customer_feedback_responded_at,
            'responded_by' => $booking->customer_feedback_responded_by,
            'responded' => (bool) $booking->customer_feedback_response,
        ];
    }

    public function getDocumentStats(): JsonResponse
    {
        $companyId = $this->requireActiveCompanyId();
        $documents = Document::query()->where('documentable_type', Customer::class)
            ->whereHasMorph('documentable', [Customer::class], fn ($query) => \App\Services\CompanyCustomerScope::constrain($query, 'customers', $companyId));
        return response()->json([
            'status' => 'success',
            'data' => [
                'totalDocuments' => (clone $documents)->count(),
                'pendingVerification' => (clone $documents)->where('status', 'pending')->count(),
                'verifiedDocuments' => (clone $documents)->where('status', 'verified')->count(),
                'rejectedDocuments' => (clone $documents)->where('status', 'rejected')->count(),
            ],
        ]);
    }

    public function getDocuments(Request $request): JsonResponse
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', 'string', 'in:pending,verified,rejected'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $companyId = $this->requireActiveCompanyId();
        $query = Document::with('documentable.user')
            ->where('documentable_type', Customer::class)
            ->whereHasMorph('documentable', [Customer::class], fn ($customerQuery) => \App\Services\CompanyCustomerScope::constrain($customerQuery, 'customers', $companyId))
            ->when($request->filled('search'), function ($builder) use ($request) {
                $search = trim((string) $request->get('search'));

                $builder->where(function ($nested) use ($search) {
                    $nested->whereLikeInsensitive('document_number', $search)
                        ->orWhereLikeInsensitive('file_name', $search)
                        ->orWhereHasMorph('documentable', [Customer::class], function ($customerQuery) use ($search) {
                            $customerQuery->whereHas('user', function ($userQuery) use ($search) {
                                $userQuery->whereLikeInsensitive('first_name', $search)
                                    ->orWhereLikeInsensitive('last_name', $search)
                                    ->orWhereLikeInsensitive('email', $search)
                                    ->orWhereLikeInsensitive('phone', $search);
                            });
                        });
                });
            })
            ->when($request->filled('type'), fn ($builder) => $builder->where('document_type', $request->get('type')))
            ->when($request->filled('status'), fn ($builder) => $builder->where('status', $request->get('status')))
            ->latest();

        $documents = $query->paginate($request->integer('per_page', 15));

        return response()->json([
            'status' => 'success',
            'data' => [
                'documents' => $documents->getCollection()
                    ->map(fn (Document $document) => $this->formatCustomerDocument($document))
                    ->values(),
                'data' => $documents->getCollection()
                    ->map(fn (Document $document) => $this->formatCustomerDocument($document))
                    ->values(),
                'current_page' => $documents->currentPage(),
                'last_page' => $documents->lastPage(),
                'per_page' => $documents->perPage(),
                'total' => $documents->total(),
            ],
        ]);
    }

    public function showDocument(Document $document): JsonResponse
    {
        $this->abortUnlessCustomerDocument($document);
        $document->load('documentable.user');

        return response()->json([
            'status' => 'success',
            'data' => $this->formatCustomerDocument($document),
        ]);
    }

    public function storeDocument(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'uuid', 'exists:customers,id'],
            'document_type' => ['required', 'string', 'max:50'],
            'document_number' => ['required', 'string', 'max:255'],
            'expiry_date' => ['nullable', 'date'],
            'file' => ['required', 'file', 'max:10240'],
        ]);
        $this->requireCompanyCustomer(Customer::query()->findOrFail($data['customer_id']));

        $file = $request->file('file');
        $disk = 'public';
        $path = $file->store('documents/customer/' . $data['customer_id'], $disk);

        $document = Document::create([
            'documentable_type' => Customer::class,
            'documentable_id' => $data['customer_id'],
            'document_type' => $data['document_type'],
            'document_number' => $data['document_number'],
            'expiry_date' => $data['expiry_date'] ?? null,
            'disk' => $disk,
            'path' => $path,
            'file_name' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(),
            'file_type' => $file->getClientMimeType(),
            'status' => 'pending',
        ]);

        $document->load('documentable.user');

        return response()->json([
            'status' => 'success',
            'message' => 'Document uploaded successfully',
            'data' => $this->formatCustomerDocument($document),
        ], 201);
    }

    public function downloadDocument(Document $document): mixed
    {
        $this->abortUnlessCustomerDocument($document);

        if (!Storage::disk($document->disk)->exists($document->path)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Document file not found',
            ], 404);
        }

        return Storage::disk($document->disk)->download($document->path, $document->file_name);
    }

    public function verifyDocument(Request $request, Document $document): JsonResponse
    {
        $this->abortUnlessCustomerDocument($document);

        $data = $request->validate([
            'verification_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $document->update([
            'status' => 'verified',
            'verification_notes' => $data['verification_notes'] ?? $document->verification_notes,
            'verified_at' => now(),
            'verified_by' => $request->user()?->id,
        ]);

        $document->load('documentable.user');

        return response()->json([
            'status' => 'success',
            'message' => 'Document verified successfully',
            'data' => $this->formatCustomerDocument($document),
        ]);
    }

    public function rejectDocument(Request $request, Document $document): JsonResponse
    {
        $this->abortUnlessCustomerDocument($document);

        $data = $request->validate([
            'verification_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $document->update([
            'status' => 'rejected',
            'verification_notes' => $data['verification_notes'] ?? $document->verification_notes,
            'verified_at' => null,
            'verified_by' => null,
        ]);

        $document->load('documentable.user');

        return response()->json([
            'status' => 'success',
            'message' => 'Document rejected',
            'data' => $this->formatCustomerDocument($document),
        ]);
    }

    public function getCustomerDocuments(Customer $customer, Request $request): JsonResponse
    {
        $customer = $this->requireCompanyCustomer($customer);

        $documents = $customer->documents()
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return response()->json([
            'status' => 'success',
            'data' => [
                'documents' => $documents->getCollection()
                    ->map(fn (Document $document) => $this->formatCustomerDocument($document))
                    ->values(),
                'data' => $documents->getCollection()
                    ->map(fn (Document $document) => $this->formatCustomerDocument($document))
                    ->values(),
                'current_page' => $documents->currentPage(),
                'last_page' => $documents->lastPage(),
                'per_page' => $documents->perPage(),
                'total' => $documents->total(),
            ],
        ]);
    }

    /**
     * Get loyalty tier based on points
     */
    private function getLoyaltyTier(int $points): array
    {
        if ($points >= 1000) {
            return ['name' => 'Platinum', 'color' => '#E5E4E2'];
        } elseif ($points >= 500) {
            return ['name' => 'Gold', 'color' => '#FFD700'];
        } elseif ($points >= 100) {
            return ['name' => 'Silver', 'color' => '#C0C0C0'];
        } else {
            return ['name' => 'Bronze', 'color' => '#CD7F32'];
        }
    }

    private function formatCustomerDocument(Document $document): array
    {
        $customer = $document->documentable instanceof Customer ? $document->documentable : null;

        return [
            'id' => $document->id,
            'customer_id' => $customer?->id,
            'customer_name' => $customer?->full_name,
            'customer_email' => $customer?->email,
            'documentable_type' => $document->documentable_type,
            'documentable_id' => $document->documentable_id,
            'customer' => $customer ? [
                'id' => $customer->id,
                'name' => $customer->full_name,
                'email' => $customer->email,
                'phone' => $customer->phone,
            ] : null,
            'document_type' => $document->document_type,
            'document_number' => $document->document_number,
            'expiry_date' => $document->expiry_date?->toDateString(),
            'file_url' => Storage::disk($document->disk)->url($document->path),
            'file_name' => $document->file_name,
            'file_size' => $document->file_size,
            'file_type' => $document->file_type,
            'status' => $document->status,
            'verification_notes' => $document->verification_notes,
            'verified_at' => $document->verified_at?->toISOString(),
            'created_at' => $document->created_at?->toISOString(),
            'updated_at' => $document->updated_at?->toISOString(),
        ];
    }

    private function abortUnlessCustomerDocument(Document $document): void
    {
        abort_unless($document->documentable_type === Customer::class, 404);
        $customer = $document->documentable;
        abort_unless($customer instanceof Customer, 404);
        $this->requireCompanyCustomer($customer);
    }

    private function requireActiveCompanyId(): string
    {
        $companyId = app(SingleCompanyScope::class)->activeDefaultCompany()?->id;
        abort_unless($companyId, 409, 'Select an active company before viewing customer data.');

        return (string) $companyId;
    }

    private function companyCustomers(string $companyId)
    {
        $query = Customer::query();
        \App\Services\CompanyCustomerScope::constrain($query, 'customers', $companyId);

        return $query;
    }

    private function requireCompanyCustomer(Customer $customer, bool $lock = false): Customer
    {
        $query = Customer::query()->whereKey($customer->id);
        \App\Services\CompanyCustomerScope::constrain($query, 'customers', $this->requireActiveCompanyId());
        if ($lock) $query->lockForUpdate();

        return $query->firstOrFail();
    }
}
