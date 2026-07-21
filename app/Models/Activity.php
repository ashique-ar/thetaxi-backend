<?php

namespace App\Models;

use Spatie\Activitylog\Models\Activity as SpatieActivity;

class Activity extends SpatieActivity
{
    public function getTable(): string
    {
        return config('activitylog.table_name', parent::getTable());
    }

    public function getConnectionName(): ?string
    {
        return config('activitylog.database_connection') ?: parent::getConnectionName();
    }
}
