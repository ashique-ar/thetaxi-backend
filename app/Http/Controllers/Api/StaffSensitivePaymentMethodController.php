<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\StaffPaymentMethodResource;
use App\Http\Resources\StaffPaymentMethodChangeResource;
use App\Models\PaymentMethod;
use App\Models\Staff;
use App\Models\StaffPaymentMethodChange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Services\StaffAccessService;

class StaffSensitivePaymentMethodController extends Controller
{
    private const PAYMENT_FIELDS = [
        'method_type', 'label', 'account_holder_name', 'bank_name', 'bank_branch',
        'account_number', 'routing_number', 'card_brand', 'card_last_four',
        'card_expiry_month', 'card_expiry_year', 'wallet_provider', 'wallet_identifier',
        'cheque_payee_name', 'cheque_bank_name', 'is_default', 'is_active',
    ];

    public function __construct(private readonly StaffAccessService $staffAccess) {}

    public function index(Request $request, Staff $staff): JsonResponse
    {
        $this->staffAccess->authorize($request->user(), $staff, 'view');
        $methods = $staff->paymentMethods()->orderByDesc('is_default')->latest()->get();
        $this->audit($request, $staff, 'staff_payment_methods_viewed');

        return response()->json([
            'status' => 'success',
            'data' => StaffPaymentMethodResource::collection($methods),
        ]);
    }

    public function changes(Request $request, Staff $staff): JsonResponse
    {
        $this->staffAccess->authorize($request->user(), $staff, 'view');
        $changes = $staff->paymentMethodChanges()
            ->latest()
            ->paginate($request->integer('per_page', 20));

        $this->audit($request, $staff, 'staff_payment_method_changes_viewed');

        return response()->json([
            'status' => 'success',
            'data' => StaffPaymentMethodChangeResource::collection($changes),
        ]);
    }

    public function requestChange(Request $request, Staff $staff): JsonResponse
    {
        $this->staffAccess->authorize($request->user(), $staff, 'edit');
        $data = $request->validate([
            'action' => ['required', Rule::in(['create', 'update', 'deactivate'])],
            'payment_method_id' => ['nullable', 'uuid'],
            'reason' => ['required', 'string', 'max:2000'],
            'payment_method' => ['nullable', 'array'],
            'payment_method.method_type' => ['nullable', Rule::in(['cash', 'bank_transfer', 'cheque', 'card', 'wallet', 'online', 'other'])],
            'payment_method.label' => ['nullable', 'string', 'max:120'],
            'payment_method.account_holder_name' => ['nullable', 'string', 'max:255'],
            'payment_method.bank_name' => ['nullable', 'string', 'max:255'],
            'payment_method.bank_branch' => ['nullable', 'string', 'max:255'],
            'payment_method.account_number' => ['nullable', 'string', 'max:100'],
            'payment_method.routing_number' => ['nullable', 'string', 'max:100'],
            'payment_method.card_brand' => ['nullable', 'string', 'max:50'],
            'payment_method.card_last_four' => ['nullable', 'digits:4'],
            'payment_method.card_expiry_month' => ['nullable', 'integer', 'between:1,12'],
            'payment_method.card_expiry_year' => ['nullable', 'integer', 'min:'.date('Y')],
            'payment_method.wallet_provider' => ['nullable', 'string', 'max:120'],
            'payment_method.wallet_identifier' => ['nullable', 'string', 'max:255'],
            'payment_method.cheque_payee_name' => ['nullable', 'string', 'max:255'],
            'payment_method.cheque_bank_name' => ['nullable', 'string', 'max:255'],
            'payment_method.is_default' => ['nullable', 'boolean'],
            'payment_method.is_active' => ['nullable', 'boolean'],
        ]);

        if ($data['action'] !== 'create') {
            $this->ownedMethod($staff, $data['payment_method_id'] ?? null);
        }

        if (in_array($data['action'], ['create', 'update'], true) && empty($data['payment_method'])) {
            throw ValidationException::withMessages(['payment_method' => ['Payment method data is required for this action.']]);
        }

        $change = StaffPaymentMethodChange::create([
            'staff_id' => $staff->id,
            'payment_method_id' => $data['payment_method_id'] ?? null,
            'action' => $data['action'],
            'payload' => Arr::only($data['payment_method'] ?? [], self::PAYMENT_FIELDS),
            'reason' => $data['reason'],
            'status' => 'pending',
            'requested_by' => $request->user()->id,
            'created_user_id' => $request->user()->id,
        ]);

        $this->audit($request, $staff, 'staff_payment_method_change_requested', ['change_id' => $change->id, 'action' => $change->action]);

        return response()->json(['status' => 'success', 'data' => new StaffPaymentMethodChangeResource($change)], 202);
    }

