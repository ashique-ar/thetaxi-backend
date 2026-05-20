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
        $query = MedicalRecord::with(['category', 'subject']);
        
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
        $record = MedicalRecord::with(['category', 'documents', 'subject'])
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
        
        $records = MedicalRecord::with(['category', 'subject'])
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
}
