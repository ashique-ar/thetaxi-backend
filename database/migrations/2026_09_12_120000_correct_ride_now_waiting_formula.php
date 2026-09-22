<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Only repair the known faulty formula; preserve independently edited definitions.
        $definitions = DB::table('vehicle_pricing_calculation_definitions')
            ->whereNull('deleted_at')
            ->where('name', 'Ride Now')
            ->where('formula', '(total_distance - Minimum_KM) * service_rate_per_km + (duration_minutes - Free_Waiting)  + Additonal_Waiting_min + base_rate')
            ->get();

        foreach ($definitions as $definition) {
            $variables = json_decode($definition->variables, true, 512, JSON_THROW_ON_ERROR);
            foreach ($variables as &$variable) {
                if (in_array($variable['name'], ['waiting_minutes', 'Minimum_Fare'], true)) {
                    $variable['is_required'] = true;
                    $variable['default_value'] = null;
                }
            }
            unset($variable);

            DB::table('vehicle_pricing_calculation_definitions')->where('id', $definition->id)->update([
                'formula' => 'Minimum_Fare + max(0, total_distance - Minimum_KM) * service_rate_per_km + max(0, waiting_minutes - Free_Waiting) * Additonal_Waiting_min',
                'variables' => json_encode($variables, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Do not restore a formula that bills journey minutes as currency.
    }
};