    public function approve(Request $request, StaffPaymentMethodChange $change): JsonResponse
    {
        $data = $request->validate(['review_notes' => ['nullable', 'string', 'max:2000']]);

        $method = DB::transaction(function () use ($request, $change, $data) {
            $locked = StaffPaymentMethodChange::query()->lockForUpdate()->findOrFail($change->id);
            abort_unless($locked->status === 'pending', 409, 'This change has already been reviewed.');
            abort_if($locked->requested_by === $request->user()->id, 422, 'The requester cannot approve their own banking change.');

            $staff = Staff::query()->lockForUpdate()->findOrFail($locked->staff_id);
            $this->staffAccess->authorize($request->user(), $staff, 'edit');
            $payload = Arr::only($locked->payload ?? [], self::PAYMENT_FIELDS);

            if ($locked->action === 'create') {
                $method = $staff->paymentMethods()->create($payload + [
                    'method_type' => $payload['method_type'] ?? 'bank_transfer',
                    'is_active' => $payload['is_active'] ?? true,
                    'created_user_id' => $request->user()->id,
                ]);
            } else {
                $method = $this->ownedMethod($staff, $locked->payment_method_id, true);
                $method->update($locked->action === 'deactivate'
                    ? ['is_active' => false, 'updated_user_id' => $request->user()->id]
                    : $payload + ['updated_user_id' => $request->user()->id]);
            }

            if ($method->is_default) {
                $staff->paymentMethods()->where('id', '!=', $method->id)->update(['is_default' => false]);
            }

            $locked->update([
                'status' => 'approved',
                'payment_method_id' => $method->id,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'review_notes' => $data['review_notes'] ?? null,
                'applied_at' => now(),
                'updated_user_id' => $request->user()->id,
            ]);

            $this->audit($request, $staff, 'staff_payment_method_change_approved', ['change_id' => $locked->id, 'action' => $locked->action]);

            return $method->fresh();
        });

        return response()->json(['status' => 'success', 'data' => new StaffPaymentMethodResource($method)]);
    }

    public function reject(Request $request, StaffPaymentMethodChange $change): JsonResponse
    {
        $data = $request->validate(['review_notes' => ['required', 'string', 'max:2000']]);
        abort_unless($change->status === 'pending', 409, 'This change has already been reviewed.');
        abort_if($change->requested_by === $request->user()->id, 422, 'The requester cannot review their own banking change.');
        $this->staffAccess->authorize($request->user(), $change->staff, 'edit');

        $change->update([
            'status' => 'rejected',
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'review_notes' => $data['review_notes'],
            'updated_user_id' => $request->user()->id,
        ]);

        $this->audit($request, $change->staff, 'staff_payment_method_change_rejected', ['change_id' => $change->id, 'action' => $change->action]);

        return response()->json(['status' => 'success', 'data' => new StaffPaymentMethodChangeResource($change->fresh())]);
    }

    private function ownedMethod(Staff $staff, ?string $methodId, bool $withTrashed = false): PaymentMethod
    {
        if (! $methodId) {
            throw ValidationException::withMessages(['payment_method_id' => ['A Staff payment method is required for this action.']]);
        }

        $query = $staff->paymentMethods();
        if ($withTrashed) {
            $query->withTrashed();
        }

        return $query->whereKey($methodId)->firstOrFail();
    }

    private function audit(Request $request, Staff $staff, string $event, array $properties = []): void
    {
        activity('staff-sensitive-data')
            ->causedBy($request->user())
            ->performedOn($staff)
            ->withProperties($properties + ['ip' => $request->ip()])
            ->log($event);
    }
}
