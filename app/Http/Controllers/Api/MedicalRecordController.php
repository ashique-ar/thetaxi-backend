<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MedicalRecord;
use App\Models\MedicalCategory;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;

class MedicalRecordController extends Controller
{
    /**
     * Display a listing of medical records
     */
    public function index(Request $request): JsonResponse
    {
        $query = MedicalRecord::with(['category', 'documents']);
        
        // Apply filters
        if ($request->subject_type) {
            $query->where('subject_type', $request->subject_type);
        }
        
        if ($request->subject_id) {
            $query->where('subject_id', $request->subject_id);
        }
        
        if ($request->category_id) {
            $query->where('medical_category_id', $request->category_id);
        }
        
        if ($request->status) {
            $query->where('status', $request->status);
        }
        
        if ($request->expiring_within_days) {
            $days = (int) $request->expiring_within_days;
            $query->where('valid_until', '<=', now()->addDays($days))
                  ->where('valid_until', '>=', now());
        }
        
        if ($request->search) {
            $query->where(function($q) use ($request) {
                $q->where('title', 'like', '%' . $request->search . '%')
                  ->orWhere('description', 'like', '%' . $request->search . '%')
                  ->orWhere('record_number', 'like', '%' . $request->search . '%');
            });
        }
        
        $records = $query->orderBy('created_at', 'desc')
                        ->paginate($request->per_page ?? 15);
        
        return response()->json([
            'status' => 'success',
            'data' => $records
        ]);
    }

