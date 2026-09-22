<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agreement;
use App\Models\Customer;
use App\Models\Document;
use App\Models\Driver\Driver;
use App\Models\Staff;
use App\Models\Vehicle\Vehicle;
use App\Models\Vehicle\VehicleLease;
use App\Models\Vehicle\VehicleOwner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use App\Services\StaffAccessService;

class DocumentController extends Controller
{
    private const RENEWABLE_TYPES = [
        'driver_license', 'driver_license_front', 'driver_license_back',
        'vehicle_insurance', 'vehicle_revenue_license', 'vehicle_registration',
    ];
    private const OWNER_TYPES = [
        'agreement' => Agreement::class,
        'customer' => Customer::class,
        'driver' => Driver::class,
        'staff' => Staff::class,
        'vehicle' => Vehicle::class,
        'vehicle_lease' => VehicleLease::class,
        'vehicle_owner' => VehicleOwner::class,
    ];

    public function __construct(private readonly StaffAccessService $staffAccess)
    {
        $this->middleware('permission:documents.view|system.view|agreements.view|customers.view|drivers.view|staff-sensitive-documents.view|vehicles.view|vehicle-owners.view|vehicle-leases.view')->only(['index', 'show', 'download', 'stats']);
        $this->middleware('permission:documents.create|uploads.manage|customers.edit|drivers.edit|staff-sensitive-documents.create|vehicles.edit|vehicle-owners.edit|vehicle-leases.edit')->only(['store']);
        $this->middleware('permission:documents.create|uploads.manage|customers.edit|drivers.edit|staff-sensitive-documents.create|vehicles.edit|vehicle-owners.edit|vehicle-leases.edit')->only(['ownerOptions']);
        $this->middleware('permission:documents.edit|uploads.manage|customers.edit|drivers.edit|staff-sensitive-documents.verify|vehicles.edit|vehicle-owners.edit|vehicle-leases.manage')->only(['verify', 'reject', 'setLegalHold']);
        $this->middleware('permission:documents.delete|uploads.manage|staff-sensitive-documents.delete')->only(['destroy']);
    }

    public function ownerOptions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'record_type' => ['required', 'string', 'max:50'],
            'search' => ['nullable', 'string', 'max:100'],
            'selected_id' => ['nullable', 'uuid'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $ownerType = $this->normaliseOwnerType($data['record_type']);
        $this->assertOwnerAccess($request, $ownerType, 'create', $data['selected_id'] ?? null);
        $query = $this->ownerOptionQuery($request, $ownerType);

        if (! empty($data['selected_id'])) {
            $query->whereKey($data['selected_id']);
        } elseif ($search = trim((string) ($data['search'] ?? ''))) {
            $this->applyOwnerOptionSearch($query, $ownerType, $search);
        }

        $owners = $query->limit(min(50, (int) ($data['per_page'] ?? 25)))->get();

        return response()->json([
            'status' => 'success',
            'data' => $owners->map(fn (Model $owner) => [
                'value' => (string) $owner->getKey(),
                'label' => $this->ownerLabel($owner),
                'metadata' => ['type' => str_replace('_', ' ', $ownerType)],
                'status' => $owner->deleted_at ? 'inactive' : 'active',
            ])->filter(fn (array $option) => filled($option['label']))->values(),
        ]);
    }

    private function ownerOptionQuery(Request $request, string $ownerType): Builder
    {
        $class = $this->ownerClass($ownerType);
        $query = $class::query();

        if (in_array($ownerType, ['customer', 'driver', 'staff', 'vehicle_owner'], true)) {
            $query->with('user');
        }
        if ($ownerType === 'vehicle_lease') {
            $query->with('vehicle');
        }
        if ($ownerType === 'staff') {
            return $this->staffAccess->scope($query, $request->user())
                ->whereNull('employment_ended_at');
        }

        return $query;
    }

