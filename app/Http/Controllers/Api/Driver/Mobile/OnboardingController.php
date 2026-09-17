<?php

namespace App\Http\Controllers\Api\Driver\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Driver\Driver;
use App\Models\Driver\DriverOnboardingApplication;
use App\Models\User;
use App\Models\UserContext;
use App\Models\Vehicle\Vehicle;
use App\Models\Vehicle\VehicleGrade;
use App\Models\Vehicle\VehicleGroup;
use App\Models\Vehicle\VehicleMake;
use App\Models\Vehicle\VehicleModel;
use App\Support\SriLankanNic;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OnboardingController extends Controller
{
    private const DOCUMENT_TYPES = [
        'driver_photo', 'driver_license_front', 'driver_license_back',
        'nic_front', 'nic_back', 'vehicle_insurance',
        'vehicle_revenue_license', 'vehicle_registration',
    ];

    public function show(Request $request): JsonResponse
    {
        return response()->json(['status' => 'success', 'data' => $this->applicationData($this->application($request))]);
    }

    public function updateStep(Request $request, int $step): JsonResponse
    {
        abort_unless(in_array($step, [1, 3, 4], true), 404);
        $application = $this->application($request);
        abort_if(in_array($application->status, ['submitted', 'approved', 'rejected'], true), 409, 'This application cannot be edited.');

        $rules = match ($step) {
            1 => [
                'first_name' => ['required', 'string', 'max:100'], 'last_name' => ['required', 'string', 'max:100'],
                'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($application->user_id)],
                'nic' => ['required', 'string', 'regex:/^(?:\d{9}[vVxX]|\d{12})$/'],
            ],
            3 => [
                'address' => ['required', 'string', 'max:500'],
                'country_id' => ['required', 'uuid', Rule::exists('countries', 'id')->whereNull('deleted_at')],
                'state_id' => ['required', 'uuid', Rule::exists('states', 'id')
                    ->where('country_id', $request->input('country_id'))->whereNull('deleted_at')],
                'city' => ['required', 'string', 'max:100'],
                'postal_code' => ['nullable', 'string', 'max:20'],
            ],
            4 => [
                'make_id' => ['required', 'uuid', 'exists:vehicle_makes,id'],
                'model_id' => ['required', 'uuid', Rule::exists('vehicle_models', 'id')->where('make_id', $request->input('make_id'))],
                'model_year' => ['required', 'integer', 'min:1950', 'max:'.(now()->year + 1)],
                'color' => ['required', 'string', 'max:50'], 'registration_year' => ['required', 'integer', 'min:1950', 'max:'.(now()->year + 1)],
                'license_plate' => ['required', 'string', 'max:30'], 'is_owner' => ['required', 'boolean'],
            ],
        };
        $data = $request->validate($rules);
        if ($step === 1) {
            $data['dob'] = SriLankanNic::dateOfBirth($data['nic']);
            if (! $data['dob']) {
                throw ValidationException::withMessages(['nic' => ['The NIC does not contain a valid date of birth.']]);
            }
        }
        $section = [1 => 'identity', 3 => 'address', 4 => 'vehicle'][$step];
        $payload = $application->payload ?? [];
        $changed = collect($data)->except('dob')->filter(fn ($value, $field) => ($payload[$section][$field] ?? null) != $value)->keys()->all();
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
        if (in_array($data['document_type'], ['driver_license_front', 'vehicle_insurance', 'vehicle_revenue_license'], true)
            && empty($data['expiry_date'])) {
            throw ValidationException::withMessages(['expiry_date' => ['An expiry date is required for this document.']]);
        }
        $this->assertIssueEditable($application, [$data['document_type']], 'documents');
        $old = $application->documents()->where('document_type', $data['document_type'])->whereIn('status', ['pending', 'changes_requested'])->latest()->first();
        $file = $data['file'];
        $path = $file->store("driver-onboarding/{$application->id}", 'public');
        $document = $application->documents()->create([
            'documentable_type' => DriverOnboardingApplication::class, 'documentable_id' => $application->id,
            'document_type' => $data['document_type'],
            'document_number' => $data['document_number'] ?? data_get($application->payload, 'identity.nic', 'pending'),
            'expiry_date' => $data['expiry_date'] ?? null, 'reminder_days' => $data['reminder_days'] ?? 30,
            'disk' => 'public', 'path' => $path, 'file_name' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(), 'file_type' => $file->getMimeType(), 'status' => 'pending',
            'replaces_document_id' => $old?->id,
        ]);
        $old?->update(['status' => 'superseded']);
        $application->update(['current_step' => max($application->current_step, 5)]);

        return response()->json(['status' => 'success', 'data' => $document], 201);
    }

    public function submit(Request $request): JsonResponse
    {
        $application = $this->application($request);
        $missing = collect(['identity', 'address', 'vehicle'])->reject(fn ($key) => ! empty($application->payload[$key]))->values()->all();
        $types = $application->documents()->where('status', 'pending')->pluck('document_type');
        $missing = array_merge($missing, collect(self::DOCUMENT_TYPES)->diff($types)->values()->all());
        if ($missing) throw ValidationException::withMessages(['application' => ['Missing: '.implode(', ', $missing)]]);
        $application->update(['status' => 'submitted', 'submitted_at' => now(), 'review_issues' => null, 'review_message' => null]);
        return response()->json(['status' => 'success', 'message' => 'Application submitted for review.', 'data' => $this->applicationData($application->fresh())]);
    }

    public function index(Request $request): JsonResponse
    {
        $applications = DriverOnboardingApplication::with(['user', 'documents'])->latest()->paginate($request->integer('per_page', 15));
        return response()->json(['status' => 'success', 'data' => $applications]);
    }

    public function review(Request $request, DriverOnboardingApplication $application): JsonResponse
    {
        abort_unless($application->status === 'submitted', 409, 'Only submitted applications can be reviewed.');
        $editableFields = [
            'identity.first_name', 'identity.last_name', 'identity.email', 'identity.nic',
            'address.address', 'address.country_id', 'address.state_id', 'address.city', 'address.postal_code',
            'vehicle.make_id', 'vehicle.model_id', 'vehicle.model_year', 'vehicle.color', 'vehicle.registration_year',
            'vehicle.license_plate', 'vehicle.is_owner', ...array_map(fn ($type) => "documents.{$type}", self::DOCUMENT_TYPES),
        ];
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'request_changes', 'reject'])],
            'message' => ['nullable', 'string', 'max:2000'],
            'issues' => ['required_if:decision,request_changes', 'array'],
            'issues.*.field' => ['required', Rule::in($editableFields)], 'issues.*.message' => ['required', 'string', 'max:500'],
        ]);
        if ($data['decision'] === 'approve') $this->approve($application, $request->user()->id);
        else $application->update([
            'status' => $data['decision'] === 'reject' ? 'rejected' : 'changes_requested',
            'review_issues' => $data['issues'] ?? null, 'review_message' => $data['message'] ?? null,
            'reviewed_by' => $request->user()->id, 'reviewed_at' => now(),
        ]);
        return response()->json(['status' => 'success', 'data' => $this->applicationData($application->fresh())]);
    }

    private function approve(DriverOnboardingApplication $application, string $reviewerId): void
    {
        DB::transaction(function () use ($application, $reviewerId) {
            $p = $application->payload;
            $user = $application->user ?: User::create([
                ...$p['identity'], 'phone' => $application->mobile, 'phone_verified_at' => now(),
                'password' => bcrypt(Str::random(24)), 'is_active' => true,
            ]);
            $user->update(array_merge($p['identity'], ['phone' => $application->mobile, 'phone_verified_at' => now()]));
            $grade = VehicleGrade::latest('created_at')->latest('id')->firstOrFail();
            $make = VehicleMake::findOrFail($p['vehicle']['make_id']);
            $model = VehicleModel::where('make_id', $make->id)->findOrFail($p['vehicle']['model_id']);
            $group = VehicleGroup::firstOrCreate([
                'make_id' => $make->id, 'model_id' => $model->id, 'grade_id' => $grade->id,
            ], [
                'name' => trim("{$make->name} {$model->name} {$grade->name}"),
                'is_active' => true, 'created_user_id' => $reviewerId,
            ]);
            $vehicleData = collect($p['vehicle'])->except(['make_id', 'model_id', 'registration_year', 'is_owner'])->all();
            $vehicle = Vehicle::create([
                ...$vehicleData, 'vehicle_group_id' => $group->id,
                'title' => trim($p['vehicle']['license_plate'].' '.$p['vehicle']['model_year']),
                'registration_no' => $p['vehicle']['license_plate'], 'year' => $p['vehicle']['registration_year'],
                'ownership_type' => $p['vehicle']['is_owner'] ? 'driver_owned' : 'third_party',
                'is_active' => false, 'created_user_id' => $reviewerId,
            ]);
            $driver = Driver::create([
                'user_id' => $user->id, 'nic' => $p['identity']['nic'], 'dob' => $p['identity']['dob'], ...$p['address'],
                'license_no' => optional($application->documents()->where('document_type', 'driver_license_front')->latest()->first())->document_number,
                'license_expiry' => optional($application->documents()->where('document_type', 'driver_license_front')->latest()->first())->expiry_date,
                'default_vehicle_id' => $vehicle->id, 'availability_status' => 'offline', 'created_user_id' => $reviewerId,
            ]);
            UserContext::create(['user_id' => $user->id, 'context_type' => 'driver', 'context_id' => $driver->id,
                'is_active' => true, 'created_user_id' => $reviewerId]);
            $vehicleTypes = ['vehicle_insurance', 'vehicle_revenue_license', 'vehicle_registration'];
            $application->documents->each(fn (Document $document) => $document->update([
                'documentable_type' => in_array($document->document_type, $vehicleTypes, true) ? $vehicle->getMorphClass() : $driver->getMorphClass(),
                'documentable_id' => in_array($document->document_type, $vehicleTypes, true) ? $vehicle->id : $driver->id,
                'status' => 'verified', 'verified_by' => $reviewerId, 'verified_at' => now(),
                'metadata' => $document->document_type === 'vehicle_revenue_license'
                    ? array_merge($document->metadata ?? [], ['renewal_reminder_date' => $document->expiry_date?->copy()->subMonthNoOverflow()->toDateString()])
                    : $document->metadata,
            ]));
            $application->update(['user_id' => $user->id, 'driver_id' => $driver->id, 'vehicle_id' => $vehicle->id,
                'status' => 'approved', 'reviewed_by' => $reviewerId, 'reviewed_at' => now(), 'review_issues' => null]);
        });
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
        unset($data['payload']['identity']['dob']);

        return [...$data, 'editable_fields' => $application->status === 'changes_requested'
            ? collect($application->review_issues)->pluck('field')->values()->all() : null];
    }

    private function assertIssueEditable(DriverOnboardingApplication $application, array $fields, string $section): void
    {
        if ($application->status !== 'changes_requested') return;
        $allowed = collect($application->review_issues)->pluck('field');
        abort_unless(collect($fields)->every(fn ($field) => $allowed->contains($field) || $allowed->contains("{$section}.{$field}")), 403,
            'Only fields identified by the reviewer can be changed.');
    }

}