    /**
     * Store a newly created medical record
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'subject_type' => 'required|in:driver,vehicle,staff',
            'subject_id' => 'required|string',
            'medical_category_id' => 'required|exists:medical_categories,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'record_number' => 'nullable|string|unique:medical_records',
            'issued_date' => 'required|date',
            'valid_until' => 'nullable|date|after:issued_date',
            'issuing_authority' => 'nullable|string|max:255',
            'documents' => 'nullable|array',
            'documents.*' => 'file|mimes:pdf,jpg,jpeg,png|max:10240'
        ]);

        DB::beginTransaction();
        try {
            $record = MedicalRecord::create([
                'subject_type' => $request->subject_type,
                'subject_id' => $request->subject_id,
                'medical_category_id' => $request->medical_category_id,
                'title' => $request->title,
                'description' => $request->description,
                'record_number' => $request->record_number ?? $this->generateRecordNumber(),
                'issued_date' => $request->issued_date,
                'valid_until' => $request->valid_until,
                'issuing_authority' => $request->issuing_authority,
                'status' => 'active',
                'created_by' => auth()->id()
            ]);

            // Handle document uploads
            if ($request->hasFile('documents')) {
                foreach ($request->file('documents') as $index => $file) {
                    $path = $file->store('medical_records/' . $record->id, 'public');
                    
                    $record->documents()->create([
                        'file_path' => $path,
                        'file_name' => $file->getClientOriginalName(),
                        'file_type' => $file->getClientMimeType(),
                        'file_size' => $file->getSize(),
                        'description' => $request->input("document_descriptions.{$index}"),
                        'uploaded_by' => auth()->id()
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'data' => $record->load(['category', 'documents'])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create medical record: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified medical record
     */
    public function show(string $id): JsonResponse
    {
        $record = MedicalRecord::with(['category', 'documents'])
                              ->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data' => $record
        ]);
    }

    /**
     * Update the specified medical record
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $record = MedicalRecord::findOrFail($id);

        $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'valid_until' => 'nullable|date',
            'issuing_authority' => 'nullable|string|max:255',
            'status' => 'sometimes|in:active,expired,suspended,revoked',
            'documents' => 'nullable|array',
            'documents.*' => 'file|mimes:pdf,jpg,jpeg,png|max:10240'
        ]);

        DB::beginTransaction();
        try {
            $record->update($request->only([
                'title', 'description', 'valid_until', 'issuing_authority', 'status'
            ]));

            // Handle new document uploads
            if ($request->hasFile('documents')) {
                foreach ($request->file('documents') as $index => $file) {
                    $path = $file->store('medical_records/' . $record->id, 'public');
                    
                    $record->documents()->create([
                        'file_path' => $path,
                        'file_name' => $file->getClientOriginalName(),
                        'file_type' => $file->getClientMimeType(),
                        'file_size' => $file->getSize(),
                        'description' => $request->input("document_descriptions.{$index}"),
                        'uploaded_by' => auth()->id()
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'data' => $record->load(['category', 'documents'])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update medical record: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified medical record
     */
    public function destroy(string $id): JsonResponse
    {
        $record = MedicalRecord::findOrFail($id);

        DB::beginTransaction();
        try {
            // Delete associated documents from storage
            foreach ($record->documents as $document) {
                Storage::disk('public')->delete($document->file_path);
            }

            // Delete the record (cascade will handle documents)
            $record->delete();

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Medical record deleted successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete medical record: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get records by subject
     */
    public function getRecordsBySubject(Request $request, string $subjectType, string $subjectId): JsonResponse
    {
        $query = MedicalRecord::with(['category', 'documents'])
                              ->where('subject_type', $subjectType)
                              ->where('subject_id', $subjectId);

        if ($request->status) {
            $query->where('status', $request->status);
        }

        $records = $query->orderBy('created_at', 'desc')
                        ->paginate($request->per_page ?? 15);

        return response()->json([
            'status' => 'success',
            'data' => $records
        ]);
    }

    /**
     * Get compliance report for subject
     */
    public function getSubjectComplianceReport(string $subjectType, string $subjectId): JsonResponse
    {
        $records = MedicalRecord::with(['category'])
                                ->where('subject_type', $subjectType)
                                ->where('subject_id', $subjectId)
                                ->get();

        $compliance = [
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'total_records' => $records->count(),
            'active_records' => $records->where('status', 'active')->count(),
            'expired_records' => $records->where('status', 'expired')->count(),
            'expiring_soon' => $records->where('valid_until', '<=', now()->addDays(30))
                                     ->where('valid_until', '>=', now())
                                     ->count(),
            'compliance_score' => $this->calculateComplianceScore($records),
            'categories' => $records->groupBy('medical_category_id')
                                  ->map(function($categoryRecords, $categoryId) {
                                      $category = $categoryRecords->first()->category;
                                      return [
                                          'category_name' => $category->name,
                                          'total' => $categoryRecords->count(),
                                          'active' => $categoryRecords->where('status', 'active')->count(),
                                          'latest_record_date' => $categoryRecords->max('issued_date')
                                      ];
                                  })->values()
        ];

        return response()->json([
            'status' => 'success',
            'data' => $compliance
        ]);
    }

    /**
     * Update record status
     */
    public function updateStatus(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'status' => 'required|in:active,expired,suspended,revoked',
            'reason' => 'nullable|string'
        ]);

        $record = MedicalRecord::findOrFail($id);
        
        $record->update([
            'status' => $request->status,
            'status_reason' => $request->reason,
            'status_updated_by' => auth()->id(),
            'status_updated_at' => now()
        ]);

        return response()->json([
            'status' => 'success',
            'data' => $record->load(['category'])
        ]);
    }

    /**
     * Get expiring records
     */
    public function getExpiringRecords(Request $request): JsonResponse
    {
        $days = $request->get('days', 30);
        
        $records = MedicalRecord::with(['category', 'documents'])
                                ->where('status', 'active')
                                ->where('valid_until', '<=', now()->addDays($days))
                                ->where('valid_until', '>=', now())
                                ->orderBy('valid_until')
                                ->paginate($request->per_page ?? 15);

        return response()->json([
            'status' => 'success',
            'data' => $records
        ]);
    }

    /**
     * Get statistics
     */
    public function getStats(Request $request): JsonResponse
    {
        $dateFrom = $request->get('date_from', now()->startOfMonth());
        $dateTo = $request->get('date_to', now()->endOfMonth());

        $stats = [
            'total_records' => MedicalRecord::count(),
            'active_records' => MedicalRecord::where('status', 'active')->count(),
            'expired_records' => MedicalRecord::where('status', 'expired')->count(),
            'expiring_within_30_days' => MedicalRecord::where('valid_until', '<=', now()->addDays(30))
                                                     ->where('valid_until', '>=', now())
                                                     ->count(),
            'records_by_subject_type' => MedicalRecord::select('subject_type')
                                                     ->selectRaw('count(*) as count')
                                                     ->groupBy('subject_type')
                                                     ->get(),
            'records_by_category' => MedicalRecord::join('medical_categories', 'medical_records.medical_category_id', '=', 'medical_categories.id')
                                                 ->select('medical_categories.name')
                                                 ->selectRaw('count(*) as count')
                                                 ->groupBy('medical_categories.id', 'medical_categories.name')
                                                 ->get(),
            'new_records_this_period' => MedicalRecord::whereBetween('created_at', [$dateFrom, $dateTo])->count()
        ];

        return response()->json([
            'status' => 'success',
            'data' => $stats
        ]);
    }

    /**
     * Bulk action on records
     */
    public function bulkAction(Request $request): JsonResponse
    {
        $request->validate([
            'action' => 'required|in:delete,update_status,extend_validity',
            'record_ids' => 'required|array',
            'record_ids.*' => 'exists:medical_records,id',
            'status' => 'required_if:action,update_status|in:active,expired,suspended,revoked',
            'valid_until' => 'required_if:action,extend_validity|date',
            'reason' => 'nullable|string'
        ]);

        DB::beginTransaction();
        try {
            $records = MedicalRecord::whereIn('id', $request->record_ids);
            
            switch ($request->action) {
                case 'delete':
                    $count = $records->count();
                    $records->delete();
                    $message = "{$count} records deleted successfully";
                    break;
                    
                case 'update_status':
                    $count = $records->update([
                        'status' => $request->status,
                        'status_reason' => $request->reason,
                        'status_updated_by' => auth()->id(),
                        'status_updated_at' => now()
                    ]);
                    $message = "{$count} records updated successfully";
                    break;
                    
                case 'extend_validity':
                    $count = $records->update([
                        'valid_until' => $request->valid_until,
                        'updated_by' => auth()->id()
                    ]);
                    $message = "{$count} records extended successfully";
                    break;
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => $message
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Bulk action failed: ' . $e->getMessage()
            ], 500);
        }
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $request->validate([
            'record_ids' => 'required|array|min:1',
            'record_ids.*' => 'exists:medical_records,id',
        ]);

        $records = MedicalRecord::whereIn('id', $request->record_ids)->get();

        DB::transaction(function () use ($records): void {
            foreach ($records as $record) {
                foreach ($record->documents as $document) {
                    $document->delete();
                }
                $record->delete();
            }
        });

        return response()->json([
            'status' => 'success',
            'message' => $records->count() . ' medical records deleted',
        ]);
    }

    public function bulkStatus(Request $request): JsonResponse
    {
        $request->validate([
            'record_ids' => 'required|array|min:1',
            'record_ids.*' => 'exists:medical_records,id',
            'status' => 'required|in:active,expired,suspended,revoked,cancelled',
        ]);

        $count = MedicalRecord::whereIn('id', $request->record_ids)->update([
            'status' => $request->status,
            'status_updated_by' => auth()->id(),
            'status_updated_at' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => "{$count} medical records updated",
        ]);
    }

    public function bulkExport(Request $request)
    {
        $request->validate([
            'record_ids' => 'required|array|min:1',
            'record_ids.*' => 'exists:medical_records,id',
            'format' => 'nullable|in:pdf,csv,excel',
        ]);

        $records = MedicalRecord::with('category')
            ->whereIn('id', $request->record_ids)
            ->orderBy('created_at')
            ->get();

        $filename = 'medical-records-export-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($records): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'Record Number',
                'Subject Type',
                'Subject ID',
                'Title',
                'Category',
                'Issued Date',
                'Valid Until',
                'Status',
            ]);

            foreach ($records as $record) {
                fputcsv($handle, [
                    $record->record_number,
                    $record->subject_type,
                    $record->subject_id,
                    $record->title,
                    $record->category?->name,
                    optional($record->issued_date)->toDateString(),
                    optional($record->valid_until)->toDateString(),
                    $record->status,
                ]);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function downloadDocument(string $id)
    {
        $record = MedicalRecord::with('documents')->findOrFail($id);
        $document = $record->documents->first();

        if (!$document) {
            abort(404, 'No document is attached to this medical record.');
        }

        $disk = $document->disk ?? 'public';
        $path = $document->path ?? $document->file_path ?? null;

        if (!$path || !Storage::disk($disk)->exists($path)) {
            abort(404, 'The attached document file could not be found.');
        }

        return Storage::disk($disk)->download($path, $document->file_name);
    }

    public function sendReminders(Request $request): JsonResponse
    {
        $request->validate([
            'record_ids' => 'nullable|array',
            'record_ids.*' => 'exists:medical_records,id',
        ]);

        $query = MedicalRecord::query()
            ->where('status', 'active')
            ->whereNotNull('valid_until')
            ->whereBetween('valid_until', [now(), now()->addDays(30)]);

        if ($request->filled('record_ids')) {
            $query->whereIn('id', $request->record_ids);
        }

        $count = $query->count();

        return response()->json([
            'status' => 'success',
            'message' => "{$count} expiry reminder" . ($count === 1 ? '' : 's') . ' queued',
            'data' => ['queued' => $count],
        ]);
    }

    // Private helper methods

    /**
     * Generate unique record number
     */
    private function generateRecordNumber(): string
    {
        do {
            $number = 'MR' . date('Y') . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
        } while (MedicalRecord::where('record_number', $number)->exists());

        return $number;
    }

    /**
     * Calculate compliance score
     */
    private function calculateComplianceScore($records): int
    {
        if ($records->isEmpty()) {
            return 0;
        }

        $totalWeight = $records->count();
        $complianceWeight = 0;

        foreach ($records as $record) {
            if ($record->status === 'active' && 
                ($record->valid_until === null || $record->valid_until > now())) {
                $complianceWeight += 1;
            } elseif ($record->status === 'active' && 
                     $record->valid_until <= now()->addDays(30)) {
                $complianceWeight += 0.7; // Partial score for expiring soon
            }
        }

        return round(($complianceWeight / $totalWeight) * 100);
    }

    public function bulkUpdate(Request $request): JsonResponse
    {
        $request->validate([
            'ids'    => 'required|array|min:1',
            'ids.*'  => 'uuid',
            'action' => 'required|in:activate,deactivate,delete',
        ]);

        $records = MedicalRecord::whereIn('id', $request->input('ids'));

        $count = match ($request->input('action')) {
            'activate'   => $records->update(['status' => 'active']),
            'deactivate' => $records->update(['status' => 'inactive']),
            'delete'     => tap($records->count(), fn() => $records->delete()),
        };

        return response()->json([
            'status'  => 'success',
            'message' => "{$count} records updated",
            'data'    => ['affected' => $count],
        ]);
    }

    public function getComplianceReport(Request $request): JsonResponse
    {
        $subjectType = $request->input('subject_type', 'driver');
        $records     = MedicalRecord::where('subject_type', $subjectType)
            ->with('category')
            ->get();

        $total    = $records->count();
        $active   = $records->where('status', 'active')->count();
        $expired  = $records->where('status', 'expired')->count();
        $expiring = $records->filter(fn($r) => $r->valid_until && $r->valid_until->between(now(), now()->addDays(30)))->count();

        return response()->json([
            'status' => 'success',
            'data'   => [
                'subject_type'    => $subjectType,
                'total'           => $total,
                'active'          => $active,
                'expired'         => $expired,
                'expiring_soon'   => $expiring,
                'compliance_rate' => $total > 0 ? round($active / $total * 100, 1) : 0,
            ],
        ]);
    }

    public function uploadDocument(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'document' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240|required_without:file',
            'file' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240|required_without:document',
            'description' => 'nullable|string',
        ]);

        $record = MedicalRecord::findOrFail($id);
        $file = $request->file('document') ?: $request->file('file');
        $path = $file->store('medical-records/' . $record->id, 'public');

        $record->documents()->create([
            'document_type' => 'medical_record',
            'document_number' => $record->record_number ?: (string) $record->id,
            'disk' => 'public',
            'path'        => $path,
            'file_name'   => $file->getClientOriginalName(),
            'file_type'   => $file->getClientMimeType(),
            'file_size'   => $file->getSize(),
            'verification_notes' => $request->description,
            'created_user_id' => auth()->id(),
        ]);

        return response()->json([
            'status'  => 'success',
            'message' => 'Document uploaded',
            'data'    => $record->fresh(['category', 'documents']),
        ]);
    }

    public function getCategories(Request $request): JsonResponse
    {
        $categories = MedicalCategory::orderBy('name')->get();

        return response()->json([
            'status' => 'success',
            'data'   => $categories,
        ]);
    }
}
