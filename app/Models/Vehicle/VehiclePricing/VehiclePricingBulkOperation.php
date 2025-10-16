<?php

namespace App\Models\Vehicle\VehiclePricing;

use App\Models\BaseModel;
use App\Models\User;
use App\Traits\UUID;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Pricing Bulk Operations Model
 * 
 * Manages bulk pricing operations like bulk updates, imports, and copies
 */
class VehiclePricingBulkOperation extends BaseModel
{
    use UUID, SoftDeletes;

    protected $table = 'vehicle_pricing_bulk_operations';

    protected $fillable = [
        'operation_type',
        'operation_name',
        'description',
        'operation_data',
        'affected_records',
        'status',
        'total_records',
        'processed_records',
        'successful_records',
        'failed_records',
        'errors',
        'initiated_by',
        'started_at',
        'completed_at'
    ];

    protected $casts = [
        'operation_data' => 'json',
        'affected_records' => 'json',
        'errors' => 'json',
        'started_at' => 'datetime',
        'completed_at' => 'datetime'
    ];

    /**
     * Get the user who initiated the operation
     */
    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    /**
     * Scope to filter by operation type
     */
    public function scopeByOperationType($query, $operationType)
    {
        return $query->where('operation_type', $operationType);
    }

    /**
     * Scope to filter by status
     */
    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Get progress percentage
     */
    public function getProgressPercentageAttribute()
    {
        if ($this->total_records == 0) {
            return 0;
        }
        return round(($this->processed_records / $this->total_records) * 100, 2);
    }

    /**
     * Get success rate percentage
     */
    public function getSuccessRateAttribute()
    {
        if ($this->processed_records == 0) {
            return 0;
        }
        return round(($this->successful_records / $this->processed_records) * 100, 2);
    }

    /**
     * Get duration in seconds
     */
    public function getDurationAttribute()
    {
        if (!$this->started_at || !$this->completed_at) {
            return null;
        }
        return $this->completed_at->diffInSeconds($this->started_at);
    }

    /**
     * Check if operation is running
     */
    public function isRunning()
    {
        return in_array($this->status, ['pending', 'in_progress']);
    }

    /**
     * Check if operation is completed
     */
    public function isCompleted()
    {
        return $this->status === 'completed';
    }

    /**
     * Check if operation has failed
     */
    public function hasFailed()
    {
        return $this->status === 'failed';
    }

    /**
     * Mark operation as started
     */
    public function markAsStarted()
    {
        $this->update([
            'status' => 'in_progress',
            'started_at' => now()
        ]);
    }

    /**
     * Mark operation as completed
     */
    public function markAsCompleted()
    {
        $this->update([
            'status' => 'completed',
            'completed_at' => now()
        ]);
    }

    /**
     * Mark operation as failed
     */
    public function markAsFailed($errors = null)
    {
        $this->update([
            'status' => 'failed',
            'completed_at' => now(),
            'errors' => $errors
        ]);
    }

    /**
     * Update progress
     */
    public function updateProgress($processed, $successful, $failed, $errors = null)
    {
        $this->update([
            'processed_records' => $processed,
            'successful_records' => $successful,
            'failed_records' => $failed,
            'errors' => $errors
        ]);
    }

    /**
     * Get operation type label
     */
    public function getOperationTypeLabelAttribute()
    {
        return match($this->operation_type) {
            'bulk_update' => 'Bulk Update',
            'bulk_import' => 'Bulk Import',
            'bulk_copy' => 'Bulk Copy',
            default => 'Unknown Operation'
        };
    }

    /**
     * Get status label
     */
    public function getStatusLabelAttribute()
    {
        return match($this->status) {
            'pending' => 'Pending',
            'in_progress' => 'In Progress',
            'completed' => 'Completed',
            'failed' => 'Failed',
            'cancelled' => 'Cancelled',
            default => 'Unknown'
        };
    }
}
