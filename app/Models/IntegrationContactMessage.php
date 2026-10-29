<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationContactMessage extends Model
{
    use HasUuids;

    protected $fillable = [
        'connection_id', 'company_id', 'provider', 'external_contact_id', 'external_message_id',
        'traffic', 'sender_source', 'message_type', 'content', 'sent_at', 'synced_at',
        'linked_customer_id', 'linked_by_user_id', 'linked_at',
    ];

    protected $casts = [
        'content' => 'encrypted',
        'sent_at' => 'datetime',
        'synced_at' => 'datetime',
        'linked_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'linked_customer_id');
    }
}
