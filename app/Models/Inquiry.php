<?php

namespace App\Models;

use App\Models\BaseModel;
use App\Traits\UUID;

/**
 * App\Models\Inquiry
 *
 * @property string $id Primary key (UUID)
 * @property string|null $customer_id Foreign key to customers table (optional)
 * @property string|null $subject Inquiry subject (optional)
 * @property string|null $message Inquiry message (optional)
 * @property string|null $status Inquiry status (optional)
 * @property string|null $priority Inquiry priority (optional)
 * @property string|null $response Response to inquiry (optional)
 * @property \Illuminate\Support\Carbon|null $responded_at Response date (optional)
 * @property string|null $created_user_id ID of user who created this record
 * @property string|null $updated_user_id ID of user who last updated this record
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 *
 * @property-read \App\Models\Customer|null $customer Customer who made the inquiry
 * @property-read \App\Models\User|null $createdBy User who created this record
 * @property-read \App\Models\User|null $updatedBy User who last updated this record
 */
class Inquiry extends BaseModel
{
    

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'customer_id',
        'agent_id',
        'assigned_to',
        'name',
        'email',
        'phone',
        'subject',
        'message',
        'status',
        'priority',
        'response',
        'responded_at',
        'source',
        'payload',
        'created_user_id',
        'updated_user_id',
        // Additional fields for quotation requests
        'inquiry_type',
        'vehicle_group_id',
        'service_type',
        'company_name',
        'search_context',
        'form_data',
        'ip_address',
        'user_agent'
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'responded_at' => 'datetime',
        'payload' => 'array',
        'search_context' => 'array',
        'form_data' => 'array',
    ];

    // Relations

    /**
     * Get the customer who made this inquiry.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Get the user who created this record.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_user_id');
    }

    /**
     * Get the user who last updated this record.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_user_id');
    }
}
