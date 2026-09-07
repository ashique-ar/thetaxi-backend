<?php

namespace App\Http\Controllers\Api\Booking\Traits;

use App\Http\Resources\Booking\BookingFlowResource;
use App\Models\Booking\Booking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

trait BookingSubmissionTrait
{
    public function submitBookingForApproval(Request $request): JsonResponse
    {
        $params = $this->bookingFlowService->normalizeDynamicCalculationParams($request->all());
        $params = $this->bookingFlowService->normalizeCorporateEmployeeReferences($params);
        $requirements = $this->bookingFlowService->getDynamicCalculationRequirements($params);
        $usesDropoffTime = (bool) ($requirements['uses_dropoff_time'] ?? true);
        $pickupRequired = (bool) ($requirements['pickup_location_required'] ?? true);
        $dropoffRequired = (bool) ($requirements['dropoff_location_required'] ?? true);

        $rules = [
            'customer_id' => 'nullable|string',
            'is_corporate_booking' => 'sometimes|boolean',
            'corporate_account_id' => 'nullable|uuid|exists:corporates,id',
            'employee_id' => $this->bookingEmployeeIdRules(),
            'corporate_employee_id' => 'nullable|uuid',
            'corporate_department_id' => 'nullable|uuid|exists:corporate_departments,id',
            'corporate_division_id' => 'nullable|uuid|exists:corporate_divisions,id',
            'cost_center' => 'nullable|string|max:255',
            'project_code' => 'nullable|string|max:255',
            'corporate_contact' => 'nullable|array',
            'corporate_contact.name' => 'nullable|string|max:255',
            'corporate_contact.email' => 'nullable|email|max:255',
            'corporate_contact.phone' => 'nullable|string|max:50',
            'service_type' => 'required_without:booking_items|string',
            'vehicle_group_id' => 'sometimes|string',
            'vehicle_groups' => 'sometimes|array',
            'vehicle_groups.*.id' => 'required|string',
            'vehicle_groups.*.quantity' => 'required|integer|min:1',
            'vehicles' => 'sometimes|array',
            'vehicles.*.id' => 'required|string',
            'vehicles.*.group_id' => 'required|string',
            'drivers' => 'sometimes|array',
            'drivers.*.id' => 'required|string',
            'vehicle_driver_assignments' => 'sometimes|array',
            'vehicle_driver_assignments.*.vehicle_id' => 'required|string',
            'vehicle_driver_assignments.*.driver_id' => 'nullable|string',
            'from_date' => 'required_without:booking_items|date',
            'from_time' => 'required_without:booking_items|string',
            'pickup_location' => $pickupRequired ? 'required_without:booking_items|array' : 'nullable|array',
            'pickup_location.latitude' => $pickupRequired ? 'required_without:booking_items|numeric' : 'required_with:pickup_location|numeric',
            'pickup_location.longitude' => $pickupRequired ? 'required_without:booking_items|numeric' : 'required_with:pickup_location|numeric',
            'dropoff_location' => $dropoffRequired ? 'required_without:booking_items|array' : 'nullable|array',
            'dropoff_location.latitude' => $dropoffRequired ? 'required_without:booking_items|numeric' : 'required_with:dropoff_location|numeric',
            'dropoff_location.longitude' => $dropoffRequired ? 'required_without:booking_items|numeric' : 'required_with:dropoff_location|numeric',
            'selected_addons' => 'sometimes|array',
            'selected_addons.*.id' => 'required|string',
            'selected_addons.*.quantity' => 'sometimes|integer|min:1',
            'selected_addons.*.custom_price' => 'sometimes',
            'selected_addons.*.group_id' => 'sometimes|string',
            'variable_customizations' => 'sometimes|array',
            'variable_customizations.*.id' => 'sometimes|string',
            'variable_customizations.*.variable_name' => 'required|string',
            'variable_customizations.*.variable_type' => 'sometimes|string|in:slab_rate,common_rate,addon_rate,fixed_value',
            'variable_customizations.*.original_value' => 'sometimes|numeric',
            'variable_customizations.*.custom_value' => 'required|numeric',
            'variable_customizations.*.rate_type' => 'sometimes|string|in:per_day,per_hour,flat_rate,per_package',
            'variable_customizations.*.billing_type' => 'sometimes|string|in:per_day,per_hour,per_package',
            'variable_customizations.*.model_type' => 'sometimes|string',
            'variable_customizations.*.model_id' => 'sometimes|string',
            'variable_customizations.*.context' => 'sometimes|string|in:base_pricing,addon_pricing',
            'variable_customizations.*.reason' => 'sometimes|string',
            'variable_customizations.*.group_id' => 'sometimes|string',
            'has_variable_customizations' => 'sometimes|boolean',
            'session_id' => 'sometimes|string',
            'is_preview_calculation' => 'sometimes|boolean',
            'currency' => 'sometimes|string|size:3',
            'base_currency' => 'sometimes|string|size:3',
            'booking_id' => 'nullable|string',
            'preserve_custom_pricing' => 'sometimes|boolean',
            'preserve_custom_addon_prices' => 'sometimes|boolean',
            'force_recalculation' => 'sometimes|boolean',
            'applied_discounts' => 'sometimes|array',
            'payment_collection_method' => [
                'sometimes',
                'string',
                Rule::in(['cash_to_driver', 'online', 'monthly_invoice', 'bank_transfer', 'card', 'advance_then_balance', 'deposit_then_balance', 'pay_at_end', 'account_credit', 'complimentary', 'other']),
                function ($attribute, $value, $fail) use ($params) {
                    $isCorporate = filter_var($params['is_corporate_booking'] ?? false, FILTER_VALIDATE_BOOL);
                    $responsibility = $params['payment_responsibility'] ?? null;
                    if ($value === 'monthly_invoice' && !$isCorporate && $responsibility !== 'company') {
                        $fail('Monthly invoice payment is only available for corporate or company-billed bookings.');
                    }
                },
            ],
            'payment_responsibility' => ['sometimes', 'string', Rule::in(['customer', 'corporate', 'company'])],
            'send_confirmation_sms' => ['sometimes', 'boolean'],
        ];

        if ($usesDropoffTime) {
            $rules['to_date'] = 'required_without:booking_items|date|after_or_equal:from_date';
            $rules['to_time'] = 'required_without:booking_items|string';
        } else {
            $rules['to_date'] = 'nullable|date|after_or_equal:from_date';
            $rules['to_time'] = 'nullable|string';
            $params['to_date'] = $params['to_date'] ?? ($params['from_date'] ?? null);
            $params['to_time'] = $params['to_time'] ?? ($params['from_time'] ?? null);
        }

        Validator::make($params, $rules)->validate();

        $booking = $this->bookingFlowService->submitBookingForApproval($params);

        return response()->json([
            'status' => 'success',
            'data' => new BookingFlowResource($booking),
            'requires_approval' => $booking->requires_approval,
            'message' => $booking->requires_approval
                ? 'Booking submitted for approval successfully'
                : 'Booking confirmed successfully'
        ], 201);
    }

