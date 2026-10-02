<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_attribution_exceptions', function (Blueprint $table): void {
            $table->dropUnique('sales_attribution_open_exception_unique');
        });

        DB::statement(
            "CREATE UNIQUE INDEX sales_attribution_open_exception_unique
             ON sales_attribution_exceptions (booking_id, exception_type)
             WHERE status = 'open'"
        );
    }

    public function down(): void
    {
        $duplicates = DB::table('sales_attribution_exceptions')
            ->select(['booking_id', 'exception_type', 'status'])
            ->groupBy(['booking_id', 'exception_type', 'status'])
            ->havingRaw('COUNT(*) > 1')
            ->exists();
        if ($duplicates) {
            throw new \RuntimeException(
                'Cannot restore the legacy attribution-exception uniqueness constraint without discarding history.'
            );
        }

        DB::statement('DROP INDEX IF EXISTS sales_attribution_open_exception_unique');
        Schema::table('sales_attribution_exceptions', function (Blueprint $table): void {
            $table->unique(
                ['booking_id', 'exception_type', 'status'],
                'sales_attribution_open_exception_unique'
            );
        });
    }
};
