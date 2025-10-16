<?php

namespace App\Models\Booking;

use App\Models\BaseModel;
use App\Models\User;
use App\Models\Vehicle\Vehicle;
use App\Enums\QCStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Booking QC Model
 * 
 * Tracks Quality Control inspection of returned vehicles
 */
class BookingQC extends BaseModel
{
    protected $table = 'booking_qcs';

    protected $fillable = [
        'booking_id',
        'vehicle_id',
        'dispatch_id',
        'qc_status',
        'inspector_id',
        'inspection_started_at',
        'inspection_completed_at',
        'interior_condition',
        'exterior_condition',
        'mechanical_condition',
        'cleanliness_rating',
        'fuel_level',
        'mileage',
        'damages_found',
        'issues_reported',
        'repair_required',
        'estimated_repair_cost',
        'repair_notes',
        'qc_notes',
        'photos',
        'passed_inspection',
        'requires_maintenance',
        'next_maintenance_due',
        'created_user_id',
        'updated_user_id',
    ];

    protected $casts = [
        'qc_status' => QCStatus::class,
        'inspection_started_at' => 'datetime',
        'inspection_completed_at' => 'datetime',
        'interior_condition' => 'array',
        'exterior_condition' => 'array',
        'mechanical_condition' => 'array',
        'cleanliness_rating' => 'integer',
        'fuel_level' => 'decimal:1',
        'mileage' => 'integer',
        'damages_found' => 'array',
        'issues_reported' => 'array',
        'repair_required' => 'boolean',
        'estimated_repair_cost' => 'decimal:2',
        'photos' => 'array',
        'passed_inspection' => 'boolean',
        'requires_maintenance' => 'boolean',
        'next_maintenance_due' => 'date',
    ];

    /**
     * Get the booking this QC belongs to
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * Get the vehicle being inspected
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /**
     * Get the dispatch record
     */
    public function dispatch(): BelongsTo
    {
        return $this->belongsTo(BookingDispatch::class, 'dispatch_id');
    }

    /**
     * Get the inspector
     */
    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspector_id');
    }

    /**
     * Get QC repair items
     */
    public function repairItems(): HasMany
    {
        return $this->hasMany(BookingQCRepairItem::class, 'qc_id');
    }

    /**
     * Start QC inspection
     */
    public function startInspection(string $inspectorId): void
    {
        $this->update([
            'qc_status' => QCStatus::IN_PROGRESS,
            'inspector_id' => $inspectorId,
            'inspection_started_at' => now(),
        ]);
    }

    /**
     * Complete QC inspection
     */
    public function completeInspection(array $inspectionData): void
    {
        $hasIssues = !empty($inspectionData['damages_found']) || 
                    !empty($inspectionData['issues_reported']) ||
                    $inspectionData['repair_required'] ?? false;

        $this->update([
            'qc_status' => $hasIssues ? QCStatus::ISSUES_FOUND : QCStatus::COMPLETED,
            'inspection_completed_at' => now(),
            'interior_condition' => $inspectionData['interior_condition'] ?? null,
            'exterior_condition' => $inspectionData['exterior_condition'] ?? null,
            'mechanical_condition' => $inspectionData['mechanical_condition'] ?? null,
            'cleanliness_rating' => $inspectionData['cleanliness_rating'] ?? null,
            'fuel_level' => $inspectionData['fuel_level'] ?? null,
            'mileage' => $inspectionData['mileage'] ?? null,
            'damages_found' => $inspectionData['damages_found'] ?? null,
            'issues_reported' => $inspectionData['issues_reported'] ?? null,
            'repair_required' => $inspectionData['repair_required'] ?? false,
            'estimated_repair_cost' => $inspectionData['estimated_repair_cost'] ?? null,
            'repair_notes' => $inspectionData['repair_notes'] ?? null,
            'qc_notes' => $inspectionData['qc_notes'] ?? null,
            'photos' => $inspectionData['photos'] ?? null,
            'passed_inspection' => !$hasIssues,
            'requires_maintenance' => $inspectionData['requires_maintenance'] ?? false,
            'next_maintenance_due' => $inspectionData['next_maintenance_due'] ?? null,
        ]);
    }

    /**
     * Mark repair as required
     */
    public function requireRepair(array $repairData = []): void
    {
        $this->update([
            'qc_status' => QCStatus::REPAIR_REQUIRED,
            'repair_required' => true,
            'estimated_repair_cost' => $repairData['estimated_cost'] ?? null,
            'repair_notes' => $repairData['notes'] ?? null,
        ]);
    }

    /**
     * Mark QC as completed after repairs
     */
    public function markCompleted(): void
    {
        $this->update([
            'qc_status' => QCStatus::COMPLETED,
            'passed_inspection' => true,
        ]);
    }

    /**
     * Check if QC is in progress
     */
    public function isInProgress(): bool
    {
        return $this->qc_status === QCStatus::IN_PROGRESS;
    }

    /**
     * Check if QC is completed
     */
    public function isCompleted(): bool
    {
        return $this->qc_status === QCStatus::COMPLETED;
    }

    /**
     * Check if repair is required
     */
    public function needsRepair(): bool
    {
        return $this->repair_required || $this->qc_status === QCStatus::REPAIR_REQUIRED;
    }

    /**
     * Get QC summary
     */
    public function getQCSummary(): array
    {
        return [
            'qc_id' => $this->id,
            'booking_id' => $this->booking_id,
            'vehicle' => $this->vehicle->license_plate ?? 'N/A',
            'status' => $this->qc_status->getDisplayName(),
            'inspector' => $this->inspector->name ?? 'N/A',
            'started_at' => $this->inspection_started_at?->format('Y-m-d H:i'),
            'completed_at' => $this->inspection_completed_at?->format('Y-m-d H:i'),
            'passed_inspection' => $this->passed_inspection,
            'repair_required' => $this->repair_required,
            'estimated_repair_cost' => $this->estimated_repair_cost,
            'cleanliness_rating' => $this->cleanliness_rating,
            'issues_count' => count($this->issues_reported ?? []),
            'damages_count' => count($this->damages_found ?? []),
        ];
    }
}
