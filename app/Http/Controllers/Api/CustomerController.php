<?php
// app/Http/Controllers/Api/CustomerController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Http\Requests\Customer\CreateCustomerRequest;
use App\Http\Requests\Customer\UpdateCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Services\UserContextService;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $q = Customer::with(['user', 'state']);

        if ($request->filled('search')) {
            $search = $request->get('search');
            $q->where(function ($builder) use ($search) {
                $builder->whereHas('user', function ($query) use ($search) {
                    $query->whereLikeInsensitive('first_name', $search)
                        ->orWhereLikeInsensitive('last_name', $search)
                        ->orWhereLikeInsensitive('email', $search)
                        ->orWhereLikeInsensitive('phone', $search);
                })->orWhereLikeInsensitive('code', $search)
                    ->orWhereLikeInsensitive('nic', $search)
                    ->orWhereLikeInsensitive('passport_number', $search);
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

        $sort = in_array($request->get('sort'), ['created_at', 'updated_at', 'code'], true)
            ? $request->get('sort')
            : 'created_at';
        $direction = $request->get('direction') === 'asc' ? 'asc' : 'desc';

        return CustomerResource::collection(
            $q->orderBy($sort, $direction)->paginate($request->per_page ?? 15)
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
                    $userQuery->whereLikeInsensitive('first_name', $search)
                        ->orWhereLikeInsensitive('last_name', $search)
                        ->orWhereLikeInsensitive('email', $search)
                        ->orWhereLikeInsensitive('phone', $search);
                })->orWhereLikeInsensitive('code', $search);
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
            ->whereHas('user', fn ($query) => $query->where('email', $email))
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
            $existingUser = User::where('email', $data['email'])->lockForUpdate()->first();
            
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
                $customer = Customer::with(['user', 'state'])->find($context->getAttribute('context_id'));

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
                $customer = Customer::with(['user', 'state'])->find($context->getAttribute('context_id'));

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
        $customer->load(['user', 'state']);
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

            $customerData = array_diff_key($data, $userData);

            if (!empty($userData)) {
                $customer->user->update($userData);
            }

            $customer->update($customerData);

            $customer->load(['user', 'state']);

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
     * Add loyalty points
     * POST /api/customers/{customer}/loyalty/points
     */
    public function addLoyaltyPoints(Request $request, Customer $customer): JsonResponse
    {
        $request->validate([
            'points' => 'required|integer|min:1',
            'reason' => 'required|string|max:255'
        ]);

        $user = $customer->user;
        $user->addPoint($request->get('points'));

        return response()->json([
            'status' => 'success',
            'message' => 'Points added successfully',
            'data' => [
                'points_added' => $request->get('points'),
                'total_points' => $user->fresh()->getPoints()
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

        $analytics = [
            'total_customers' => $totalCustomers,
            'new_customers_this_month' => $newThisMonth,
            'active_customers' => $activeCustomers,
            'totalCustomers' => $totalCustomers,
            'newCustomersThisMonth' => $newThisMonth,
            'activeCustomers' => $activeCustomers,
            'averageLifetimeValue' => (float) DB::table('bookings')->avg(DB::raw('COALESCE(total_actual, total_estimated, 0)')),
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
        $cohorts = Customer::selectRaw("DATE_FORMAT(created_at, '%Y-%m') as cohort, COUNT(*) as customers")
            ->where('created_at', '>=', now()->subMonths(12))
            ->groupBy('cohort')
            ->orderBy('cohort')
            ->get();

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
    public function exportCustomers(Request $request): JsonResponse
    {
        $format = $request->get('format', 'csv');

        // This would typically generate a file export
        // For now, returning success response
        return response()->json([
            'status' => 'success',
            'message' => 'Export initiated',
            'data' => [
                'format' => $format,
                'estimated_completion' => now()->addMinutes(5)
            ]
        ]);
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
}
