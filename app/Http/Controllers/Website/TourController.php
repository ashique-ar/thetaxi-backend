<?php

namespace App\Http\Controllers\Website;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Tours\TourItineraryController;
use App\Mail\InquiryConfirmationMail;
use App\Models\Customer;
use App\Models\Booking\Booking;
use App\Models\Cart;
use App\Models\Inquiry;
use App\Models\Service\ServiceType;
use App\Models\Tours\TourItinerary;
use App\Models\User;
use App\Services\CustomerService;
use App\Services\CartService;
use App\Services\CurrencyService;
use App\Services\Tours\TourItineraryQuoteService;
use App\Services\MailDispatchService;
use App\Services\Sms\SmsAutomationService;
use App\Services\WebsiteSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TourController extends Controller
{
    public function index(): View
    {
        $enabled = ServiceType::publicContext()
            ->where('is_active', true)
            ->where('pricing_basis', 'vehicle_class')
            ->where('tour_booking_enabled', true)
            ->exists();

        abort_unless($enabled, 404);

        return view('tours.index');
    }

    public function confirmation(Request $request): View
    {
        $customer = $this->sessionCustomer();
        $bookingId = $request->session()->get('tour_checkout_booking_id');
        $inquiryId = $request->session()->get('tour_checkout_inquiry_id');
        abort_unless($customer && ($bookingId || $inquiryId), 404);
        $booking = $bookingId
            ? Booking::query()->whereKey($bookingId)->where('customer_id', $customer->id)->firstOrFail()
            : null;
        $inquiry = $inquiryId
            ? Inquiry::query()->whereKey($inquiryId)->where('customer_id', $customer->id)->firstOrFail()
            : null;
        if ($booking) {
            $booking->setAttribute('public_reference', $booking->booking_number ?: (string) $booking->id);
        }

        return view('tours.confirmation', compact('booking', 'inquiry'));
    }

    public function storeDraft(Request $request, CustomerService $customers, CurrencyService $currency): JsonResponse
    {
        $data = $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name' => 'nullable|string|max:100',
            'email' => 'required|email:rfc|max:255',
            'phone' => 'required|string|min:5|max:30',
            'service_type_id' => 'required|uuid',
            'service_package_id' => 'nullable|uuid',
            'vehicle_class_id' => 'required|uuid',
            'start_date' => 'required|date|after_or_equal:today',
            'duration_days' => 'required|integer|min:1|max:60',
            'adult_count' => 'required|integer|min:0|max:100',
            'child_count' => 'required|integer|min:0|max:100',
            'child_seat_count' => 'sometimes|integer|min:0|max:100',
            'luggage_count' => 'required|integer|min:0|max:100',
            'days' => 'required|array',
            'days.*.day_number' => 'required|integer|min:1|max:60|distinct:strict',
            'days.*.timezone' => 'required|timezone',
            'days.*.start_time' => 'sometimes|date_format:H:i',
            'days.*.start_destination_id' => 'required|uuid',
            'days.*.end_destination_id' => 'required|uuid',
            'days.*.overnight_destination_id' => 'nullable|uuid',
            'days.*.stops' => 'present|array|max:40',
            'days.*.stops.*.kind' => 'required|in:destination,activity',
            'days.*.stops.*.catalogue_id' => 'required|uuid',
        ]);
        if (count($data['days']) !== (int) $data['duration_days']) {
            throw ValidationException::withMessages(['days' => 'Add one route plan for every trip day.']);
        }
        if ((int) ($data['child_seat_count'] ?? 0) > (int) $data['child_count']) {
            throw ValidationException::withMessages(['child_seat_count' => 'Child seats cannot exceed the number of children travelling.']);
        }
        $request->merge(['child_seat_count' => (int) ($data['child_seat_count'] ?? 0)]);

        $request->merge([
            'currency' => $currency->getBookingBaseCurrency(),
            'custom_answers' => $request->input('custom_answers', []),
        ]);
        $controller = app(TourItineraryController::class);
        [$customer, $user, $stored] = DB::transaction(function () use ($request, $data, $customers, $controller): array {
            $customer = $this->sessionCustomer();
            $user = $customer ? $this->sessionUser() : null;
            if (!$customer) {
                $bookingSettings = app(WebsiteSettingsService::class)->getBookingSettings();
                $contact = [
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'] ?? '',
                    'customer_name' => trim($data['first_name'].' '.($data['last_name'] ?? '')),
                    'customer_email' => mb_strtolower(trim($data['email'])),
                    'customer_phone' => trim($data['phone']),
                ];
                if (Auth::check()) {
                    $user = Auth::user();
                    $customer = $customers->createCustomerForUser($user, $contact);
                } else {
                    abort_unless(filter_var($bookingSettings['guest_booking_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN), 403, 'Please sign in before booking this tour.');
                    $customer = $customers->createGuestCustomer($contact);
                    $user = User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($data['email']))])->firstOrFail();
                }
            } elseif (!$user || mb_strtolower(trim((string) $user->email)) !== mb_strtolower(trim($data['email']))) {
                throw ValidationException::withMessages(['email' => 'This tour checkout is already linked to another email. Start a new tour checkout to use different details.']);
            }

            $this->bindCustomerContext($request, $customer, 'customer', $user);
            $request->merge(['currency' => app(CurrencyService::class)->getSelectedCurrency()]);
            $existingId = $request->session()->get('tour_checkout_itinerary_id');
            if ($existingId) {
                $existing = \App\Models\Tours\TourItinerary::query()->where('user_id', $user->id)->findOrFail($existingId);
                if (in_array($existing->status, ['draft', 'quoted'], true)) {
                    if ($existing->review_inquiry_id) {
                        $existing->review_inquiry_id = null;
                        $existing->save();
                    }
                    $request->merge(['expected_revision' => $existing->revision]);
                    $stored = $controller->update($request, (string) $existing->id)->getData(true);
                } else {
                    $activeCart = Cart::query()
                        ->where('status', 'active')
                        ->where(function ($query) use ($user): void {
                            $query->where('session_id', session()->getId());
                            if (Auth::check()) {
                                $query->orWhere('user_id', $user->id);
                            }
                        })
                        ->latest('last_activity_at')
                        ->first();
                    $hasTourInCart = collect($activeCart?->items ?? [])->contains(
                        fn ($item): bool => is_array($item) && !empty($item['is_tour'])
                    );
                    if ($hasTourInCart) {
                        throw ValidationException::withMessages([
                            'tour' => ['Your accepted Tour is already in checkout. Finish checkout or remove it from your cart before planning another Tour.'],
                        ]);
                    }
                    $request->session()->forget(['tour_checkout_quote_id', 'tour_checkout_booking_id', 'tour_checkout_inquiry_id']);
                    $stored = $controller->store($request)->getData(true);
                }
            } else {
                $stored = $controller->store($request)->getData(true);
            }

            return [$customer, $user, $stored];
        }, 3);
        $request->session()->put('tour_checkout_user_id', (string) $user->id);
        $request->session()->put('tour_checkout_customer_id', (string) $customer->id);
        $request->session()->forget(['tour_checkout_booking_id', 'tour_checkout_inquiry_id']);
        $itinerary = $stored['data'];
        $request->merge(['expected_revision' => $itinerary['revision']]);
        $routeOptions = app(TourItineraryQuoteService::class)->options(
            \App\Models\Tours\TourItinerary::query()->where('user_id', $user->id)->findOrFail($itinerary['id'])
        );
        $request->session()->put('tour_checkout_itinerary_id', $itinerary['id']);

        return response()->json(['success' => true, 'data' => [
            'itinerary' => $itinerary,
            'route_options' => $routeOptions,
        ]], 201);
    }

    public function quote(Request $request, string $id): JsonResponse
    {
        $this->authorizeSessionItinerary($request, $id, 'customer');
        $data = $request->validate(['expected_revision' => 'required|integer|min:1']);

        return app(TourItineraryController::class)->quote($request, $id, app(TourItineraryQuoteService::class));
    }

    public function applyRoute(Request $request, string $id): JsonResponse
    {
        $this->authorizeSessionItinerary($request, $id, 'customer');

        return app(TourItineraryController::class)->applyRoute($request, $id);
    }

    public function acceptQuote(Request $request, string $id, string $quoteId): JsonResponse
    {
        $this->authorizeSessionItinerary($request, $id, 'customer');
        $request->validate(['expected_revision' => 'required|integer|min:1', 'accept_terms' => 'required|accepted']);

        return app(TourItineraryController::class)->acceptQuote($request, $id, $quoteId, app(TourItineraryQuoteService::class));
    }

    public function submitBooking(Request $request, string $id, CartService $cart): JsonResponse
    {
        $this->authorizeSessionItinerary($request, $id, 'customer');
        $data = $request->validate(['quote_id' => 'required|uuid', 'expected_revision' => 'required|integer|min:1']);
        $user = $this->sessionUser();
        $itinerary = TourItinerary::query()->where('user_id', $user?->id)->findOrFail($id);
        $quote = $itinerary->quotes()->whereKey($data['quote_id'])->where('status', 'accepted')->firstOrFail();
        abort_unless($itinerary->status === 'accepted'
            && (int) $itinerary->revision === (int) $data['expected_revision']
            && (int) $quote->itinerary_revision === (int) $itinerary->revision
            && app(CurrencyService::class)->isValidCurrency($quote->currency),
            409,
            'This accepted Tour quote is no longer valid. Please request a current quote.');
        abort_if($itinerary->booking_id, 409, 'This Tour is already in a booking.');

        app(CurrencyService::class)->setSelectedCurrency($quote->currency);
        $cartModel = $cart->getOrCreateCart();
        $cart->addItem($cartModel, ['is_tour' => true], 'tour-'.$itinerary->id);
        $request->session()->put([
            'tour_checkout_itinerary_id' => (string) $itinerary->id,
            'tour_checkout_quote_id' => (string) $quote->id,
        ]);
        $request->session()->forget('tour_checkout_inquiry_id');

        return response()->json(['success' => true, 'data' => ['checkout_url' => route('checkout')]]);
    }

    public function requestReview(Request $request, string $id): JsonResponse
    {
        $customer = $this->authorizeSessionItinerary($request, $id, 'customer');
        $user = $this->sessionUser();
        abort_unless($user, 403, 'Your Tour checkout session has expired. Start again to continue.');

        $inquiry = DB::transaction(function () use ($customer, $id, $user): Inquiry {
            $itinerary = \App\Models\Tours\TourItinerary::query()
                ->where('user_id', $user->id)
                ->whereKey($id)
                ->lockForUpdate()
                ->with(['serviceType', 'package', 'vehicleClass', 'days.stops.activity', 'days.stops.destination'])
                ->firstOrFail();
            if ($itinerary->review_inquiry_id) {
                return Inquiry::query()->findOrFail($itinerary->review_inquiry_id);
            }

            $days = $itinerary->days->map(fn ($day): array => [
                'day_number' => $day->day_number,
                'date' => $day->service_date,
                'timezone' => $day->timezone,
                'start' => $day->start_location_snapshot,
                'end' => $day->end_location_snapshot,
                'stops' => $day->stops->map(fn ($stop): array => [
                    'kind' => $stop->kind,
                    'name' => $stop->activity?->name ?? $stop->destination?->name,
                    'location' => $stop->location_snapshot,
                    'visit_duration_minutes' => $stop->visit_duration_minutes,
                ])->values()->all(),
            ])->values()->all();
            $quote = $itinerary->quotes()->whereIn('status', ['current', 'accepted'])->latest()->first();
            $name = trim((string) $user->first_name.' '.(string) $user->last_name);
            $inquiry = Inquiry::createWithUniqueNumber([
                'customer_id' => $customer->id,
                'name' => $name,
                'email' => $user->email,
                'phone' => $user->phone,
                'inquiry_type' => 'tour_review',
                'subject' => 'Tour route or vehicle availability review',
                'message' => 'The customer requested staff review of the saved Tour itinerary. No vehicle has been booked or reserved.',
                'status' => 'open',
                'source' => 'web',
                'payload' => [
                    'tour_itinerary_id' => (string) $itinerary->id,
                    'revision' => (int) $itinerary->revision,
                    'service_type' => $itinerary->serviceType?->name,
                    'package' => $itinerary->package?->name,
                    'vehicle_class' => $itinerary->vehicleClass?->name,
                    'start_date' => $itinerary->start_date?->toDateString(),
                    'duration_days' => (int) $itinerary->duration_days,
                    'party' => [
                        'adults' => (int) $itinerary->adult_count,
                        'children' => (int) $itinerary->child_count,
                        'child_seats' => (int) $itinerary->child_seat_count,
                        'luggage' => (int) $itinerary->luggage_count,
                    ],
                    'quote' => $quote ? [
                        'status' => $quote->status,
                        'currency' => $quote->currency,
                        'total_amount' => (float) $quote->total_amount,
                        'distance_km' => (float) $quote->distance_km,
                        'expires_at' => $quote->expires_at?->toIso8601String(),
                    ] : null,
                    'days' => $days,
                    'custom_answers' => $itinerary->custom_answers ?? [],
                    'custom_fields' => $itinerary->custom_fields_snapshot ?? [],
                ],
            ]);
            $itinerary->review_inquiry_id = $inquiry->id;
            $itinerary->save();

            return $inquiry;
        }, 3);

        if ($inquiry->wasRecentlyCreated) {
            try {
                app(SmsAutomationService::class)->queueWebsiteInquiryReceived($inquiry);
                app(MailDispatchService::class)->sendToCustomer(
                    (string) $inquiry->email,
                    new InquiryConfirmationMail($inquiry, 'Tour review request', 'We have received your Tour plan and will review the route or vehicle availability.')
                );
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        $request->session()->put('tour_checkout_inquiry_id', (string) $inquiry->id);
        $request->session()->forget('tour_checkout_booking_id');

        return response()->json([
            'success' => true,
            'data' => [
                'inquiry_number' => $inquiry->inquiry_number,
                'confirmation_url' => route('tours.confirmation'),
            ],
        ], 201);
    }

    private function authorizeSessionItinerary(Request $request, string $id, string $context): Customer
    {
        abort_unless((string) $request->session()->get('tour_checkout_itinerary_id') === $id, 404);
        $customer = $this->sessionCustomer();
        abort_unless($customer, 403, 'Your Tour checkout session has expired. Start again to continue.');
        $this->bindCustomerContext($request, $customer, $context);

        return $customer;
    }

    private function sessionCustomer(): ?Customer
    {
        $id = session('tour_checkout_customer_id');

        return $id ? Customer::query()->with('user')->find($id) : null;
    }

    private function sessionUser(): ?User
    {
        $id = session('tour_checkout_user_id');

        return $id ? User::query()->find($id) : null;
    }

    private function bindCustomerContext(Request $request, Customer $customer, string $bookingContext, ?User $asUser = null): void
    {
        $user = $asUser ?? $this->sessionUser() ?? $customer->user;
        abort_unless($user instanceof User, 403, 'A customer profile is required to continue.');
        $context = $user->contexts()
            ->where('context_type', 'customer')
            ->where('context_id', $customer->id)
            ->where('is_active', true)
            ->firstOrFail();
        $ownsCustomer = (string) $customer->user_id === (string) $user->id
            || DB::table('customer_user_aliases')->where('user_id', $user->id)->where('customer_id', $customer->id)->exists();
        abort_unless($ownsCustomer, 403, 'Your account is not connected to this customer profile.');

        $request->setUserResolver(fn () => $user);
        $request->headers->set('X-Active-Context-Type', 'customer');
        $request->headers->set('X-Active-Context-Id', (string) $context->id);
        $request->headers->set('X-Active-Portal-Profile', 'customer');
        $request->route()->setParameter('tour_pricing_context', 'public');
        $request->route()->setParameter('tour_booking_context', $bookingContext);
    }
}
