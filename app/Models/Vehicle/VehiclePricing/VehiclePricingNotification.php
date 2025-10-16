<?php

namespace App\Models\Vehicle\VehiclePricing;

use App\Models\BaseModel;
use App\Models\User;
use App\Traits\UUID;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Pricing Notifications Model
 * 
 * Manages notifications related to pricing changes and operations
 */
class VehiclePricingNotification extends BaseModel
{
    use UUID, SoftDeletes;

    protected $table = 'vehicle_pricing_notifications';

    protected $fillable = [
        'notification_type',
        'title',
        'message',
        'data',
        'related_id',
        'related_type',
        'user_id',
        'is_read',
        'read_at',
        'priority',
        'action_buttons'
    ];

    protected $casts = [
        'data' => 'json',
        'action_buttons' => 'json',
        'is_read' => 'boolean',
        'read_at' => 'datetime'
    ];

    /**
     * Get the user associated with this notification
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Scope to filter unread notifications
     */
    public function scopeUnread($query)
    {
        return $query->where('is_read', false);
    }

    /**
     * Scope to filter by priority
     */
    public function scopeByPriority($query, $priority)
    {
        return $query->where('priority', $priority);
    }

    /**
     * Scope to filter by notification type
     */
    public function scopeByType($query, $type)
    {
        return $query->where('notification_type', $type);
    }

    /**
     * Mark notification as read
     */
    public function markAsRead()
    {
        $this->update([
            'is_read' => true,
            'read_at' => now()
        ]);
    }

    /**
     * Get priority label
     */
    public function getPriorityLabelAttribute()
    {
        return match($this->priority) {
            'low' => 'Low',
            'normal' => 'Normal',
            'high' => 'High',
            'urgent' => 'Urgent',
            default => 'Normal'
        };
    }

    /**
     * Get priority color
     */
    public function getPriorityColorAttribute()
    {
        return match($this->priority) {
            'low' => 'text-blue-600',
            'normal' => 'text-gray-600',
            'high' => 'text-orange-600',
            'urgent' => 'text-red-600',
            default => 'text-gray-600'
        };
    }

    /**
     * Create a pricing notification
     */
    public static function createNotification(array $data)
    {
        return self::create([
            'notification_type' => $data['notification_type'],
            'title' => $data['title'],
            'message' => $data['message'],
            'data' => $data['data'] ?? null,
            'related_id' => $data['related_id'] ?? null,
            'related_type' => $data['related_type'] ?? null,
            'user_id' => $data['user_id'],
            'priority' => $data['priority'] ?? 'normal',
            'action_buttons' => $data['action_buttons'] ?? null
        ]);
    }

    /**
     * Create price change notification
     */
    public static function createPriceChangeNotification($userId, $pricingHistoryId, $data)
    {
        return self::createNotification([
            'notification_type' => 'price_change',
            'title' => 'Price Change Notification',
            'message' => "Price has been {$data['change_type']} for {$data['vehicle_group']} - {$data['service_type']}",
            'data' => $data,
            'related_id' => $pricingHistoryId,
            'related_type' => 'pricing_history',
            'user_id' => $userId,
            'priority' => 'normal'
        ]);
    }

    /**
     * Create bulk operation notification
     */
    public static function createBulkOperationNotification($userId, $operationId, $data)
    {
        return self::createNotification([
            'notification_type' => 'bulk_operation',
            'title' => 'Bulk Operation Update',
            'message' => "Bulk operation '{$data['operation_name']}' has been {$data['status']}",
            'data' => $data,
            'related_id' => $operationId,
            'related_type' => 'bulk_operation',
            'user_id' => $userId,
            'priority' => $data['status'] === 'failed' ? 'high' : 'normal'
        ]);
    }
}
