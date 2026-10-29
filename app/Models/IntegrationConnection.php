<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IntegrationConnection extends Model
{
    use HasUuids;

    protected $fillable = ['company_id', 'provider', 'name', 'webhook_secret_hash', 'api_key_hash', 'respond_io_api_token', 'enabled', 'default_inquiry_assignee_user_id', 'missed_call_callback_minutes', 'missed_call_escalation_minutes', 'created_user_id'];
    protected $hidden = ['webhook_secret_hash', 'api_key_hash'];
    protected $casts = ['enabled' => 'boolean', 'respond_io_api_token' => 'encrypted', 'missed_call_callback_minutes' => 'integer', 'missed_call_escalation_minutes' => 'integer'];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function events(): HasMany { return $this->hasMany(IntegrationWebhookEvent::class, 'connection_id'); }
    public function defaultInquiryAssignee(): BelongsTo { return $this->belongsTo(User::class, 'default_inquiry_assignee_user_id')->withTrashed(); }
}
