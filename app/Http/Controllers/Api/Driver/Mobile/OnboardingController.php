<?php

namespace App\Http\Controllers\Api\Driver\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Country;
use App\Models\State;
use App\Models\Driver\Driver;
use App\Models\Driver\DriverOnboardingApplication;
use App\Models\User;
use App\Models\UserContext;
use App\Models\Vehicle\Vehicle;
use App\Models\Vehicle\VehicleMake;
use App\Models\Vehicle\VehicleModel;
use App\Services\UserContextService;
use App\Support\SriLankanNic;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OnboardingController extends Controller
{
    private const DOCUMENT_TYPES = [
        'driver_photo',
        'driver_license_front',
        'driver_license_back',
        'nic_front',
        'nic_back',
        'vehicle_insurance',
        'vehicle_revenue_license',
        'vehicle_registration',
    ];

    public function makes(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => VehicleMake::query()
                ->select('id', 'name')->orderBy('name')->get()
        ]);
    }

    public function models(string $make): JsonResponse
    {
        VehicleMake::query()->findOrFail($make);

        return response()->json([
            'status' => 'success',
            'data' => VehicleModel::query()
                ->where('make_id', $make)->select('id', 'make_id', 'name')->orderBy('name')->get()
        ]);
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json(['status' => 'success', 'data' => $this->applicationData($this->application($request))]);
    }

    public function updateStep(Request $request, int $step): JsonResponse
    {
        abort_unless(in_array($step, [1, 3, 4], true), 404);
        $application = $this->application($request);
        abort_if(in_array($application->status, ['submitted', 'approved', 'rejected'], true), 409, 'This application cannot be edited.');

        if ($step === 4) {
            $this->normalizeOtherVehicleSelection($request);
        }

        $rules = match ($step) {
            1 => [
                'first_name' => ['required', 'string', 'max:100'],
                'last_name' => ['required', 'string', 'max:100'],
                'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($application->user_id)],
                'nic' => ['required', 'string', 'regex:/^(?:\d{9}[vVxX]|\d{12})$/'],
            ],
            3 => [
                'address' => ['required', 'string', 'max:500'],
                'country_id' => ['required', 'uuid', Rule::exists('countries', 'id')->whereNull('deleted_at')],
                'state_id' => [
                    'required',
                    'uuid',
                    Rule::exists('states', 'id')
                        ->where('country_id', $request->input('country_id'))->whereNull('deleted_at')
                ],
                'city' => ['required', 'string', 'max:100'],
                'postal_code' => ['nullable', 'string', 'max:20'],
            ],
            4 => [
                'make_id' => ['nullable', 'required_without:other_make', 'uuid', 'exists:vehicle_makes,id'],
                'other_make' => ['nullable', 'required_without:make_id', 'string', 'max:255'],
                'model_id' => ['nullable', 'required_without:other_model', 'uuid', Rule::exists('vehicle_models', 'id')->where('make_id', $request->input('make_id'))],
                'other_model' => ['nullable', 'required_without:model_id', 'string', 'max:255'],
                'model_year' => ['required', 'integer', 'min:1950', 'max:' . (now()->year + 1)],
                'color' => ['required', 'string', 'max:50'],
                'registration_year' => ['required', 'integer', 'min:1950', 'max:' . (now()->year + 1)],
                'license_plate' => ['required', 'string', 'max:30'],
                'is_owner' => ['required', 'boolean'],
            ],
        };
        $data = $request->validate($rules);
        if ($step === 1) {
            $data['dob'] = SriLankanNic::dateOfBirth($data['nic']);
            if (!$data['dob']) {
                throw ValidationException::withMessages(['nic' => ['The NIC does not contain a valid date of birth.']]);
            }
        }
        $section = [1 => 'identity', 3 => 'address', 4 => 'vehicle'][$step];
        $payload = $application->payload ?? [];
        $changed = collect($data)->except('dob')->filter(fn($value, $field) => ($payload[$section][$field] ?? null) != $value)->keys()->all();
        $this->assertIssueEditable($application, $changed, $section);
        $payload[$section] = array_merge($payload[$section] ?? [], $data);
        $application->update(['payload' => $payload, 'current_step' => max($application->current_step, $step + 1)]);

        return response()->json(['status' => 'success', 'data' => $this->applicationData($application->fresh())]);
    }

    public function uploadDocument(Request $request): JsonResponse
    {
        $application = $this->application($request);
        abort_if(in_array($application->status, ['submitted', 'approved', 'rejected'], true), 409, 'This application cannot be edited.');
        $data = $request->validate([
            'document_type' => ['required', Rule::in(self::DOCUMENT_TYPES)],
            'document_number' => ['nullable', 'string', 'max:100'],
            'expiry_date' => ['nullable', 'date', 'after:today'],
            'reminder_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
        ]);
        if (
            in_array($data['document_type'], ['driver_license_front', 'vehicle_insurance', 'vehicle_revenue_license'], true)
            && empty($data['expiry_date'])
        ) {
            throw ValidationException::withMessages(['expiry_date' => ['An expiry date is required for this document.']]);
        }
        $this->assertIssueEditable($application, [$data['document_type']], 'documents');
        $old = $application->documents()->where('document_type', $data['document_type'])->whereIn('status', ['pending', 'changes_requested'])->latest()->first();
        $file = $data['file'];
        $disk = config('filesystems.default', 's3');
        $path = $file->store("driver-onboarding/{$application->id}", $disk);
        abort_unless($path, 500, 'The document could not be stored. Please try again.');
        $document = $application->documents()->create([
            'documentable_type' => DriverOnboardingApplication::class,
            'documentable_id' => $application->id,
            'document_type' => $data['document_type'],
            'document_number' => $data['document_number'] ?? data_get($application->payload, 'identity.nic', 'pending'),
            'expiry_date' => $data['expiry_date'] ?? null,
            'reminder_days' => $data['reminder_days'] ?? 30,
            'disk' => $disk,
            'path' => $path,
            'file_name' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(),
            'file_type' => $file->getMimeType(),
            'status' => 'pending',
            'replaces_document_id' => $old?->id,
        ]);
        $old?->update(['status' => 'superseded']);
        $application->update(['current_step' => max($application->current_step, 5)]);

        return response()->json(['status' => 'success', 'data' => $this->documentData($document)], 201);
    }

    public function submit(Request $request): JsonResponse
    {
        $application = $this->application($request);
        $missing = collect(['identity', 'address', 'vehicle'])->reject(fn($key) => !empty($application->payload[$key]))->values()->all();
        $types = $application->documents()->where('status', 'pending')->pluck('document_type');
        $missing = array_merge($missing, collect(self::DOCUMENT_TYPES)->diff($types)->values()->all());
        if ($missing)
            throw ValidationException::withMessages(['application' => ['Missing: ' . implode(', ', $missing)]]);
        $application->update(['status' => 'submitted', 'submitted_at' => now(), 'review_issues' => null, 'review_message' => null]);
        return response()->json(['status' => 'success', 'message' => 'Application submitted for review.', 'data' => $this->applicationData($application->fresh())]);
    }

    public function index(Request $request): JsonResponse
    {
        $status = $request->string('status', 'submitted')->toString();
        abort_unless(in_array($status, ['submitted', 'draft', 'changes_requested', 'approved', 'rejected'], true), 422, 'Invalid application status.');
        if ($status === 'draft') {
            abort_unless($request->user()->can('drivers.onboarding-drafts.view'), 403, 'You do not have permission to view onboarding drafts.');
        }

        $applications = DriverOnboardingApplication::with(['user', 'documents', 'vehicle:id,vehicle_group_id'])
            ->where('status', $status)
            ->when($status === 'draft', fn($query) => $query->whereNotExists(function ($newer): void {
                $newer->selectRaw('1')
                    ->from('driver_onboarding_applications as newer_applications')
                    ->whereColumn('newer_applications.mobile', 'driver_onboarding_applications.mobile')
                    ->where('newer_applications.status', 'draft')
                    ->whereNull('newer_applications.deleted_at')
                    ->whereColumn('newer_applications.updated_at', '>', 'driver_onboarding_applications.updated_at');
            }))
            ->latest('updated_at')->paginate($request->integer('per_page', 15));

        $countryNames = Country::query()->whereIn('id', $applications->getCollection()->pluck('payload.address.country_id')->filter())
            ->pluck('name', 'id');
        $stateNames = State::query()->whereIn('id', $applications->getCollection()->pluck('payload.address.state_id')->filter())
            ->pluck('name', 'id');
        $makeNames = VehicleMake::query()->whereIn('id', $applications->getCollection()->pluck('payload.vehicle.make_id')->filter())
            ->pluck('name', 'id');
        $modelNames = VehicleModel::query()->whereIn('id', $applications->getCollection()->pluck('payload.vehicle.model_id')->filter())
            ->pluck('name', 'id');

        $applications->setCollection($applications->getCollection()->map(function (DriverOnboardingApplication $application) use ($countryNames, $stateNames, $makeNames, $modelNames) {
            $data = $application->toArray();
            $data['display'] = [
                'country' => $countryNames[data_get($application->payload, 'address.country_id')] ?? null,
                'state' => $stateNames[data_get($application->payload, 'address.state_id')] ?? null,
                'make' => $makeNames[data_get($application->payload, 'vehicle.make_id')] ?? data_get($application->payload, 'vehicle.other_make'),
                'model' => $modelNames[data_get($application->payload, 'vehicle.model_id')] ?? data_get($application->payload, 'vehicle.other_model'),
            ];

            return $data;
        })->unique('mobile')->values());
        return response()->json(['status' => 'success', 'data' => $applications]);
    }

    public function review(Request $request, DriverOnboardingApplication $application): JsonResponse
    {
        abort_unless($application->status === 'submitted', 409, 'Only submitted applications can be reviewed.');
        if ($request->input('decision') === 'approve') {
            $request->merge([
                'make_id' => $request->input('make_id', data_get($application->payload, 'vehicle.make_id')),
                'model_id' => $request->input('model_id', data_get($application->payload, 'vehicle.model_id')),
            ]);
        }
        $editableFields = [
            'identity.first_name',
            'identity.last_name',
            'identity.email',
            'identity.nic',
            'address.address',
            'address.country_id',
            'address.state_id',
            'address.city',
            'address.postal_code',
            'vehicle.make_id',
            'vehicle.other_make',
            'vehicle.model_id',
            'vehicle.other_model',
            'vehicle.model_year',
            'vehicle.color',
            'vehicle.registration_year',
            'vehicle.license_plate',
            'vehicle.is_owner',
            ...array_map(fn($type) => "documents.{$type}", self::DOCUMENT_TYPES),
        ];
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'request_changes', 'reject'])],
            'make_id' => ['required_if:decision,approve', 'nullable', 'uuid', 'exists:vehicle_makes,id'],
            'model_id' => ['required_if:decision,approve', 'nullable', 'uuid', Rule::exists('vehicle_models', 'id')->where('make_id', $request->input('make_id'))],
            'message' => ['nullable', 'string', 'max:2000'],
            'issues' => ['required_if:decision,request_changes', 'array'],
            'issues.*.field' => ['required', Rule::in($editableFields)],
            'issues.*.message' => ['required', 'string', 'max:500'],
        ]);
        if ($data['decision'] === 'approve') {
            $payload = $application->payload;
            $payload['vehicle']['make_id'] = $data['make_id'];
            $payload['vehicle']['model_id'] = $data['model_id'];
            $application->update(['payload' => $payload]);
            $this->approve($application->fresh(), $request->user()->id);
        } else
            $application->update([
                'status' => $data['decision'] === 'reject' ? 'rejected' : 'changes_requested',
                'review_issues' => $data['issues'] ?? null,
                'review_message' => $data['message'] ?? null,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);
        return response()->json(['status' => 'success', 'data' => $this->applicationData($application->fresh())]);
    }

    private function approve(DriverOnboardingApplication $application, string $reviewerId): void
    {
        $promotedFiles = [];
        try {
            DB::transaction(function () use ($application, $reviewerId, &$promotedFiles) {
                $p = $application->payload;
                $user = $application->user ?: User::create([
                    ...$p['identity'],
                    'phone' => $application->mobile,
                    'phone_verified_at' => now(),
                    'password' => bcrypt(Str::random(24)),
                    'is_active' => true,
                ]);
                $user->update(array_merge($p['identity'], ['phone' => $application->mobile, 'phone_verified_at' => now()]));
                $make = VehicleMake::findOrFail($p['vehicle']['make_id']);
                $model = VehicleModel::where('make_id', $make->id)->findOrFail($p['vehicle']['model_id']);
                $vehicleData = collect($p['vehicle'])->except([
                    'other_make',
                    'other_model',
                    'registration_year',
                    'is_owner',
                ])->all();
                $vehicle = Vehicle::create([
                    ...$vehicleData,
                    'vehicle_group_id' => null,
                    'title' => trim($p['vehicle']['license_plate'] . ' ' . $p['vehicle']['model_year']),
                    'registration_no' => $p['vehicle']['license_plate'],
                    'year' => $p['vehicle']['registration_year'],
                    'ownership_type' => $p['vehicle']['is_owner'] ? 'driver_owned' : 'third_party',
                    'is_active' => false,
                    'created_user_id' => $reviewerId,
                ]);
                $driver = Driver::create([
                    'user_id' => $user->id,
                    'nic' => $p['identity']['nic'],
                    'dob' => $p['identity']['dob'],
                    ...$p['address'],
                    'license_no' => optional($application->documents()->where('document_type', 'driver_license_front')->latest()->first())->document_number,
                    'license_expiry' => optional($application->documents()->where('document_type', 'driver_license_front')->latest()->first())->expiry_date,
                    'default_vehicle_id' => $vehicle->id,
                    'availability_status' => 'offline',
                    'created_user_id' => $reviewerId,
                ]);
                $driverContext = UserContext::updateOrCreate(
                    ['user_id' => $user->id, 'context_type' => 'driver'],
                    ['context_id' => $driver->id, 'is_active' => true, 'updated_user_id' => $reviewerId]
                );
                if (!$driverContext->created_user_id) {
                    $driverContext->forceFill(['created_user_id' => $reviewerId])->save();
                }
                app(UserContextService::class)->assignRolesToContext($user, $driverContext, ['driver']);
                $vehicleTypes = ['vehicle_insurance', 'vehicle_revenue_license', 'vehicle_registration'];
                foreach ($application->documents as $document) {
                    $isVehicleDocument = in_array($document->document_type, $vehicleTypes, true);
                    $storage = $this->promoteDocumentFile(
                        $document,
                        $isVehicleDocument ? 'vehicles' : 'drivers',
                        $isVehicleDocument ? $vehicle->id : $driver->id,
                        $promotedFiles
                    );
                    $document->update([
                        'documentable_type' => $isVehicleDocument ? $vehicle->getMorphClass() : $driver->getMorphClass(),
                        'documentable_id' => $isVehicleDocument ? $vehicle->id : $driver->id,
                        'disk' => $storage['disk'],
                        'path' => $storage['path'],
                        'status' => 'verified',
                        'verified_by' => $reviewerId,
                        'verified_at' => now(),
                        'metadata' => $document->document_type === 'vehicle_revenue_license'
                            ? array_merge($document->metadata ?? [], ['renewal_reminder_date' => $document->expiry_date?->copy()->subMonthNoOverflow()->toDateString()])
                            : $document->metadata,
                    ]);
                }
                $profilePhoto = $application->documents->firstWhere('document_type', 'driver_photo');
                if ($profilePhoto) {
                    $driver->update(['profile_photo_document_id' => $profilePhoto->id]);
                }
                $application->update([
                    'user_id' => $user->id,
                    'driver_id' => $driver->id,
                    'vehicle_id' => $vehicle->id,
                    'status' => 'approved',
                    'reviewed_by' => $reviewerId,
                    'reviewed_at' => now(),
                    'review_issues' => null
                ]);
                DB::afterCommit(function () use ($promotedFiles): void {
                    foreach ($promotedFiles as $file) {
                        if ($file['source_disk'] !== $file['target_disk'] || $file['source_path'] !== $file['target_path']) {
                            Storage::disk($file['source_disk'])->delete($file['source_path']);
                        }
                    }
                });
            });
        } catch (\Throwable $e) {
            foreach ($promotedFiles as $file) {
                if ($file['source_disk'] !== $file['target_disk'] || $file['source_path'] !== $file['target_path']) {
                    Storage::disk($file['target_disk'])->delete($file['target_path']);
                }
            }
            throw $e;
        }
    }

    private function promoteDocumentFile(Document $document, string $ownerFolder, string $ownerId, array &$promotedFiles): array
    {
        $sourceDisk = $document->disk;
        $sourcePath = $document->path;
        $targetDisk = config('filesystems.default', 's3');
        $extension = strtolower((string) pathinfo($document->file_name ?: $sourcePath, PATHINFO_EXTENSION));
        $targetPath = "{$ownerFolder}/{$ownerId}/documents/{$document->document_type}/{$document->id}" .
            ($extension !== '' ? ".{$extension}" : '');

        if ($sourceDisk === $targetDisk && $sourcePath === $targetPath) {
            return ['disk' => $targetDisk, 'path' => $targetPath];
        }

        $stream = Storage::disk($sourceDisk)->readStream($sourcePath);
        abort_unless(is_resource($stream), 500, "Unable to read {$document->document_type} for approval.");
        try {
            $stored = Storage::disk($targetDisk)->put($targetPath, $stream);
        } finally {
            fclose($stream);
        }
        abort_unless($stored, 500, "Unable to promote {$document->document_type} for approval.");
        $promotedFiles[] = [
            'source_disk' => $sourceDisk,
            'source_path' => $sourcePath,
            'target_disk' => $targetDisk,
            'target_path' => $targetPath,
        ];

        return ['disk' => $targetDisk, 'path' => $targetPath];
    }

    private function application(Request $request): DriverOnboardingApplication
    {
        $token = (string) ($request->bearerToken() ?: $request->header('X-Onboarding-Token'));
        abort_if($token === '', 401, 'Onboarding token is required.');
        return DriverOnboardingApplication::where('access_token_hash', hash('sha256', $token))->firstOrFail();
    }

    private function applicationData(DriverOnboardingApplication $application): array
    {
        $application->loadMissing('documents');
        $data = $application->toArray();
        $data['documents'] = $application->documents
            ->map(fn (Document $document) => $this->documentData($document))
            ->values()
            ->all();
        unset($data['payload']['identity']['dob']);

        return [
            ...$data,
            'editable_fields' => $application->status === 'changes_requested'
                ? collect($application->review_issues)->pluck('field')->values()->all() : null
        ];
    }

    private function documentData(Document $document): array
    {
        return [
            ...$document->toArray(),
            'url' => $document->resourceUrl(),
            'resource_url' => $document->resourceUrl(),
        ];
    }

    private function assertIssueEditable(DriverOnboardingApplication $application, array $fields, string $section): void
    {
        if ($application->status !== 'changes_requested')
            return;
        $allowed = collect($application->review_issues)->pluck('field');
        abort_unless(
            collect($fields)->every(fn($field) => $allowed->contains($field) || $allowed->contains("{$section}.{$field}")),
            403,
            'Only fields identified by the reviewer can be changed.'
        );
    }

    private function normalizeOtherVehicleSelection(Request $request): void
    {
        foreach (['make', 'model'] as $field) {
            $idField = "{$field}_id";
            $otherField = "other_{$field}";

            if (strcasecmp(trim((string) $request->input($idField)), 'other') === 0) {
                $request->merge([
                    $idField => null,
                    $otherField => $request->filled($otherField) ? $request->input($otherField) : 'Other',
                ]);
            } elseif ($request->filled($idField)) {
                $request->merge([$otherField => null]);
            }
        }
    }

}
