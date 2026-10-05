<?php

namespace App\Models;

use Illuminate\Support\Facades\Schema;
use LogicException;

abstract class NonSoftDeletableTrackedModel extends BaseModel
{
    protected $useUserTracking = false;

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model): void {
            if (auth()->check() && Schema::hasColumn($model->getTable(), 'created_user_id')) {
                $model->created_user_id = auth()->id();
            }
        });

        static::updating(function ($model): void {
            if (auth()->check() && Schema::hasColumn($model->getTable(), 'updated_user_id')) {
                $model->updated_user_id = auth()->id();
            }
        });
    }

    public static function bootSoftDeletes(): void
    {
        // These tables have selected user-tracking columns but no deleted_at column.
    }

    protected function performDeleteOnModel(): ?bool
    {
        throw new LogicException('This record cannot be deleted.');
    }
}
