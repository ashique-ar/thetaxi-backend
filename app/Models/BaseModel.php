<?php

namespace App\Models;

use App\Traits\HasIsActive;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class BaseModel extends Model
{
    use HasFactory, LogsActivity, SoftDeletes, HasIsActive, HasUuids;

    /**
     * Whether to automatically track created/updated user IDs.
     * Set to false in child models if the table lacks these columns.
     */
    protected $useUserTracking = true;

    protected $fillable = [
        'created_user_id',
        'updated_user_id',
    ];


    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if ($model->useUserTracking && auth()->check()) {
                $model->created_user_id = auth()->id();
            }
        });

        static::updating(function ($model) {
            if ($model->useUserTracking && auth()->check()) {
                $model->updated_user_id = auth()->id();
            }
        });
    }


    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->dontLogIfAttributesChangedOnly(['updated_at', 'created_at'])
            ->useLogName(isset($this->logName) && !empty($this->logName)
                ? $this->logName
                : class_basename($this));
    }
}