    private function applyOwnerOptionSearch(Builder $query, string $ownerType, string $search): void
    {
        $query->where(function (Builder $nested) use ($ownerType, $search): void {
            if ($ownerType === 'agreement') {
                $nested->whereLikeInsensitive('title', $search);
            } elseif ($ownerType === 'vehicle') {
                $nested->whereLikeInsensitive('registration_no', $search)->orWhereLikeInsensitive('title', $search);
            } elseif ($ownerType === 'vehicle_lease') {
                $nested->whereLikeInsensitive('lease_number', $search)->orWhereLikeInsensitive('agreement_number', $search);
            } else {
                if (in_array($ownerType, ['customer', 'driver', 'staff'], true)) {
                    $nested->whereLikeInsensitive('code', $search);
                }
                $nested->orWhereHas('user', fn (Builder $user) => $user
                    ->whereLikeInsensitive('first_name', $search)
                    ->orWhereLikeInsensitive('last_name', $search)
                    ->orWhereLikeInsensitive('email', $search));
            }
        });
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'owner_type' => ['nullable', 'string', 'max:50'],
            'owner_id' => ['nullable', 'uuid'],
            'document_type' => ['nullable', 'string', 'max:50'],
            'employment_spell_id' => ['nullable', 'uuid', 'exists:hr_employment_spells,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'status' => ['nullable', 'string', 'in:pending,verified,rejected'],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Document::with('documentable')->latest();

        if (!empty($data['owner_type'])) {
            $this->assertOwnerAccess($request, $data['owner_type'], 'view', $data['owner_id'] ?? null);
            $query->where('documentable_type', $this->ownerClass($data['owner_type']));
            if ($this->normaliseOwnerType($data['owner_type']) === 'staff' && empty($data['owner_id'])) {
                $query->whereIn('documentable_id', $this->staffAccess->scope(Staff::query(), $request->user())->select('id'));
            }
        } elseif (! $request->user()->can('staff-sensitive-documents.view')) {
            $query->whereNotIn('documentable_type', ['staff', Staff::class]);
        } else {
            $staffIds = $this->staffAccess->scope(Staff::query(), $request->user())->select('id');
            $query->where(function ($scope) use ($staffIds) {
                $scope->whereNotIn('documentable_type', ['staff', Staff::class])
                    ->orWhere(function ($staffScope) use ($staffIds) {
                        $staffScope->whereIn('documentable_type', ['staff', Staff::class])
                            ->whereIn('documentable_id', $staffIds);
                    });
            });
        }

        if (!empty($data['owner_id'])) {
            $query->where('documentable_id', $data['owner_id']);
        }

        if (!empty($data['document_type'])) {
            $query->where('document_type', $data['document_type']);
        }

        if (!empty($data['employment_spell_id'])) {
            $query->where('employment_spell_id', $data['employment_spell_id']);
        }

        if (!empty($data['date_from'])) {
            $query->whereDate('created_at', '>=', $data['date_from']);
        }

        if (!empty($data['date_to'])) {
            $query->whereDate('created_at', '<=', $data['date_to']);
        }

        if (!empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        if (!empty($data['search'])) {
            $search = trim($data['search']);
            $query->where(function ($nested) use ($search) {
                $nested->whereLikeInsensitive('document_number', $search)
                    ->orWhereLikeInsensitive('file_name', $search)
                    ->orWhereLikeInsensitive('file_type', $search);
            });
        }

        $documents = $query->paginate($request->integer('per_page', 15));

        return response()->json([
            'status' => 'success',
            'data' => [
                'documents' => $documents->getCollection()->map(fn (Document $document) => $this->payload($document))->values(),
                'data' => $documents->getCollection()->map(fn (Document $document) => $this->payload($document))->values(),
                'current_page' => $documents->currentPage(),
                'last_page' => $documents->lastPage(),
                'per_page' => $documents->perPage(),
                'total' => $documents->total(),
            ],
        ]);
    }

    public function stats(Request $request): JsonResponse
    {
        $data = $request->validate([
            'owner_type' => ['nullable', 'string', 'max:50'],
            'owner_id' => ['nullable', 'uuid'],
        ]);

        $query = Document::query();

        if (!empty($data['owner_type'])) {
            $this->assertOwnerAccess($request, $data['owner_type'], 'view', $data['owner_id'] ?? null);
            $query->where('documentable_type', $this->ownerClass($data['owner_type']));
            if ($this->normaliseOwnerType($data['owner_type']) === 'staff' && empty($data['owner_id'])) {
                $query->whereIn('documentable_id', $this->staffAccess->scope(Staff::query(), $request->user())->select('id'));
            }
        } elseif (! $request->user()->can('staff-sensitive-documents.view')) {
            $query->whereNotIn('documentable_type', ['staff', Staff::class]);
        } else {
            $staffIds = $this->staffAccess->scope(Staff::query(), $request->user())->select('id');
            $query->where(function ($scope) use ($staffIds) {
                $scope->whereNotIn('documentable_type', ['staff', Staff::class])
                    ->orWhere(function ($staffScope) use ($staffIds) {
                        $staffScope->whereIn('documentable_type', ['staff', Staff::class])
                            ->whereIn('documentable_id', $staffIds);
                    });
            });
        }

        if (!empty($data['owner_id'])) {
            $query->where('documentable_id', $data['owner_id']);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'totalDocuments' => (clone $query)->count(),
                'pendingVerification' => (clone $query)->where('status', 'pending')->count(),
                'verifiedDocuments' => (clone $query)->where('status', 'verified')->count(),
                'rejectedDocuments' => (clone $query)->where('status', 'rejected')->count(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'owner_type' => ['required', 'string', 'max:50'],
            'owner_id' => ['required', 'uuid'],
            'employment_spell_id' => ['nullable', 'uuid', 'exists:hr_employment_spells,id'],
            'document_type' => ['required', 'string', 'max:50'],
            'document_number' => ['nullable', 'string', 'max:255'],
            'idempotency_key' => ['required', 'uuid'],
            'expiry_date' => ['nullable', 'date'],
            'supersedes_id' => ['nullable', 'uuid'],
            'retention_until' => ['nullable', 'date'],
            'file' => ['required', 'file', 'max:20480'],
        ]);

        $this->assertOwnerAccess($request, $data['owner_type'], 'create', $data['owner_id']);
        $file = $request->file('file');
        $checksum = hash('sha256', json_encode([
            'actor_id' => $request->user()?->id,
            'owner_type' => $this->normaliseOwnerType($data['owner_type']),
            'owner_id' => $data['owner_id'],
            'employment_spell_id' => $data['employment_spell_id'] ?? null,
            'document_type' => $data['document_type'],
            'document_number' => $data['document_number'] ?? null,
            'expiry_date' => $data['expiry_date'] ?? null,
            'supersedes_id' => $data['supersedes_id'] ?? null,
            'retention_until' => $data['retention_until'] ?? null,
            'file_sha256' => hash_file('sha256', $file->getRealPath()),
        ], JSON_THROW_ON_ERROR));
        $replay = Document::query()->where('upload_idempotency_key', $data['idempotency_key'])->first();
        if ($replay) {
            abort_unless(
                hash_equals((string) $replay->upload_request_checksum, $checksum)
                    && (string) $replay->created_user_id === (string) ($request->user()?->id),
                Response::HTTP_CONFLICT,
                'The upload retry key was already used for a different request.'
            );

            return response()->json(['status' => 'success', 'data' => $this->payload($replay->load('documentable'))]);
        }
        $owner = $this->owner($data['owner_type'], $data['owner_id']);
        $ownerType = $this->normaliseOwnerType($data['owner_type']);
        $disk = $this->storageDisk($ownerType);
        $superseded = ! empty($data['supersedes_id'])
            ? $owner->documents()->whereKey($data['supersedes_id'])->firstOrFail()
            : null;
        if (! empty($data['employment_spell_id'])) {
            abort_unless(
                $ownerType === 'staff' && DB::table('hr_employment_spells')->where('id', $data['employment_spell_id'])->where('staff_id', $owner->getKey())->exists(),
                422,
                'The employment spell must belong to the document owner.'
            );
            // §5.3: uploads linked to a governed employment spell must use an approved
            // hr_document_types code so §5.1 compliance computation (which joins on this
            // same code) never silently misses a document filed under an unregistered type.
            abort_unless(
                DB::table('hr_document_types')->where('company_id', $owner->company_id)->where('code', $data['document_type'])->where('status', 'active')->exists(),
                422,
                'This document type is not an approved code in the governed HR document-type register.'
            );
        }
        $path = $file->store("documents/{$ownerType}/{$owner->getKey()}", $disk);
        $previous = in_array($data['document_type'], self::RENEWABLE_TYPES, true)
            ? $owner->documents()->where('document_type', $data['document_type'])
                ->whereIn('status', ['pending', 'verified', 'active'])->latest()->first()
            : null;

        $replacement = $superseded ?? $previous;
        try {
            $document = DB::transaction(function () use ($request, $owner, $ownerType, $data, $checksum, $file, $disk, $path, $replacement): Document {
                $lockedOwner = $owner::query()->lockForUpdate()->findOrFail($owner->getKey());
                $document = $lockedOwner->documents()->create([
                    'employment_spell_id' => $data['employment_spell_id'] ?? $replacement?->employment_spell_id,
                    'document_type' => $data['document_type'],
                    'document_number' => $data['document_number'] ?? (string) Str::uuid(),
                    'upload_idempotency_key' => $data['idempotency_key'],
                    'upload_request_checksum' => $checksum,
                    'expiry_date' => $data['expiry_date'] ?? null,
                    'disk' => $disk,
                    'path' => $path,
                    'file_name' => $file->getClientOriginalName(),
                    'file_size' => $file->getSize(),
                    'file_type' => $file->getMimeType(),
                    'status' => 'pending',
                    'classification' => $ownerType === 'staff' ? 'hr_confidential' : 'operational',
                    'version' => $replacement ? $replacement->version + 1 : 1,
                    'supersedes_id' => $replacement?->id,
                    'replaces_document_id' => $replacement?->id,
                    'retention_until' => $data['retention_until'] ?? null,
                    'created_user_id' => $request->user()?->id,
                ]);
                $replacement?->update(['status' => 'superseded']);
                $this->audit($request, $document, 'document_uploaded');

                return $document;
            });
        } catch (QueryException $exception) {
            $replay = Document::query()->where('upload_idempotency_key', $data['idempotency_key'])->first();
            if (! $replay) {
                Storage::disk($disk)->delete($path);
                throw $exception;
            }
            Storage::disk($disk)->delete($path);
            abort_unless(
                hash_equals((string) $replay->upload_request_checksum, $checksum)
                    && (string) $replay->created_user_id === (string) ($request->user()?->id),
                Response::HTTP_CONFLICT,
                'The upload retry key was already used for a different request.'
            );

            return response()->json(['status' => 'success', 'data' => $this->payload($replay->load('documentable'))]);
        } catch (\Throwable $exception) {
            Storage::disk($disk)->delete($path);
            throw $exception;
        }
        return response()->json([
            'status' => 'success',
            'message' => 'Document uploaded successfully',
            'data' => $this->payload($document->load('documentable')),
        ], Response::HTTP_CREATED);
    }

    public function show(Request $request, Document $document): JsonResponse
    {
        $this->assertDocumentAccess($request, $document, 'view');
        $this->audit($request, $document, 'document_viewed');

        return response()->json([
            'status' => 'success',
            'data' => $this->payload($document->load('documentable')),
        ]);
    }

    public function download(Request $request, Document $document): mixed
    {
        $this->assertDocumentAccess($request, $document, 'view');
        abort_unless(Storage::disk($document->disk)->exists($document->path), Response::HTTP_NOT_FOUND);
        $this->audit($request, $document, 'document_downloaded');

        return Storage::disk($document->disk)->download($document->path, $document->file_name);
    }

    public function verify(Request $request, Document $document): JsonResponse
    {
        $this->assertDocumentAccess($request, $document, 'verify');
        $data = $request->validate([
            'verification_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $document->update([
            'status' => 'verified',
            'verification_notes' => $data['verification_notes'] ?? $document->verification_notes,
            'verified_at' => now(),
            'verified_by' => $request->user()?->id,
        ]);
        $this->audit($request, $document, 'document_verified');

        return response()->json([
            'status' => 'success',
            'message' => 'Document verified successfully',
            'data' => $this->payload($document->fresh()->load('documentable')),
        ]);
    }

    public function reject(Request $request, Document $document): JsonResponse
    {
        $this->assertDocumentAccess($request, $document, 'verify');
        $data = $request->validate([
            'verification_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $document->update([
            'status' => 'rejected',
            'verification_notes' => $data['verification_notes'] ?? $document->verification_notes,
            'verified_at' => null,
            'verified_by' => null,
        ]);
        $this->audit($request, $document, 'document_rejected');

        return response()->json([
            'status' => 'success',
            'message' => 'Document rejected',
            'data' => $this->payload($document->fresh()->load('documentable')),
        ]);
    }

    public function destroy(Request $request, Document $document): JsonResponse
    {
        $this->assertDocumentAccess($request, $document, 'delete');
        abort_if($document->legal_hold, Response::HTTP_CONFLICT, 'This document is under legal hold and cannot be deleted.');
        $this->audit($request, $document, 'document_archived');
        $document->delete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    public function setLegalHold(Request $request, Document $document): JsonResponse
    {
        $this->assertDocumentAccess($request, $document, 'verify');
        $data = $request->validate(['legal_hold' => ['required', 'boolean'], 'reason' => ['required', 'string', 'max:1000']]);
        $document->update(['legal_hold' => $data['legal_hold']]);
        $this->audit($request, $document, $data['legal_hold'] ? 'document_legal_hold_applied' : 'document_legal_hold_released');

        return response()->json(['status' => 'success', 'data' => $this->payload($document->fresh()->load('documentable'))]);
    }

    private function owner(string $type, string $id): Model
    {
        return $this->ownerClass($type)::findOrFail($id);
    }

    private function ownerClass(string $type): string
    {
        $type = $this->normaliseOwnerType($type);
        abort_unless(isset(self::OWNER_TYPES[$type]), Response::HTTP_UNPROCESSABLE_ENTITY, 'Unsupported document owner type.');

        return self::OWNER_TYPES[$type];
    }

    private function normaliseOwnerType(string $type): string
    {
        return str_replace('-', '_', Str::snake($type));
    }

    private function payload(Document $document): array
    {
        $owner = $document->documentable;
        $ownerType = array_search($document->documentable_type, self::OWNER_TYPES, true) ?: $document->documentable_type;
        $isStaffDocument = $ownerType === 'staff';

        return [
            'id' => $document->id,
            'owner_type' => $ownerType,
            'owner_id' => $document->documentable_id,
            'owner_label' => $this->ownerLabel($owner),
            'documentable_type' => $document->documentable_type,
            'documentable_id' => $document->documentable_id,
            'employment_spell_id' => $document->employment_spell_id,
            'document_type' => $document->document_type,
            'document_number' => $document->document_number,
            'expiry_date' => $document->expiry_date?->toDateString(),
            'file_url' => $isStaffDocument ? null : $this->storageUrl($document->disk, $document->path),
            'download_url' => $isStaffDocument ? "/api/documents/{$document->id}/download" : null,
            'file_name' => $document->file_name,
            'file_size' => $document->file_size,
            'file_type' => $document->file_type,
            'status' => $document->status,
            'replaces_document_id' => $document->replaces_document_id,
            'metadata' => $document->metadata,
            'verification_notes' => $document->verification_notes,
            'classification' => $document->classification,
            'version' => $document->version,
            'supersedes_id' => $document->supersedes_id,
            'retention_until' => $document->retention_until?->toISOString(),
            'legal_hold' => (bool) $document->legal_hold,
            'verified_at' => $document->verified_at?->toISOString(),
            'created_at' => $document->created_at?->toISOString(),
            'updated_at' => $document->updated_at?->toISOString(),
        ];
    }

    private function ownerLabel(?Model $owner): ?string
    {
        if (!$owner) {
            return null;
        }

        if ($owner instanceof Agreement) {
            return $owner->title;
        }

        if ($owner instanceof Vehicle) {
            return trim(($owner->title ?: 'Vehicle') . ' ' . ($owner->registration_no ? "({$owner->registration_no})" : ''));
        }

        if ($owner instanceof VehicleLease) {
            return $owner->lease_number;
        }

        if (method_exists($owner, 'getFullNameAttribute')) {
            return $owner->full_name;
        }

        if (method_exists($owner, 'user')) {
            $user = $owner->user;

            return trim((string) ($user?->first_name . ' ' . $user?->last_name)) ?: $user?->email;
        }

        return (string) $owner->getKey();
    }

    private function storageDisk(string $ownerType): string
    {
        if ($ownerType === 'staff') {
            return 'hr_private';
        }

        return config('filesystems.default', 'public');
    }

    private function storageUrl(string $diskName, string $path): string
    {
        $disk = Storage::disk($diskName);

        return method_exists($disk, 'providesTemporaryUrls') && $disk->providesTemporaryUrls()
            ? $disk->temporaryUrl($path, now()->addMinutes(15))
            : $disk->url($path);
    }

    private function assertDocumentAccess(Request $request, Document $document, string $action): void
    {
        $ownerType = array_search($document->documentable_type, self::OWNER_TYPES, true) ?: $document->documentable_type;
        $this->assertOwnerAccess($request, (string) $ownerType, $action, $document->documentable_id);
    }

    private function assertOwnerAccess(Request $request, string $ownerType, string $action, ?string $ownerId = null): void
    {
        $ownerType = $this->normaliseOwnerType($ownerType);
        $this->ownerClass($ownerType);

        if ($ownerType !== 'staff') {
            $globalPermission = match ($action) {
                'create' => ['documents.create', 'uploads.manage'],
                'verify' => ['documents.edit', 'uploads.manage'],
                'delete' => ['documents.delete', 'uploads.manage'],
                default => ['documents.view', 'system.view'],
            };
            $domainPermission = match ($ownerType) {
                'agreement' => $action === 'view' ? 'agreements.view' : 'documents.create',
                'customer' => $action === 'view' ? 'customers.view' : 'customers.edit',
                'driver' => $action === 'view' ? 'drivers.view' : 'drivers.edit',
                'vehicle' => $action === 'view' ? 'vehicles.view' : 'vehicles.edit',
                'vehicle_owner' => $action === 'view' ? 'vehicle-owners.view' : 'vehicle-owners.edit',
                'vehicle_lease' => $action === 'view' ? 'vehicle-leases.view' : ($action === 'create' ? 'vehicle-leases.edit' : 'vehicle-leases.manage'),
            };

            abort_unless(
                collect([...$globalPermission, $domainPermission])->contains(fn (string $permission) => $request->user()?->can($permission)),
                Response::HTTP_FORBIDDEN,
                'You are not authorized to access documents for this owner type.'
            );

            return;
        }

        $permission = match ($action) {
            'create' => 'staff-sensitive-documents.create',
            'verify' => 'staff-sensitive-documents.verify',
            'delete' => 'staff-sensitive-documents.delete',
            default => 'staff-sensitive-documents.view',
        };

        abort_unless($request->user()?->can($permission), Response::HTTP_FORBIDDEN, 'Staff documents require a dedicated sensitive-data permission.');

        if ($ownerId) {
            $staff = Staff::withTrashed()->findOrFail($ownerId);
            $scopeAction = in_array($action, ['create', 'verify', 'delete'], true) ? 'edit' : 'view';
            $this->staffAccess->authorize($request->user(), $staff, $scopeAction);
        }
    }

    private function audit(Request $request, Document $document, string $event): void
    {
        activity('document-access')
            ->causedBy($request->user())
            ->performedOn($document)
            ->withProperties([
                'owner_type' => $document->documentable_type,
                'owner_id' => $document->documentable_id,
                'ip' => $request->ip(),
            ])
            ->log($event);
    }
}
