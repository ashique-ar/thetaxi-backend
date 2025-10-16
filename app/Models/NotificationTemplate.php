<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\UUID;

/**
 * Notification Template Model
 * 
 * Represents templates for various types of notifications (email, SMS, push notifications).
 * Contains the structure and content for automated notifications sent by the system.
 * 
 * @property string $id Primary key (UUID)
 * @property string $code Unique template code identifier
 * @property string $channel Notification channel (email, sms, push, etc.)
 * @property string|null $subject Email subject (for email notifications)
 * @property string $body Template body content with placeholders
 * @property bool $is_active Whether template is active and can be used
 * @property string|null $created_user_id ID of user who created this record
 * @property string|null $updated_user_id ID of user who last updated this record
 * @property \Carbon\Carbon|null $created_at Record creation timestamp
 * @property \Carbon\Carbon|null $updated_at Record last update timestamp
 * @property \Carbon\Carbon|null $deleted_at Soft deletion timestamp
 * 
 * @property-read \Illuminate\Database\Eloquent\Collection<NotificationLog> $logs Notification logs using this template
 * @property-read User|null $createdBy User who created this record
 * @property-read User|null $updatedBy User who last updated this record
 */
class NotificationTemplate extends BaseModel
{
    

    /**
     * The table associated with the model.
     */
    protected $table = 'notification_templates';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'code',
        'channel',
        'subject',
        'body',
        'is_active',
        'created_user_id',
        'updated_user_id',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Get all notification logs using this template.
     */
    public function logs(): HasMany
    {
        return $this->hasMany(NotificationLog::class, 'template_id');
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
