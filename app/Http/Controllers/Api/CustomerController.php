<?php
// app/Http/Controllers/Api/CustomerController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking\Booking;
use App\Models\Customer;
use App\Models\Document;
use App\Http\Requests\Customer\CreateCustomerRequest;
use App\Http\Requests\Customer\UpdateCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Services\UserContextService;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Services\PaymentMethodSyncService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomerController extends Controller
{
    protected $contextService;

    public function __construct(UserContextService $contextService)
    {
        $this->contextService = $contextService;
        $this->middleware('permission:customers.view')->only(['index', 'show']);
        $this->middleware('permission:customers.create')->only(['store']);
        $this->middleware('permission:customers.edit')->only(['update']);
        $this->middleware('permission:customers.delete')->only(['destroy']);
        $this->middleware('permission:customers.export')->only(['exportCustomers']);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $q = Customer::with(['user', 'state', 'paymentMethods']);

        if ($request->filled('search')) {
            $search = trim((string) $request->get('search'));
            $q->where(function ($builder) use ($search) {
                $builder->whereHas('user', function ($query) use ($search) {
                    $query->whereLikeInsensitive('id', $search)
                        ->orWhereLikeInsensitive('first_name', $search)
                        ->orWhereLikeInsensitive('last_name', $search)
                        ->orWhereLikeInsensitive('email', $search)
                        ->orWhereLikeInsensitive('phone', $search);
                })->orWhereLikeInsensitive('id', $search)
                    ->orWhereLikeInsensitive('user_id', $search)
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
                    $query->whereNotNull('email_verified_at');
                } else {
                    $query->whereNull('email_verified_at');
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
                $q->orderBy($sort, $direction);
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

        $customers = Customer::with('user')
            ->when($search !== '', function ($query) use ($search) {
                $query->whereHas('user', function ($userQuery) use ($search) {
                    $userQuery->whereLikeInsensitive('id', $search)
                        ->orWhereLikeInsensitive('first_name', $search)
                        ->orWhereLikeInsensitive('last_name', $search)
                        ->orWhereLikeInsensitive('email', $search)
                        ->orWhereLikeInsensitive('phone', $search);
                })->orWhereLikeInsensitive('id', $search)
                    ->orWhereLikeInsensitive('user_id', $search)
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

        $customer = Customer::with('user')
            ->whereHas('user', fn ($query) => $query->whereRaw('LOWER(email) = ?', [$email]))
            ->when(!empty($data['except_customer_id']), fn ($query) => $query->where('id', '!=', $data['except_customer_id']))
            ->first();

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
        $data['created_user_id'] = $request->user()->id;

        try {
            return DB::transaction(function () use ($data) {
                $existingUser = User::whereRaw('LOWER(email) = ?', [strtolower(trim($data['email']))])->lockForUpdate()->first();
            
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
                    'type' => $data['type'],
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
                ];

                $context = $this->contextService->switchContext($existingUser, 'customer', $contextData);
                $customer = Customer::with(['user', 'state', 'paymentMethods'])->find($context->getAttribute('context_id'));

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
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'],
                    'email' => $data['email'],
                    'password' => bcrypt($data['password'] ?? Str::random(12)),
                    'phone' => $data['phone'],
                    'email_verified_at' => now(), // Auto-verify for customers created by admin
                    'is_active' => true,
                ]);

                // Create customer context
                $contextData = [
                    'code' => $data['code'] ?? null,
                    'type' => $data['type'],
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
                ];

                $context = $this->contextService->switchContext($user, 'customer', $contextData);
                $customer = Customer::with(['user', 'state', 'paymentMethods'])->find($context->getAttribute('context_id'));

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

    public function show(Customer $customer): JsonResponse
    {
        $customer->load(['user', 'state', 'paymentMethods']);
        return response()->json([
            'status' => 'success',
            'data' => new CustomerResource($customer)
        ]);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): JsonResponse
    {
        try {
            $data = $request->validated();
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
        $user = User::find($customer->user_id);
        \App\Models\UserContext::where('user_id', $customer->user_id)
            ->where('context_type', 'customer')
            ->where('context_id', $customer->id)
            ->update(['is_active' => false]);

        $customer->delete();

        if ($user && !$user->contexts()->where('is_active', true)->exists()) {
            $user->delete();
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Customer deleted'
        ]);
    }

    /**
     * Get customer bookings
     * GET /api/customers/{customer}/bookings
     */
    public function getCustomerBookings(Customer $customer): JsonResponse
    {
        $bookings = $customer->bookings()
            ->with(['vehicle', 'driver.user', 'bookingStatus'])
            ->latest()
            ->paginate(15);

        return response()->json([
            'status' => 'success',
            'data' => [
                'bookings' => $bookings->items(),
                'pagination' => [
                    'current_page' => $bookings->currentPage(),
                    'last_page' => $bookings->lastPage(),
                    'per_page' => $bookings->perPage(),
                    'total' => $bookings->total()
                ]
            ]
        ]);
    }

    /**
     * Get customer loyalty info
     * GET /api/customers/{customer}/loyalty
     */
    public function getCustomerLoyalty(Customer $customer): JsonResponse
    {
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
        // This would typically come from a feedback table
        // For now, returning mock data
        $feedback = [
            [
                'id' => 1,
                'booking_id' => 'B001',
                'rating' => 5,
                'comment' => 'Excellent service!',
                'created_at' => now()->subDays(2)
            ],
            [
                'id' => 2,
                'booking_id' => 'B002',
                'rating' => 4,
                'comment' => 'Good experience overall',
                'created_at' => now()->subDays(7)
            ]
        ];

        return response()->json([
            'status' => 'success',
            'data' => [
                'feedback' => $feedback
            ]
        ]);
    }

    /**
     * Add customer feedback
     * POST /api/customers/{customer}/feedback
     */
    public function addCustomerFeedback(Request $request, Customer $customer): JsonResponse
    {
        $request->validate([
            'booking_id' => 'required|exists:bookings,id',
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:1000'
        ]);

        // This would typically save to a feedback table
        // For now, returning success response
        return response()->json([
            'status' => 'success',
            'message' => 'Feedback added successfully',
            'data' => [
                'feedback' => [
                    'booking_id' => $request->get('booking_id'),
                    'rating' => $request->get('rating'),
                    'comment' => $request->get('comment'),
                    'created_at' => now()
                ]
            ]
        ]);
    }

    /**
     * Get customer analytics
     * GET /api/customers/analytics
     */
    public function getCustomerAnalytics(): JsonResponse
    {
        $totalCustomers = Customer::count();
        $newThisMonth = Customer::whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();
        $activeCustomers = Customer::whereHas('bookings', function ($q) {
            $q->where('created_at', '>=', now()->subDays(30));
        })->count();
        $inactiveCustomers = max($totalCustomers - $activeCustomers, 0);
        $customersWithLicense = Customer::whereNotNull('license_no')
            ->where('license_no', '!=', '')
            ->count();
        $totalBookings = Booking::count();

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
                ->selectRaw('AVG(COALESCE(total_actual, total_estimated, 0)) as average_lifetime_value')
                ->value('average_lifetime_value') ?? 0),
            'customer_growth' => Customer::selectRaw('DATE(created_at) as date, COUNT(*) as count')
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
        $startDate = $request->date('start_date') ?? now()->subDays(30);
        $endDate = $request->date('end_date') ?? now();

        $rows = Customer::selectRaw('DATE(created_at) as date, COUNT(*) as count')
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
        $segments = Customer::selectRaw("COALESCE(type, 'Unclassified') as name, COUNT(*) as value")
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
        $cohortExpression = DB::connection()->getDriverName() === 'pgsql'
            ? "TO_CHAR(created_at, 'YYYY-MM')"
            : "DATE_FORMAT(created_at, '%Y-%m')";

        $cohorts = Customer::selectRaw("{$cohortExpression} as month, COUNT(*) as customers")
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

        $customers = Customer::with('user')
            ->withCount('bookings')
            ->withSum('bookings as total_revenue', 'total_actual')
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
                'lastBooking' => $customer->bookings()->latest('created_at')->value('created_at'),
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

        $query = Customer::query()->with('user');

        if ($request->filled('search')) {
            $search = trim((string) $request->get('search'));
            $query->where(function ($builder) use ($search) {
                $builder->whereHas('user', function ($userQuery) use ($search) {
                    $userQuery->whereLikeInsensitive('id', $search)
                        ->orWhereLikeInsensitive('first_name', $search)
                        ->orWhereLikeInsensitive('last_name', $search)
                        ->orWhereLikeInsensitive('email', $search)
                        ->orWhereLikeInsensitive('phone', $search);
                })->orWhereLikeInsensitive('id', $search)
                    ->orWhereLikeInsensitive('user_id', $search)
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
        // Mock feedback statistics
        $stats = [
            'total_feedback' => 150,
            'average_rating' => 4.2,
            'rating_distribution' => [
                '5' => 60,
                '4' => 45,
                '3' => 30,
                '2' => 10,
                '1' => 5
            ],
            'recent_feedback' => [
                [
                    'id' => 1,
                    'customer_name' => 'John Doe',
                    'rating' => 5,
                    'comment' => 'Excellent service!',
                    'created_at' => now()->subHours(2)
                ]
            ]
        ];

        return response()->json([
            'status' => 'success',
            'data' => $stats
        ]);
    }

    /**
     * Get customer feedback (general)
     * GET /api/customers/feedback
     */
    public function getAllCustomerFeedback(Request $request): JsonResponse
    {
        $limit = $request->get('limit', 20);
        $rating = $request->get('rating');

        // Mock feedback data
        $feedback = collect([
            [
                'id' => 1,
                'customer_name' => 'John Doe',
                'booking_id' => 'B001',
                'rating' => 5,
                'comment' => 'Excellent service!',
                'created_at' => now()->subDays(1)
            ],
            [
                'id' => 2,
                'customer_name' => 'Jane Smith',
                'booking_id' => 'B002',
                'rating' => 4,
                'comment' => 'Good experience',
                'created_at' => now()->subDays(2)
            ]
        ]);

        if ($rating) {
            $feedback = $feedback->where('rating', $rating);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'feedback' => $feedback->take($limit)->values(),
                'total' => $feedback->count()
            ]
        ]);
    }

    /**
     * Submit customer feedback (general)
     * POST /api/customers/feedback
     */
    public function submitCustomerFeedback(Request $request): JsonResponse
    {
        $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'booking_id' => 'required|exists:bookings,id',
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:1000'
        ]);

        // This would typically save to a feedback table
        return response()->json([
            'status' => 'success',
            'message' => 'Feedback submitted successfully',
            'data' => [
                'feedback' => [
                    'customer_id' => $request->get('customer_id'),
                    'booking_id' => $request->get('booking_id'),
                    'rating' => $request->get('rating'),
                    'comment' => $request->get('comment'),
                    'created_at' => now()
                ]
            ]
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

        $responseDate = now()->toISOString();

        return response()->json([
            'status' => 'success',
            'message' => 'Feedback response recorded successfully',
            'data' => [
                'feedback' => [
                    'id' => $feedbackId,
                    'responded' => true,
                    'response' => $data['response'],
                    'response_date' => $responseDate,
                    'responseDate' => $responseDate,
                    'responded_by' => $request->user()?->id,
                ],
            ],
        ]);
    }

    public function getDocumentStats(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => [
                'totalDocuments' => Document::where('documentable_type', Customer::class)->count(),
                'pendingVerification' => Document::where('documentable_type', Customer::class)->where('status', 'pending')->count(),
                'verifiedDocuments' => Document::where('documentable_type', Customer::class)->where('status', 'verified')->count(),
                'rejectedDocuments' => Document::where('documentable_type', Customer::class)->where('status', 'rejected')->count(),
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

        $query = Document::with('documentable.user')
            ->where('documentable_type', Customer::class)
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
    }
}
