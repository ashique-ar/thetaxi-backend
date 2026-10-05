<?php

namespace App\Models\Hr;

use App\Models\BaseModel;
use LogicException;
use Spatie\Activitylog\Support\LogOptions;

class HrPeopleIdentityLink extends BaseModel
{
    protected $fillable = ['company_id', 'duplicate_review_id', 'alias_staff_id', 'canonical_staff_id', 'status', 'reason', 'evidence_checksum', 'approved_by', 'approved_at'];
    protected $casts = ['approved_at' => 'datetime'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['company_id', 'status'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->dontLogIfAttributesChangedOnly(['updated_at', 'created_at'])
            ->useLogName('HrPeopleIdentityLink');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('People identity links are immutable.'));
        static::deleting(fn () => throw new LogicException('People identity links cannot be deleted.'));
    }
}
