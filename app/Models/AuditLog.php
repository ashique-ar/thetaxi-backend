<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit Log Model
 * 
 * Records all significant actions performed in the system for audit and compliance purposes.
 * Tracks user activities, entity modifications, and system events.
 * 
 * @property string $id Primary key (UUID)
 * @property string|null $user_id Foreign key to users table (who performed the action)
 * @property string $action Action performed (create, update, delete, view, etc.)
 * @property string $entity Entity type affected (User, Booking, Vehicle, etc.)
 * @property string|null $entity_id ID of the specific entity affected
 * @property \Carbon\Carbon $timestamp When the action occurred
 * @property array|null $details Additional details about the action as JSON
 * @property string|null $created_user_id ID of user who created this record
 * @property string|null $updated_user_id ID of user who last updated this record
 * @property \Carbon\Carbon|null $created_at Record creation timestamp
 * @property \Carbon\Carbon|null $updated_at Record last update timestamp
 * @property \Carbon\Carbon|null $deleted_at Soft deletion timestamp
 * 
 * @property-read User|null $user User who performed the action
 * @property-read User|null $createdBy User who created this record
 * @property-read User|null $updatedBy User who last updated this record
 */
class AuditLog extends BaseModel
{
    protected static function booted(): void
    {
        static::creating(function (AuditLog $log): void {
            $details = is_array($log->details) ? $log->details : [];
            if (!array_key_exists('actor_display_snapshot', $details)) {
                $user = $log->user_id ? User::query()->find($log->user_id, ['id', 'first_name', 'last_name']) : null;
                $name = $user ? trim((string) $user->first_name . ' ' . (string) $user->last_name) : null;
                $details['actor_display_snapshot'] = $name !== '' ? $name : ($log->user_id ? null : 'System');
                $details['actor_type_snapshot'] = $log->user_id ? 'user' : 'system';
                $log->details = $details;
            }
        });
    }

    /**
     * The table associated with the model.
     */
    protected $table = 'audit_logs';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'user_id',
        'action',
        'entity',
        'entity_id',
        'timestamp',
        'details',
        'created_user_id',
        'updated_user_id',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'timestamp' => 'datetime',
        'details' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Get the user who performed the action.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the user who created this record.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_user_id');
    }

    /**
     * Get the user who last updated this record.
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_user_id');
    }
}
