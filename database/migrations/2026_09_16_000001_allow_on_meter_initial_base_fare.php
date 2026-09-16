<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->updateActualDistance(false, 0);
    }

    public function down(): void
    {
        $this->updateActualDistance(true, null);
    }

    private function updateActualDistance(bool $required, int|null $default): void
    {
        DB::table('vehicle_pricing_calculation_definitions')
            ->where('name', 'On Meter - Base Distance and Waiting')
            ->whereNull('deleted_at')
            ->get(['id', 'variables'])
            ->each(function ($definition) use ($required, $default): void {
                $variables = json_decode((string) $definition->variables, true) ?: [];
                foreach ($variables as &$variable) {
                    if (($variable['name'] ?? null) === 'actual_distance') {
                        $variable['default_value'] = $default;
                        $variable['is_required'] = $required;
                    }
                }

                DB::table('vehicle_pricing_calculation_definitions')
                    ->where('id', $definition->id)
                    ->update(['variables' => json_encode($variables), 'updated_at' => now()]);
            });
    }
};
