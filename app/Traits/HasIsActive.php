<?php

namespace App\Traits;

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Eloquent\Builder;

trait HasIsActive
{
    public function initializeHasIsActive(): void
    {
        if ($this->hasIsActiveColumn()) {
            $this->mergeFillable(['is_active']);
            $this->casts = array_merge($this->casts, ['is_active' => 'boolean']);
        }
    }

    protected static function bootHasIsActive(): void
    {
        static::addGlobalScope('active', function (Builder $q) {
            if ((new static)->hasIsActiveColumn()) {
                $q->where($q->getModel()->getTable() . '.is_active', true);
            }
        });
    }

    protected function hasIsActiveColumn(): bool
    {
        static $cache = [];
        
        $table = $this->getTable();
        
        if (!isset($cache[$table])) {
            $cache[$table] = Schema::hasColumn($table, 'is_active');
        }
        
        return $cache[$table];
    }


    public function scopeWithInactive(Builder $q)
    {
        return $q->withoutGlobalScope('active');
    }
    
    public function scopeInactive(Builder $q)
    {
        return $q->withInactive()->where($q->getModel()->getTable() . '.is_active', false);
    }

    /**
     * Retrieve the model for a bound value (route model binding).
     * Override to include inactive records for admin operations.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        return $this->withInactive()->where($field ?? $this->getRouteKeyName(), $value)->firstOrFail();
    }
}