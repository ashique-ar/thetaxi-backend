<?php

namespace App\Http\Controllers\Api\Vehicle;

use App\Http\Controllers\Controller;
use App\Http\Requests\Vehicle\VehicleRevenueLicense\CreateVehicleRevenueLicenseRequest;
use App\Http\Requests\Vehicle\VehicleRevenueLicense\UpdateVehicleRevenueLicenseRequest;
use App\Http\Resources\Vehicle\VehicleRevenueLicenseResource;
use App\Models\Document;
use App\Models\Vehicle\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class VehicleRevenueLicenseController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:vehicles.view')->only(['index', 'show']);
        $this->middleware('permission:vehicles.create|vehicles.edit')->only(['store', 'renew']);
        $this->middleware('permission:vehicles.edit')->only(['update']);
        $this->middleware('permission:vehicles.delete')->only(['destroy']);
    }

    public function index(Request $request)
    {
        $query = Document::query()->where('document_type', 'vehicle_revenue_license')
            ->where('documentable_type', (new Vehicle)->getMorphClass())
            ->when($request->filled('vehicle_id'), fn ($q) => $q->where('documentable_id', $request->vehicle_id))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->boolean('expiring_soon'), fn ($q) => $q->whereDate('expiry_date', '<=', now()->addDays(30)));

        return VehicleRevenueLicenseResource::collection($query->latest('expiry_date')->paginate($request->per_page ?? 15));
    }

    public function store(CreateVehicleRevenueLicenseRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $vehicle = Vehicle::findOrFail($payload['vehicle_id']);
        $license = $vehicle->documents()->create($this->attributes($payload, $request->user()->id));

        return response()->json(['status' => 'success', 'message' => 'Revenue license created',
            'data' => ['revenue_license' => new VehicleRevenueLicenseResource($license)]], 201);
    }

    public function show(Document $document): JsonResponse
    {
        $this->assertRevenueLicense($document);
        return response()->json(['status' => 'success', 'data' => ['revenue_license' => new VehicleRevenueLicenseResource($document)]]);
    }

    public function update(UpdateVehicleRevenueLicenseRequest $request, Document $document): JsonResponse
    {
        $this->assertRevenueLicense($document);
        $payload = array_merge($this->resourceData($document), $request->validated());
        $document->update($this->attributes($payload, $request->user()->id, false));
        return response()->json(['status' => 'success', 'message' => 'Revenue license updated',
            'data' => ['revenue_license' => new VehicleRevenueLicenseResource($document->fresh())]]);
    }

    public function renew(CreateVehicleRevenueLicenseRequest $request, Document $document): JsonResponse
    {
        $this->assertRevenueLicense($document);
        $renewed = DB::transaction(function () use ($request, $document) {
            $document->update(['status' => 'superseded', 'updated_user_id' => $request->user()->id]);
            $payload = $request->validated();
            $payload['vehicle_id'] = $document->documentable_id;
            $attributes = $this->attributes($payload, $request->user()->id);
            $attributes['replaces_document_id'] = $document->id;
            $attributes['metadata']['renewal_date'] = now()->toDateString();
            return Vehicle::findOrFail($document->documentable_id)->documents()->create($attributes);
        });
        return response()->json(['status' => 'success', 'message' => 'Revenue license renewed',
            'data' => ['revenue_license' => new VehicleRevenueLicenseResource($renewed)]], 201);
    }

    public function destroy(Document $document): JsonResponse
    {
        $this->assertRevenueLicense($document);
        $document->delete();
        return response()->json(['status' => 'success', 'message' => 'Revenue license deleted']);
    }

    private function attributes(array $payload, string $userId, bool $creating = true): array
    {
        $files = $payload['document_files'] ?? [];
        $first = $files[0] ?? [];
        return array_filter([
            'document_type' => 'vehicle_revenue_license',
            'document_number' => $payload['license_number'] ?: (string) Str::uuid(),
            'expiry_date' => $payload['expiry_date'] ?? null,
            'disk' => 'public', 'path' => $first['path'] ?? '',
            'file_name' => $first['name'] ?? basename($first['path'] ?? ''),
            'file_size' => 0, 'status' => $payload['status'] ?? 'active',
            'reminder_days' => 30,
            'metadata' => [
                'issued_date' => $payload['issued_date'] ?? null,
                'renewal_reminder_date' => $this->managedReminderDate($payload['expiry_date'] ?? null),
                'renewal_date' => $payload['renewal_date'] ?? null,
                'authority_name' => $payload['authority_name'] ?? null,
                'document_files' => $files,
                'notes' => $payload['notes'] ?? null,
            ],
            $creating ? 'created_user_id' : 'updated_user_id' => $userId,
        ], fn ($value) => $value !== null);
    }

    private function resourceData(Document $document): array
    {
        return ['license_number' => $document->document_number, 'expiry_date' => $document->expiry_date?->toDateString(),
            'status' => $document->status, ...($document->metadata ?? [])];
    }

    private function assertRevenueLicense(Document $document): void
    {
        abort_unless($document->document_type === 'vehicle_revenue_license'
            && $document->documentable_type === (new Vehicle)->getMorphClass(), 404);
    }

    private function managedReminderDate(mixed $expiryDate): ?string
    {
        return $expiryDate ? Carbon::parse($expiryDate)->subMonthNoOverflow()->toDateString() : null;
    }
}
