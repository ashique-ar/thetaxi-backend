<?php

namespace App\Models\Vehicle\VehiclePricing\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Pricing definitions describe shared calculation structure. Corporate
 * ownership belongs to the vehicle-group price rows that supply values, not
 * to the definitions themselves.
 */
trait HasGlobalPricingDefinitionScope
{
    protected static function bootHasGlobalPricingDefinitionScope(): void
    {
        static::addGlobalScope('global_pricing_definition', function (Builder $builder): void {
            $model = $builder->getModel();

            $builder
                ->whereNull($model->qualifyColumn('owner_type'))
                ->whereNull($model->qualifyColumn('owner_id'));
        });

        static::saving(function (Model $model): void {
            $model->setAttribute('owner_type', null);
            $model->setAttribute('owner_id', null);
        });
    }
}
