<?php

namespace App\Models\Booking;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Booking QC Repair Item Model
 * 
 * Tracks individual repair items found during QC inspection
 */
class BookingQCRepairItem extends BaseModel
{
    protected $table = 'booking_qc_repair_items';

    protected $fillable = [
        'qc_id',
        'item_type',
        'description',
        'location',
        'severity',
        'estimated_cost',
        'actual_cost',
        'repair_status',
        'repaired_at',
        'repaired_by',
        'repair_notes',
        'photos',
        'created_user_id',
        'updated_user_id',
    ];

    protected $casts = [
        'estimated_cost' => 'decimal:2',
        'actual_cost' => 'decimal:2',
        'repaired_at' => 'datetime',
        'photos' => 'array',
    ];

    /**
     * Get the QC record this repair item belongs to
     */
    public function qc(): BelongsTo
    {
        return $this->belongsTo(BookingQC::class, 'qc_id');
    }

    /**
     * Get the user who performed the repair
     */
    public function repairedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'repaired_by');
    }

    /**
     * Mark repair item as completed
     */
    public function markRepaired(string $userId, array $repairData = []): void
    {
        $this->update([
            'repair_status' => 'completed',
            'repaired_at' => now(),
            'repaired_by' => $userId,
            'actual_cost' => $repairData['actual_cost'] ?? $this->estimated_cost,
            'repair_notes' => $repairData['notes'] ?? null,
        ]);
    }

    /**
     * Check if repair is completed
     */
    public function isRepaired(): bool
    {
        return $this->repair_status === 'completed';
    }
}
