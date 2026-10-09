<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MedicalCategory;
use App\Models\MedicalRecord;
use App\Models\Staff;
use App\Services\SingleCompanyScope;
use App\Services\StaffAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MedicalRecordController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $companyId = $this->actorCompanyId($request);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'subject_type' => ['nullable', Rule::in(['vehicle', 'staff'])],
            'subject_id' => ['nullable', 'uuid'],
            'category_id' => ['nullable', 'uuid'],
            'record_type' => ['nullable', 'string', 'max:60'],
            'status' => ['nullable', Rule::in(['active', 'expired', 'suspended', 'revoked', 'superseded', 'cancelled'])],
            'expiring_within_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $query = MedicalRecord::query()->where('company_id', $companyId)
            ->when($filters['subject_type'] ?? null, fn ($q, $value) => $q->where('subject_type', $value))
            ->when($filters['subject_id'] ?? null, fn ($q, $value) => $q->where('subject_id', $value))
            ->when($filters['category_id'] ?? null, fn ($q, $value) => $q->where('medical_category_id', $value))
            ->when($filters['status'] ?? null, fn ($q, $value) => $q->where('status', $value))
            ->when($filters['expiring_within_days'] ?? null, fn ($q, $days) => $q->whereBetween('valid_until', [today(), today()->addDays($days)]))
            ->when($filters['search'] ?? null, function ($q, $search): void {
                $term = '%'.mb_strtolower(trim($search)).'%';
                $q->where(fn ($match) => $match
                    ->whereRaw('LOWER(title) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(record_number) LIKE ?', [$term])
                    ->orWhereRaw("LOWER(COALESCE(issuing_authority, '')) LIKE ?", [$term]));
            });

        if (! empty($filters['record_type'])) {
            $query->whereHas('category', fn ($category) => $category->where('code', $filters['record_type']));
        }

        $page = $query->orderByDesc('created_at')->orderBy('id')->paginate($filters['per_page'] ?? 25);
        $page->getCollection()->transform(fn (MedicalRecord $record) => $this->serializeRecord($record));
        $this->auditListView($request, $companyId);

        return response()->json(['status' => 'success', 'data' => $page]);
    }

    public function categories(Request $request): JsonResponse
    {
        $companyId = $this->actorCompanyId($request);
        $categories = MedicalCategory::query()->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('company_id')->orWhere('company_id', $companyId))
            ->orderBy('name')->orderBy('id')->get(['id', 'code', 'name', 'description']);

        return response()->json(['status' => 'success', 'data' => $categories]);
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = $this->actorCompanyId($request);
        $data = $request->validate([
            'subject_type' => ['required', Rule::in(['vehicle', 'staff'])],
            'subject_id' => ['required', 'uuid'],
            'medical_category_id' => ['required', 'uuid'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'record_number' => ['nullable', 'string', 'max:80', Rule::unique('medical_records', 'record_number')->where('company_id', $companyId)],
            'issued_date' => ['required', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:issued_date'],
            'issuing_authority' => ['nullable', 'string', 'max:255'],
        ]);
        $this->authorizeSubject($data['subject_type'], $data['subject_id'], $companyId);
        abort_unless(MedicalCategory::query()->whereKey($data['medical_category_id'])->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $companyId))->exists(), 422, 'Select an active medical category.');

        $data['company_id'] = $companyId;
        $data['record_number'] = $data['record_number'] ?: 'MED-'.Str::upper(Str::random(12));
        $data['status'] = 'active';
        $record = MedicalRecord::create($data);

        return response()->json(['status' => 'success', 'data' => $this->serializeRecord($record)], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $companyId = $this->actorCompanyId($request);
        $record = $this->record($id, $companyId);
        $this->auditRecordView($request, $record, $companyId);

        return response()->json(['status' => 'success', 'data' => $this->serializeRecord($record)]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $companyId = $this->actorCompanyId($request);
        $record = $this->record($id, $companyId);
        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'valid_until' => ['sometimes', 'nullable', 'date'],
            'issuing_authority' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(['active', 'expired', 'suspended', 'revoked', 'superseded', 'cancelled'])],
        ]);
        $issuedDate = $data['issued_date'] ?? $record->issued_date?->toDateString();
        if (! empty($data['valid_until']) && $issuedDate && $data['valid_until'] < $issuedDate) {
            abort(422, 'The expiry date must be on or after the issue date.');
        }
        $record->update($data);

        return response()->json(['status' => 'success', 'data' => $this->serializeRecord($record->fresh())]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $companyId = $this->actorCompanyId($request);
        $record = $this->record($id, $companyId);
        $record->delete();

        return response()->json(['status' => 'success', 'message' => 'Medical record archived.']);
    }

    public function uploadDocument(Request $request, string $id): JsonResponse
    {
        $companyId = $this->actorCompanyId($request);
        $record = $this->record($id, $companyId);
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $file = $data['file'];
        $documentId = (string) Str::uuid();
        $path = 'medical-records/'.$companyId.'/'.$record->id.'/'.$documentId.'.enc';
        $encrypted = Crypt::encryptString($file->get());
        abort_unless(Storage::disk('local')->put($path, $encrypted), 500, 'The private medical document could not be stored.');

        try {
            $record->documents()->create([
                'document_type' => 'medical_record',
                'document_number' => $record->record_number.'-'.Str::lower(Str::random(8)),
                'disk' => 'local',
                'path' => $path,
                'file_name' => $documentId.'.enc',
                'file_type' => 'application/octet-stream',
                'file_size' => $file->getSize(),
                'status' => 'verified',
                'created_user_id' => $request->user()->id,
                'metadata' => [
                    'original_name' => Crypt::encryptString($file->getClientOriginalName()),
                    'mime_type' => $file->getMimeType(),
                    'description' => isset($data['description']) ? Crypt::encryptString($data['description']) : null,
                ],
            ]);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        return response()->json(['status' => 'success', 'data' => $this->serializeRecord($record->fresh())], 201);
    }

    public function downloadDocument(Request $request, string $id): StreamedResponse
    {
        $companyId = $this->actorCompanyId($request);
        $record = $this->record($id, $companyId);
        $document = $record->documents()->orderBy('created_at')->firstOrFail();
        abort_unless($document->disk === 'local' && Storage::disk('local')->exists($document->path), 404, 'Medical document is unavailable.');

        $content = Crypt::decryptString(Storage::disk('local')->get($document->path));
        $metadata = $document->metadata ?? [];
        $filename = isset($metadata['original_name']) ? Crypt::decryptString($metadata['original_name']) : 'medical-document';
        $mime = $metadata['mime_type'] ?? 'application/octet-stream';
        $this->auditRecordView($request, $record, $companyId, 'medical_document_downloaded');

        return response()->streamDownload(static function () use ($content): void {
            echo $content;
        }, basename($filename), ['Content-Type' => $mime, 'Cache-Control' => 'private, no-store']);
    }

    private function actorCompanyId(Request $request): string
    {
        $companyId = app(SingleCompanyScope::class)->activeDefaultCompany()?->id;
        abort_unless($companyId, 403, 'Medical records require one active default company.');
        $actor = app(StaffAccessService::class)->currentActorStaff($request->user());
        abort_unless($actor && (string) $actor->company_id === (string) $companyId, 403, 'Medical records are available only in the active default company context.');

        return (string) $companyId;
    }

    private function authorizeSubject(string $type, string $id, string $companyId): void
    {
        $exists = match ($type) {
            'staff' => Staff::query()->whereKey($id)->where('company_id', $companyId)->exists(),
            'vehicle' => DB::table('vehicles')->where('id', $id)->where('company_id', $companyId)->whereNull('deleted_at')->exists(),
            default => false,
        };
        abort_unless($exists, 404, 'The selected medical-record subject is not available in the default company.');
    }

    private function record(string $id, string $companyId): MedicalRecord
    {
        return MedicalRecord::query()->where('company_id', $companyId)->whereKey($id)->firstOrFail();
    }

    private function serializeRecord(MedicalRecord $record): array
    {
        $record->loadMissing('category');

        return [
            'id' => (string) $record->id,
            'company_id' => (string) $record->company_id,
            'subject_type' => $record->subject_type,
            'subject_id' => (string) $record->subject_id,
            'subject_name' => $this->subjectName($record),
            'medical_category_id' => (string) $record->medical_category_id,
            'category' => $record->category?->only(['id', 'code', 'name']),
            'record_type' => $record->category?->code,
            'title' => $record->title,
            'description' => $record->description,
            'record_number' => $record->record_number,
            'issued_date' => $record->issued_date?->toDateString(),
            'issue_date' => $record->issued_date?->toDateString(),
            'examination_date' => $record->issued_date?->toDateString(),
            'valid_from' => $record->issued_date?->toDateString(),
            'valid_until' => $record->valid_until?->toDateString(),
            'expiry_date' => $record->valid_until?->toDateString(),
            'issuing_authority' => $record->issuing_authority,
            'provider_name' => $record->issuing_authority,
            'status' => $record->status,
            'documents' => $record->documents()->orderBy('created_at')->get()->map(function ($document): array {
                $metadata = $document->metadata ?? [];

                return [
                    'id' => (string) $document->id,
                    'file_name' => isset($metadata['original_name']) ? Crypt::decryptString($metadata['original_name']) : 'Medical document',
                    'file_path' => null,
                    'file_type' => $metadata['mime_type'] ?? 'application/octet-stream',
                    'file_size' => (int) $document->file_size,
                    'uploaded_at' => $document->created_at?->toISOString(),
                ];
            })->values(),
            'created_at' => $record->created_at?->toISOString(),
            'updated_at' => $record->updated_at?->toISOString(),
        ];
    }

    private function subjectName(MedicalRecord $record): string
    {
        $subject = match ($record->subject_type) {
            'staff' => DB::table('staff')->leftJoin('users', 'users.id', '=', 'staff.user_id')
                ->where('staff.id', $record->subject_id)->first(['staff.code', 'users.first_name', 'users.last_name']),
            'driver' => DB::table('drivers')->leftJoin('users', 'users.id', '=', 'drivers.user_id')
                ->where('drivers.id', $record->subject_id)->first(['drivers.code', 'users.first_name', 'users.last_name']),
            'vehicle' => DB::table('vehicles')->where('id', $record->subject_id)->first(['registration_no', 'license_plate']),
            default => null,
        };

        if (! $subject) return 'Unavailable subject';
        $name = trim(($subject->first_name ?? '').' '.($subject->last_name ?? ''));

        return $name !== '' ? $name : ($subject->code ?? $subject->registration_no ?? $subject->license_plate ?? 'Unnamed subject');
    }

    private function auditListView(Request $request, string $companyId): void
    {
        activity('hr-sensitive-data')->causedBy($request->user())->event('medical_record_list_viewed')
            ->withProperties(['company_id' => $companyId])->log('medical_record_list_viewed');
    }

    private function auditRecordView(Request $request, MedicalRecord $record, string $companyId, string $event = 'medical_record_viewed'): void
    {
        activity('hr-sensitive-data')->causedBy($request->user())->performedOn($record)->event($event)
            ->withProperties(['company_id' => $companyId])->log($event);
    }
}
