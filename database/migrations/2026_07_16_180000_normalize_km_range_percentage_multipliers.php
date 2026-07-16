<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('km_range_pricing_rules')) {
            return;
        }

        // The former portal instructed users to enter 110 for a 10% increase,
        // while runtime has always stored/used a multiplier (1.10). Normalize
        // values created under that documented UI contract before enforcing
        // the corrected 0..10 multiplier range.
        DB::table('km_range_pricing_rules')
            ->where('price_type', 'percentage_multiplier')
            ->where('percentage', '>', 10)
            ->where('percentage', '<=', 1000)
            ->update(['percentage' => DB::raw('percentage / 100')]);
    }

    public function down(): void
    {
        // Intentionally irreversible: multiplying all valid values would also
        // corrupt rows created after the corrected UI was deployed.
    }
};
