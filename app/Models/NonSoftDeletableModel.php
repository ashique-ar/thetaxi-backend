<?php

namespace App\Models;

use App\Traits\HasIsActive;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

abstract class NonSoftDeletableModel extends Model
{
    use HasFactory, HasUuids, HasIsActive, LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->dontLogIfAttributesChangedOnly(['updated_at', 'created_at'])
            ->useLogName(isset($this->logName) && ! empty($this->logName)
                ? $this->logName
                : class_basename($this));
    }

    protected function performDeleteOnModel(): ?bool
    {
        throw new LogicException('This record cannot be deleted.');
    }
}
