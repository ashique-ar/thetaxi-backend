<?php

namespace App\Http\Controllers\Api\Vehicle;

use App\Http\Controllers\Controller;
use App\Models\Vehicle\VehicleFinanceProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class VehicleFinanceProviderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $providers = VehicleFinanceProvider::query()
            ->withCount('leases')
            ->when(array_key_exists('is_active', $filters), fn ($q) => $q->where('is_active', $filters['is_active']))
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where(function ($nested) use ($search) {
                $nested->whereLikeInsensitive('name', trim($search))
                    ->orWhereLikeInsensitive('code', trim($search))
                    ->orWhereLikeInsensitive('registration_number', trim($search));
            }))
            ->orderBy('name')
            ->paginate($filters['per_page'] ?? 50);

        return response()->json(['status' => 'success', 'data' => $providers]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());
        $provider = VehicleFinanceProvider::create([
            ...$data,
            'code' => $data['code'] ?? 'VFP-' . strtoupper(substr((string) Str::uuid(), 0, 8)),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Finance provider created.',
            'data' => $provider,
        ], 201);
    }

    public function update(Request $request, VehicleFinanceProvider $vehicleFinanceProvider): JsonResponse
    {
        $vehicleFinanceProvider->update($request->validate($this->rules($vehicleFinanceProvider)));

        return response()->json([
            'status' => 'success',
            'message' => 'Finance provider updated.',
            'data' => $vehicleFinanceProvider->fresh(),
        ]);
    }

    public function destroy(VehicleFinanceProvider $vehicleFinanceProvider): JsonResponse
    {
        abort_if($vehicleFinanceProvider->leases()->exists(), 422, 'A finance provider used by a contract cannot be deleted.');
        $vehicleFinanceProvider->delete();

        return response()->json(null, 204);
    }

    private function rules(?VehicleFinanceProvider $provider = null): array
    {
        return [
            'code' => ['nullable', 'string', 'max:50', Rule::unique('vehicle_finance_providers', 'code')->ignore($provider?->id)],
            'name' => ['required', 'string', 'max:255'],
            'provider_type' => ['required', Rule::in(['bank', 'finance_company', 'leasing_company', 'individual_lessor', 'other'])],
            'registration_number' => ['nullable', 'string', 'max:120'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:60'],
            'address' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:3000'],
        ];
    }
}
