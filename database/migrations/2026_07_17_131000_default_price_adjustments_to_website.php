<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('price_adjustments', 'applicable_contexts')) {
            return;
        }

        DB::table('price_adjustments')
            ->whereNull('applicable_contexts')
            ->update(['applicable_contexts' => json_encode(['public'])]);
    }

    public function down(): void
    {
        // Intentionally retain the explicit Website selection. Converting it back
        // to null would also erase selections deliberately saved by administrators.
    }
};
