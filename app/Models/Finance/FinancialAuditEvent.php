<?php
namespace App\Models\Finance;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialAuditEvent extends Model
{
    use HasUuids;
    public $timestamps = true;

    protected $fillable = ['subject_type','subject_id','booking_id','event_type','from_status','to_status','amount','metadata','performed_by','occurred_at'];
    protected $casts = ['amount'=>'decimal:2','metadata'=>'array','occurred_at'=>'datetime'];

    protected static function booted(): void
    {
        static::creating(function (FinancialAuditEvent $event): void {
            $metadata = is_array($event->metadata) ? $event->metadata : [];
            if (!array_key_exists('actor_display_snapshot', $metadata)) {
                $user = $event->performed_by ? User::query()->find($event->performed_by, ['id', 'first_name', 'last_name']) : null;
                $name = $user ? trim((string) $user->first_name . ' ' . (string) $user->last_name) : null;
                $metadata['actor_display_snapshot'] = $name !== '' ? $name : ($event->performed_by ? null : 'System');
                $metadata['actor_type_snapshot'] = $event->performed_by ? 'user' : 'system';
                $event->metadata = $metadata;
            }
        });
        static::updating(fn () => throw new \LogicException('Financial audit events are immutable.'));
        static::deleting(fn () => throw new \LogicException('Financial audit events cannot be deleted.'));
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