    public function confirmBooking(Request $request): JsonResponse
    {
        $params = $this->bookingFlowService->normalizeDynamicCalculationParams($request->all());
        $params = $this->bookingFlowService->normalizeCorporateEmployeeReferences($params);
        $requirements = $this->bookingFlowService->getDynamicCalculationRequirements($params);
        $usesDropoffTime = (bool) ($requirements['uses_dropoff_time'] ?? true);
        $pickupRequired = (bool) ($requirements['pickup_location_required'] ?? true);
        $dropoffRequired = (bool) ($requirements['dropoff_location_required'] ?? true);

        $rules = [
            'customer_id' => 'nullable|string',
            'is_corporate_booking' => 'sometimes|boolean',
            'corporate_account_id' => 'nullable|uuid|exists:corporates,id',
            'employee_id' => $this->bookingEmployeeIdRules(),
            'corporate_employee_id' => 'nullable|uuid',
            'corporate_department_id' => 'nullable|uuid|exists:corporate_departments,id',
            'corporate_division_id' => 'nullable|uuid|exists:corporate_divisions,id',
            'cost_center' => 'nullable|string|max:255',
            'project_code' => 'nullable|string|max:255',
            'corporate_contact' => 'nullable|array',
            'corporate_contact.name' => 'nullable|string|max:255',
            'corporate_contact.email' => 'nullable|email|max:255',
            'corporate_contact.phone' => 'nullable|string|max:50',
            'service_type' => 'required_without:booking_items|string',
            'vehicle_group_id' => 'sometimes|string',
            'vehicle_groups' => 'sometimes|array',
            'vehicle_groups.*.id' => 'required|string',
            'vehicle_groups.*.quantity' => 'required|integer|min:1',
            'vehicles' => 'sometimes|array',
            'vehicles.*.id' => 'required|string',
            'vehicles.*.group_id' => 'required|string',
            'drivers' => 'sometimes|array',
            'drivers.*.id' => 'required|string',
            'vehicle_driver_assignments' => 'sometimes|array',
            'vehicle_driver_assignments.*.vehicle_id' => 'required|string',
            'vehicle_driver_assignments.*.driver_id' => 'nullable|string',
            'from_date' => 'required_without:booking_items|date',
            'from_time' => 'required_without:booking_items|string',
            'pickup_location' => $pickupRequired ? 'required_without:booking_items|array' : 'nullable|array',
            'pickup_location.latitude' => $pickupRequired ? 'required_without:booking_items|numeric' : 'required_with:pickup_location|numeric',
            'pickup_location.longitude' => $pickupRequired ? 'required_without:booking_items|numeric' : 'required_with:pickup_location|numeric',
            'dropoff_location' => $dropoffRequired ? 'required_without:booking_items|array' : 'nullable|array',
            'dropoff_location.latitude' => $dropoffRequired ? 'required_without:booking_items|numeric' : 'required_with:dropoff_location|numeric',
            'dropoff_location.longitude' => $dropoffRequired ? 'required_without:booking_items|numeric' : 'required_with:dropoff_location|numeric',
            'selected_addons' => 'sometimes|array',
            'selected_addons.*.id' => 'required|string',
            'selected_addons.*.quantity' => 'sometimes|integer|min:1',
            'selected_addons.*.custom_price' => 'sometimes',
            'selected_addons.*.group_id' => 'sometimes|string',
            'variable_customizations' => 'sometimes|array',
            'variable_customizations.*.id' => 'sometimes|string',
            'variable_customizations.*.variable_name' => 'required|string',
            'variable_customizations.*.variable_type' => 'sometimes|string|in:slab_rate,common_rate,addon_rate,fixed_value',
            'variable_customizations.*.original_value' => 'sometimes|numeric',
            'variable_customizations.*.custom_value' => 'required|numeric',
            'variable_customizations.*.rate_type' => 'sometimes|string|in:per_day,per_hour,flat_rate,per_package',
            'variable_customizations.*.billing_type' => 'sometimes|string|in:per_day,per_hour,per_package',
            'variable_customizations.*.model_type' => 'sometimes|string',
            'variable_customizations.*.model_id' => 'sometimes|string',
            'variable_customizations.*.context' => 'sometimes|string|in:base_pricing,addon_pricing',
            'variable_customizations.*.reason' => 'sometimes|string',
            'variable_customizations.*.group_id' => 'sometimes|string',
            'has_variable_customizations' => 'sometimes|boolean',
            'session_id' => 'sometimes|string',
            'is_preview_calculation' => 'sometimes|boolean',
            'currency' => 'sometimes|string|size:3',
            'base_currency' => 'sometimes|string|size:3',
            'booking_id' => 'nullable|string',
            'preserve_custom_pricing' => 'sometimes|boolean',
            'preserve_custom_addon_prices' => 'sometimes|boolean',
            'force_recalculation' => 'sometimes|boolean',
            'applied_discounts' => 'sometimes|array',
            'payment_collection_method' => [
                'sometimes',
                'string',
                Rule::in(['cash_to_driver', 'online', 'monthly_invoice', 'bank_transfer', 'card', 'advance_then_balance', 'deposit_then_balance', 'pay_at_end', 'account_credit', 'complimentary', 'other']),
                function ($attribute, $value, $fail) use ($params) {
                    $isCorporate = filter_var($params['is_corporate_booking'] ?? false, FILTER_VALIDATE_BOOL);
                    $responsibility = $params['payment_responsibility'] ?? null;
                    if ($value === 'monthly_invoice' && !$isCorporate && $responsibility !== 'company') {
                        $fail('Monthly invoice payment is only available for corporate or company-billed bookings.');
                    }
                },
            ],
            'payment_responsibility' => ['sometimes', 'string', Rule::in(['customer', 'corporate', 'company'])],
            'send_confirmation_sms' => ['sometimes', 'boolean'],
            'send_confirmation_emails' => ['sometimes', 'boolean'],
        ];

        if ($usesDropoffTime) {
            $rules['to_date'] = 'required_without:booking_items|date|after_or_equal:from_date';
            $rules['to_time'] = 'required_without:booking_items|string';
        } else {
            $rules['to_date'] = 'nullable|date|after_or_equal:from_date';
            $rules['to_time'] = 'nullable|string';
            $params['to_date'] = $params['to_date'] ?? ($params['from_date'] ?? null);
            $params['to_time'] = $params['to_time'] ?? ($params['from_time'] ?? null);
        }

        Validator::make($params, $rules)->validate();

        // This endpoint is only ever hit by staff confirming a booking directly in the
        // admin portal (the corporate self-service portal and the public website create
        // bookings through their own separate flows). The customer should still get their
        // confirmation email only when the operator explicitly opts in. Staff already know
        // they made the booking, so always skip the internal copy (info@ / mail.customer_cc /
        // mail.bcc_all) that normally rides along.
        $params['notify_internal_team'] = false;

        $booking = $this->bookingFlowService->confirmBooking($params);

        return response()->json([
            'status' => 'success',
            'data' => new BookingFlowResource($booking),
            'message' => 'Booking confirmed successfully'
        ], 201);
    }

