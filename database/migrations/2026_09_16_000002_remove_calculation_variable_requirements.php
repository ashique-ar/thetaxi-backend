<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('vehicle_pricing_calculation_definitions')
            ->select(['id', 'variables'])
            ->orderBy('id')
            ->get()
            ->each(function ($definition): void {
                $variables = is_string($definition->variables)
                    ? json_decode($definition->variables, true)
                    : (array) $definition->variables;

                if (!is_array($variables)) {
                    return;
                }

                $variables = array_map(function ($variable) {
                    if (is_array($variable)) {
                        unset($variable['is_required']);
                    }

                    return $variable;
                }, $variables);

                DB::table('vehicle_pricing_calculation_definitions')
                    ->where('id', $definition->id)
                    ->update(['variables' => json_encode($variables)]);
            });
    }

    public function down(): void
    {
        // Variable requirements are intentionally no longer part of pricing.
    }
};
