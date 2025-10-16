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
        return Schema::hasColumn($this->getTable(), 'is_active');
    }

    public function scopeWithInactive(Builder $q)
    {
        return $q->withoutGlobalScope('active');
    }
    
    public function scopeInactive(Builder $q)
    {
        return $q->withInactive()->where($q->getModel()->getTable() . '.is_active', false);
    }
}