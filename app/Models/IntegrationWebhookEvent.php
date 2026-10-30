<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationWebhookEvent extends Model
{
    use HasUuids;

    protected $fillable = ['connection_id', 'provider', 'event_type', 'message_direction', 'external_contact_id', 'external_conversation_id', 'external_message_id', 'linked_booking_id', 'payload_hash', 'payload', 'status', 'received_at', 'outbound_message', 'outbound_status', 'outbound_message_id', 'outbound_sent_at', 'outbound_by_user_id'];
    protected $hidden = ['payload_hash'];
    protected $casts = ['payload' => 'encrypted:array', 'resolution_note' => 'encrypted', 'outbound_message' => 'encrypted', 'received_at' => 'datetime', 'processed_at' => 'datetime', 'outbound_sent_at' => 'datetime'];

    public function scopeIncomingMessage(Builder $query): Builder
    {
        return $query->whereNotNull('external_message_id')
            ->where(function (Builder $query): void {
                $query->where('message_direction', 'incoming')
                    ->orWhere(function (Builder $query): void {
                        $query->whereRaw('LOWER(event_type) LIKE ?', ['%message%'])
                            ->where(function (Builder $query): void {
                                $query->whereRaw('LOWER(event_type) LIKE ?', ['%incoming%'])
                                    ->orWhereRaw('LOWER(event_type) LIKE ?', ['%inbound%'])
                                    ->orWhereRaw('LOWER(event_type) LIKE ?', ['%received%']);
                            });
                    });
            })
            ->where(function (Builder $query): void {
                $query->whereNull('message_direction')->orWhere('message_direction', '!=', 'outgoing');
            })
            ->whereRaw('LOWER(event_type) NOT LIKE ?', ['%outgoing%'])
            ->whereRaw('LOWER(event_type) NOT LIKE ?', ['%outbound%'])
            ->whereRaw('LOWER(event_type) NOT LIKE ?', ['%echo%'])
            ->whereRaw('LOWER(event_type) NOT LIKE ?', ['%status%']);
    }

    public function scopeConversationMessage(Builder $query): Builder
    {
        return $query->whereNotNull('external_message_id')->whereRaw('LOWER(event_type) LIKE ?', ['%message%'])
            ->whereRaw('LOWER(event_type) NOT LIKE ?', ['%status%'])->whereRaw('LOWER(event_type) NOT LIKE ?', ['%echo%'])
            ->where(function (Builder $query): void {
                $query->whereIn('message_direction', ['incoming', 'outgoing'])
                    ->orWhere(function (Builder $query): void {
                        $query->whereNull('message_direction')->where(function (Builder $query): void {
                            $query->whereRaw('LOWER(event_type) LIKE ?', ['%incoming%'])->orWhereRaw('LOWER(event_type) LIKE ?', ['%inbound%'])
                                ->orWhereRaw('LOWER(event_type) LIKE ?', ['%received%'])->orWhereRaw('LOWER(event_type) LIKE ?', ['%outgoing%'])
                                ->orWhereRaw('LOWER(event_type) LIKE ?', ['%outbound%'])->orWhereRaw('LOWER(event_type) LIKE ?', ['%sent%']);
                        });
                    });
            });
    }

    public function connection(): BelongsTo { return $this->belongsTo(IntegrationConnection::class, 'connection_id'); }
}
