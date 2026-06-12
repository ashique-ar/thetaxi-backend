<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PaymentMethod\CreatePaymentMethodRequest;
use App\Http\Requests\PaymentMethod\UpdatePaymentMethodRequest;
use App\Http\Resources\PaymentMethodResource;
use App\Models\Customer;
use App\Models\Driver\Driver;
use App\Models\PaymentMethod;
use App\Models\Staff;
use App\Models\Vehicle\VehicleOwner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentMethodController extends Controller
{
    private const PAYABLES = [
        'vehicle_owner' => ['alias' => 'vehicle_owner', 'class' => VehicleOwner::class],
        'driver' => ['alias' => 'driver', 'class' => Driver::class],
        'customer' => ['alias' => 'customer', 'class' => Customer::class],
        'staff' => ['alias' => 'staff', 'class' => Staff::class],
    ];

    public function index(Request $request)
    {
        if ($request->has('is_active')) {
            $normalizedIsActive = filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($normalizedIsActive !== null) {
                $request->merge(['is_active' => $normalizedIsActive]);
            }
        }

        $validated = $request->validate([
            'payable_type' => ['nullable', 'string', 'in:vehicle_owner,driver,customer,staff'],
            'payable_id' => ['nullable', 'uuid'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $query = PaymentMethod::query()
            ->when(isset($validated['payable_type']), function ($q) use ($validated) {
                $payable = self::PAYABLES[$validated['payable_type']];
                $q->whereIn('payable_type', [$payable['alias'], $payable['class']]);
            })
            ->when(isset($validated['payable_id']), fn ($q) => $q->where('payable_id', $validated['payable_id']))
            ->when($request->has('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->orderByDesc('is_default')
            ->latest();

        return PaymentMethodResource::collection($query->paginate($request->per_page ?? 15));
    }

    public function store(CreatePaymentMethodRequest $request): JsonResponse
    {
        $data = $this->normalizePayable($request->validated());

        $method = DB::transaction(function () use ($data, $request) {
            $this->assertDriverSingleMethod($data['payable_type'], $data['payable_id']);

            $existing = $this->findDuplicateMethod($data);
            if ($existing) {
                if (($data['is_default'] ?? false) === true) {
                    $this->clearDefault($data['payable_type'], $data['payable_id'], $existing->id);
                    $existing->update(['is_default' => true, 'updated_user_id' => $request->user()->id]);
                }

                return $existing;
            }

            if (($data['is_default'] ?? false) === true) {
                $this->clearDefault($data['payable_type'], $data['payable_id']);
            }

            return PaymentMethod::create($data + ['created_user_id' => $request->user()->id]);
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Payment method created',
            'data' => ['payment_method' => new PaymentMethodResource($method)],
        ], 201);
    }

    public function show(PaymentMethod $paymentMethod): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => ['payment_method' => new PaymentMethodResource($paymentMethod)],
        ]);
    }

    public function update(UpdatePaymentMethodRequest $request, PaymentMethod $paymentMethod): JsonResponse
    {
        $method = DB::transaction(function () use ($request, $paymentMethod) {
            $data = $request->validated();

            if (($data['is_default'] ?? false) === true) {
                $this->clearDefault($paymentMethod->payable_type, $paymentMethod->payable_id, $paymentMethod->id);
            }

            $paymentMethod->update($data + ['updated_user_id' => $request->user()->id]);

            return $paymentMethod;
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Payment method updated',
            'data' => ['payment_method' => new PaymentMethodResource($method)],
        ]);
    }

    public function destroy(PaymentMethod $paymentMethod): JsonResponse
    {
        $paymentMethod->delete();

        return response()->json(['status' => 'success', 'message' => 'Payment method deleted']);
    }

    private function normalizePayable(array $data): array
    {
        $payable = self::PAYABLES[$data['payable_type']];
        $class = $payable['class'];

        if (!$class::whereKey($data['payable_id'])->exists()) {
            throw ValidationException::withMessages(['payable_id' => ['The selected payable record does not exist.']]);
        }

        $data['payable_type'] = $payable['alias'];
        $data['is_default'] = $data['is_default'] ?? false;
        $data['is_active'] = $data['is_active'] ?? true;

        return $data;
    }

    private function assertDriverSingleMethod(string $payableType, string $payableId): void
    {
        if (in_array($payableType, ['driver', Driver::class], true) && PaymentMethod::whereIn('payable_type', ['driver', Driver::class])->where('payable_id', $payableId)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['payable_id' => ['Drivers can only have one active payment method.']]);
        }
    }

    private function clearDefault(string $payableType, string $payableId, ?string $exceptId = null): void
    {
        PaymentMethod::whereIn('payable_type', $this->payableTypeVariants($payableType))
            ->where('payable_id', $payableId)
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->update(['is_default' => false]);
    }

    private function findDuplicateMethod(array $data): ?PaymentMethod
    {
        $fields = [
            'method_type',
            'label',
            'account_holder_name',
            'bank_name',
            'bank_branch',
            'account_number',
            'routing_number',
            'card_brand',
            'card_last_four',
            'card_expiry_month',
            'card_expiry_year',
            'wallet_provider',
            'wallet_identifier',
            'cheque_payee_name',
            'cheque_bank_name',
        ];

        $query = PaymentMethod::whereIn('payable_type', $this->payableTypeVariants($data['payable_type']))
            ->where('payable_id', $data['payable_id'])
            ->where('is_active', true);

        foreach ($fields as $field) {
            $value = $data[$field] ?? null;
            $query->where(function ($q) use ($field, $value) {
                if ($value === null || $value === '') {
                    $q->whereNull($field)->orWhere($field, '');
                    return;
                }

                $q->where($field, $value);
            });
        }

        return $query->first();
    }

    private function payableTypeVariants(string $payableType): array
    {
        foreach (self::PAYABLES as $payable) {
            if (in_array($payableType, [$payable['alias'], $payable['class']], true)) {
                return [$payable['alias'], $payable['class']];
            }
        }

        return [$payableType];
    }
}