    public function updateBooking(Request $request, string $bookingId): JsonResponse
    {
        try {
            $params = $this->bookingFlowService->normalizeDynamicCalculationParams($request->all());
            $params = $this->bookingFlowService->normalizeCorporateEmployeeReferences($params);
            $bookingForEdit = Booking::findOrFail($bookingId);
            $structureEditable = in_array(
                (string) $bookingForEdit->status,
                ['draft', 'pending', 'pending_approval', 'approved', 'confirmed'],
                true
            );
            if (array_key_exists('booking_items', $params) && !$structureEditable) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Trip structure is locked after allocation. Use lifecycle actions for operational changes.',
                ], 409);
            }
            $requirements = $this->bookingFlowService->getDynamicCalculationRequirements($params);
            $usesDropoffTime = (bool) ($requirements['uses_dropoff_time'] ?? true);

            $rules = [
                'customer_id' => 'nullable|string',
                'is_corporate_booking' => 'sometimes|boolean',
                'corporate_account_id' => 'nullable|uuid|exists:corporates,id',
                'employee_id' => $this->bookingEmployeeIdRules(),
                'corporate_employee_id' => 'nullable|uuid',
                'corporate_department_id' => 'nullable|uuid|exists:corporate_departments,id',
                'corporate_division_id' => 'nullable|uuid|exists:corporate_divisions,id',
                'cost_center' => 'nullable|string|max:255',
                'project_code' => 'nullable|string|max:255',
                'corporate_contact' => 'nullable|array',
                'corporate_contact.name' => 'nullable|string|max:255',
                'corporate_contact.email' => 'nullable|email|max:255',
                'corporate_contact.phone' => 'nullable|string|max:50',
                'service_type' => 'sometimes|string',
                'service_type_id' => 'sometimes|string',
                'vehicle_group_id' => 'sometimes|string',
                'vehicle_groups' => 'sometimes|array',
                'vehicle_groups.*.id' => 'required|string',
                'vehicle_groups.*.quantity' => 'required|integer|min:1',
                'vehicles' => 'sometimes|array',
                'vehicles.*.id' => 'required|string',
                'vehicles.*.group_id' => 'required|string',
                'drivers' => 'sometimes|array',
                'drivers.*.id' => 'required|string',
                'vehicle_driver_assignments' => 'sometimes|array',
                'vehicle_driver_assignments.*.vehicle_id' => 'required|string',
                'vehicle_driver_assignments.*.driver_id' => 'nullable|string',
                'booking_items' => 'sometimes|array',
                'from_date' => 'sometimes|nullable|date',
                'from_time' => 'sometimes|nullable|string',
                'to_date' => 'sometimes|nullable|date|after_or_equal:from_date',
                'to_time' => 'sometimes|nullable|string',
                'pickup_location' => 'sometimes|array',
                'pickup_location.latitude' => 'required_with:pickup_location|numeric',
                'pickup_location.longitude' => 'required_with:pickup_location|numeric',
                'dropoff_location' => 'sometimes|array',
                'dropoff_location.latitude' => 'required_with:dropoff_location|numeric',
                'dropoff_location.longitude' => 'required_with:dropoff_location|numeric',
                'additional_pickup_locations' => 'sometimes|array',
                'additional_dropoff_locations' => 'sometimes|array',
                'ordered_additional_stops' => 'sometimes|array',
                'selected_addons' => 'sometimes|array',
                'selected_addons.*.id' => 'required|string',
                'selected_addons.*.quantity' => 'sometimes|integer|min:1',
                'selected_addons.*.custom_price' => 'sometimes',
                'selected_addons.*.group_id' => 'sometimes|string',
                'variable_customizations' => 'sometimes|array',
                'variable_customizations.*.id' => 'sometimes|string',
                'variable_customizations.*.variable_name' => 'required|string',
                'variable_customizations.*.variable_type' => 'sometimes|string|in:slab_rate,common_rate,addon_rate,fixed_value',
                'variable_customizations.*.original_value' => 'sometimes|numeric',
                'variable_customizations.*.custom_value' => 'required|numeric',
                'variable_customizations.*.rate_type' => 'sometimes|string|in:per_day,per_hour,flat_rate,per_package',
                'variable_customizations.*.billing_type' => 'sometimes|string|in:per_day,per_hour,per_package',
                'variable_customizations.*.model_type' => 'sometimes|string',
                'variable_customizations.*.model_id' => 'sometimes|string',
                'variable_customizations.*.context' => 'sometimes|string|in:base_pricing,addon_pricing',
                'variable_customizations.*.reason' => 'sometimes|string',
                'variable_customizations.*.group_id' => 'sometimes|string',
                'has_variable_customizations' => 'sometimes|boolean',
                'session_id' => 'sometimes|string',
                'is_preview_calculation' => 'sometimes|boolean',
                'currency' => 'sometimes|string|size:3',
                'base_currency' => 'sometimes|string|size:3',
                'booking_id' => 'nullable|string',
                'preserve_custom_pricing' => 'sometimes|boolean',
                'preserve_custom_addon_prices' => 'sometimes|boolean',
                'force_recalculation' => 'sometimes|boolean',
                'applied_discounts' => 'sometimes|array',
                'payment_collection_method' => [
                    'sometimes',
                    'string',
                    Rule::in(['cash_to_driver', 'online', 'monthly_invoice', 'bank_transfer', 'card', 'advance_then_balance', 'deposit_then_balance', 'pay_at_end', 'account_credit', 'complimentary', 'other']),
                    function ($attribute, $value, $fail) use ($params) {
                        $isCorporate = filter_var($params['is_corporate_booking'] ?? false, FILTER_VALIDATE_BOOL);
                        $responsibility = $params['payment_responsibility'] ?? null;
                        if ($value === 'monthly_invoice' && !$isCorporate && $responsibility !== 'company') {
                            $fail('Monthly invoice payment is only available for corporate or company-billed bookings.');
                        }
                    },
                ],
                'payment_responsibility' => ['sometimes', 'string', Rule::in(['customer', 'corporate', 'company'])],
            ];

            if (!$usesDropoffTime) {
                if (array_key_exists('from_date', $params) && !empty($params['from_date'])) {
                    $params['to_date'] = $params['from_date'];
                } else {
                    unset($params['to_date']);
                }

                if (array_key_exists('from_time', $params) && !empty($params['from_time'])) {
                    $params['to_time'] = $params['from_time'];
                } else {
                    unset($params['to_time']);
                }
            }

            Validator::make($params, $rules)->validate();

            $booking = $this->bookingFlowService->updateBooking($bookingId, $params, []);

            return response()->json([
                'status' => 'success',
                'data' => new BookingFlowResource($booking->load([
                    'customer',
                    'vehicle',
                    'driver',
                    'serviceType',
                    'vehicleGroup'
                ])),
                'requires_re_approval' => $booking->status === 'pending_approval',
                'message' => $booking->status === 'pending_approval'
                    ? 'Booking updated and submitted for re-approval'
                    : 'Booking updated successfully',
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Booking not found',
            ], 404);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Error updating booking: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update booking',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getBookingForEdit(string $bookingId): JsonResponse
    {
        try {
            $booking = Booking::with([
                'customer',
                'vehicle',
                'driver',
                'serviceType',
                'vehicleGroup',
                'bookingAddons.addon',
                'approvals',
                'bookingItems',
            ])->findOrFail($bookingId);

            if (!Gate::allows('update', $booking)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthorized to edit this booking'
                ], 403);
            }

            $editData = $this->bookingFlowService->getComprehensiveBookingData($bookingId);
            $permissions = [
                'can_edit_basic' => Gate::allows('update', $booking),
                'can_edit_structure' => Gate::allows('update', $booking) && in_array(
                    (string) $booking->status,
                    ['draft', 'pending', 'pending_approval', 'approved', 'confirmed'],
                    true
                ),
                'can_edit_pricing' => Gate::allows('update', $booking),
                'can_edit_approval' => Gate::allows('update', $booking),
                'can_cancel' => Gate::allows('delete', $booking),
                'can_override' => Gate::allows('update', $booking),
            ];
            $editData['permissions'] = $permissions;

            return response()->json([
                'status' => 'success',
                'data' => $editData,
                // Retained at the top level for existing API consumers.
                'permissions' => $permissions,
                'message' => 'Booking data retrieved for editing'
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Booking not found'
            ], 404);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Error getting booking for edit: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve booking for editing',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function generateBookingConfirmation(Request $request, string $bookingId): JsonResponse
    {
        $request->validate([
            'format' => 'required|in:pdf,email',
        ]);

        try {
            $confirmation = $this->bookingFlowService->generateBookingConfirmation(
                $bookingId,
                $request->input('format', 'pdf')
            );

            return response()->json([
                'status' => 'success',
                'data' => $confirmation,
                'message' => 'Booking confirmation generated successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error generating booking confirmation: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to generate booking confirmation',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function saveBookingDraft(Request $request): JsonResponse
    {
        try {
            $params = $this->bookingFlowService->normalizeCorporateEmployeeReferences($request->all());
            $draft = $this->bookingFlowService->saveBookingDraft($params);

            return response()->json([
                'status' => 'success',
                'data' => new BookingFlowResource($draft),
                'message' => 'Booking draft saved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Draft saving failed: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to save booking draft',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function requestBookingQuotation(Request $request): JsonResponse
    {
        $request->validate([
            'customer_id' => 'required|string|exists:customers,id',
            'booking_items' => 'required|array|min:1',
        ]);

        try {
            $params = $this->bookingFlowService->normalizeCorporateEmployeeReferences($request->all());
            $booking = $this->bookingFlowService->requestBookingQuotation($params);

            return response()->json([
                'status' => 'success',
                'data' => new BookingFlowResource($booking),
                'message' => 'Booking quotation requested successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Booking quotation request failed: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to request booking quotation',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function loadBookingDraft(string $draftId): JsonResponse
    {
        try {
            $draft = $this->bookingFlowService->loadBookingDraft($draftId);

            return response()->json([
                'success' => true,
                'data' => new BookingFlowResource($draft)
            ]);
        } catch (\Exception $e) {
            Log::error('Loading draft failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'error' => 'Failed to load booking draft',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    private function bookingEmployeeIdRules(): array
    {
        return [
            'nullable',
            'uuid',
            function ($attribute, $value, $fail) {
                if (!$this->bookingFlowService->isValidBookingEmployeeId($value)) {
                    $fail('The selected employee id is invalid.');
                }
            },
        ];
    }

    public function getBookingsList(Request $request): JsonResponse
    {
        $request->validate([
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
            'search' => 'nullable|string|max:255',
            'status' => 'nullable',
            'status.*' => 'string|in:draft,quotation_requested,pending_approval,approved,confirmed,allocated,in_progress,completed,cancelled',
            'item_status' => 'nullable',
            'item_status.*' => 'string|in:pending,confirmed,cancelled,completed',
            'service_type' => 'nullable|string',
            'customer_id' => 'nullable|uuid|exists:customers,id',
            'vehicle_group_id' => 'nullable|uuid|exists:vehicle_groups,id',
            'vehicle_id' => 'nullable|uuid|exists:vehicles,id',
            'driver_id' => 'nullable|uuid|exists:drivers,id',
            'assignment_status' => 'nullable',
            'assignment_status.*' => 'string|in:active,pending_approval,approved,completed,cancelled',
            'assignment_type' => 'nullable',
            'assignment_type.*' => 'string|in:primary,concurrent,override',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'sort_by' => 'nullable|string|in:created_at,booking_date,from_date,to_date,total_amount,total_actual,status,booking_status,priority',
            'sort_direction' => 'nullable|string|in:asc,desc',
            'sort_order' => 'nullable|string|in:asc,desc',
            'requires_approval' => 'nullable|boolean',
            'has_overrides' => 'nullable|boolean',
            'priority' => 'nullable|string|in:normal,high,urgent',
            'assigned_to' => 'nullable|uuid|exists:users,id',
            'created_by' => 'nullable|uuid|exists:users,id',
            'item_type' => 'nullable|string|max:100',
            'is_self_driven' => 'nullable|boolean',
            'booking_source' => 'nullable|string|in:public,corporate,internal',
            'corporate_id' => 'nullable|uuid',
            'dashboard_scope' => 'nullable|string|in:standard',
            'operations_queue' => 'nullable|string|in:needs_approval,needs_assignment,ready_to_dispatch,active,return_due,qc_pending,repair_pending,ready_to_complete,payment_pending,payment_attention',
            'queue' => 'nullable|string|in:needs_approval,needs_assignment,ready_to_dispatch,active,return_due,qc_pending,repair_pending,ready_to_complete,payment_pending,payment_attention',
        ]);

        try {
            $bookings = $this->bookingFlowService->getFilteredBookings($request->all());

            return response()->json([
                'status' => 'success',
                'data' => $bookings,
                'message' => 'Bookings retrieved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting bookings list: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve bookings',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getReturnInspectionOptions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'record_type' => 'required|string|in:customer,vehicle,driver',
            'search' => 'nullable|string|max:100',
            'selected_id' => 'nullable|uuid',
            'per_page' => 'nullable|integer|min:1|max:50',
        ]);

        $recordType = $data['record_type'];
        $filters = [
            'per_page' => min(50, (int) ($data['per_page'] ?? 25)),
            'search' => trim((string) ($data['search'] ?? '')),
            'sort_by' => 'to_date',
            'sort_direction' => 'desc',
        ];
        if (! empty($data['selected_id'])) {
            $filters[$recordType . '_id'] = $data['selected_id'];
            unset($filters['search']);
        }

        $rows = collect($this->bookingFlowService->getFilteredBookings($filters)['bookings'] ?? []);
        $options = $rows->map(function (array $row) use ($recordType): ?array {
            $record = $row[$recordType] ?? null;
            if (! is_array($record) || empty($record['id'])) {
                return null;
            }

            $label = match ($recordType) {
                'customer' => $record['name'] ?? null,
                'vehicle' => $record['name'] ?? $record['title'] ?? $record['registration_number'] ?? $record['license_plate'] ?? null,
                'driver' => $record['name'] ?? null,
            };
            if (! filled($label)) {
                return null;
            }

            return [
                'value' => (string) $record['id'],
                'label' => (string) $label,
                'metadata' => [
                    'booking' => $row['booking_number'] ?? null,
                    'reference' => $record['registration_number'] ?? $record['license_plate'] ?? $record['code'] ?? null,
                ],
                'status' => 'active_booking_reference',
            ];
        })->filter()->unique('value')->values()->all();

        return response()->json(['status' => 'success', 'data' => $options]);
    }

    public function getBookingDetails(string $bookingId): JsonResponse
    {
        try {
            $booking = $this->bookingFlowService->getBookingDetails($bookingId);

            return response()->json([
                'status' => 'success',
                'data' => $booking,
                'message' => 'Booking details retrieved successfully'
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Booking not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error getting booking details: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve booking details',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function deleteBooking(string $bookingId): JsonResponse
    {
        try {
            $result = $this->bookingFlowService->deleteBooking($bookingId, Auth::id());

            return response()->json([
                'status' => 'success',
                'data' => $result,
                'message' => $result['cancelled'] ? 'Booking cancelled successfully' : 'Booking deleted successfully'
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Booking not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error deleting booking: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete booking',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function updateBookingStatus(Request $request, string $bookingId): JsonResponse
    {
        $request->validate([
            'status' => 'required|string|in:draft,pending_approval,approved,confirmed,allocated,in_progress,completed,cancelled',
            'reason' => 'nullable|string|max:1000',
        ]);

        try {
            $result = $this->bookingFlowService->updateBookingStatus(
                $bookingId,
                $request->status,
                $request->reason,
                Auth::id()
            );

            return response()->json([
                'status' => 'success',
                'data' => $result,
                'message' => 'Booking status updated successfully',
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'Booking not found'], 404);
        } catch (\Exception $e) {
            Log::error('Error updating booking status: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update booking status',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function cancelRecurringBooking(Request $request, string $bookingId): JsonResponse
    {
        $validated = $request->validate([
            'scope' => ['required', 'string', Rule::in(['single', 'future'])],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $result = $this->bookingFlowService->cancelRecurringBooking(
                $bookingId,
                $validated['scope'],
                Auth::id(),
                $validated['reason'] ?? null
            );

            return response()->json([
                'status' => 'success',
                'data' => $result,
                'message' => $validated['scope'] === 'future'
                    ? 'Future recurring bookings cancelled successfully'
                    : 'Recurring occurrence cancelled successfully',
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Booking not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error cancelling recurring booking: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to cancel recurring booking',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function bulkOperations(Request $request): JsonResponse
    {
        $request->validate([
            'operation' => 'required|string|in:delete,approve,reject,assign_vehicle,assign_driver,change_status',
            'booking_ids' => 'required|array|min:1',
            'booking_ids.*' => 'uuid|exists:bookings,id',
            'data' => 'nullable|array',
            'reason' => 'nullable|string|max:500'
        ]);

        try {
            $result = $this->bookingFlowService->bulkOperations(
                $request->operation,
                $request->booking_ids,
                $request->data ?? [],
                Auth::id(),
                $request->reason
            );

            return response()->json([
                'status' => 'success',
                'data' => $result,
                'message' => 'Bulk operation completed successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error performing bulk operation: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to perform bulk operation',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getBookingEditHistory(string $bookingId): JsonResponse
    {
        try {
            $history = $this->bookingFlowService->getBookingEditHistory($bookingId);

            return response()->json([
                'status' => 'success',
                'data' => $history,
                'message' => 'Booking edit history retrieved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting booking edit history: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve booking edit history',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function cloneBooking(string $bookingId): JsonResponse
    {
        try {
            $clonedBooking = $this->bookingFlowService->cloneBooking($bookingId, Auth::id());

            $this->bookingFlowService->trackAnalytics('booking_cloned', [
                'original_booking_id' => $bookingId,
                'cloned_booking_id' => $clonedBooking->id,
                'cloned_by' => Auth::id(),
                'clone_time' => now()
            ]);

            return response()->json([
                'status' => 'success',
                'data' => new BookingFlowResource($clonedBooking),
                'message' => 'Booking cloned successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error cloning booking: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to clone booking',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getBookingAnalytics(string $bookingId): JsonResponse
    {
        try {
            $analytics = $this->bookingFlowService->getBookingAnalytics($bookingId);

            return response()->json([
                'status' => 'success',
                'data' => $analytics,
                'message' => 'Booking analytics retrieved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting booking analytics: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve booking analytics',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
