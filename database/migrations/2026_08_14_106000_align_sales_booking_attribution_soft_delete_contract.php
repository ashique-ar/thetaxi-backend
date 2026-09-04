<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sales_booking_attributions')
            && ! Schema::hasColumn('sales_booking_attributions', 'deleted_at')) {
            Schema::table('sales_booking_attributions', function (Blueprint $table): void {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sales_booking_attributions')
            && Schema::hasColumn('sales_booking_attributions', 'deleted_at')) {
            if (DB::table('sales_booking_attributions')->whereNotNull('deleted_at')->exists()) {
                throw new LogicException(
                    'Cannot remove sales_booking_attributions.deleted_at while tombstoned attributions exist; restore or disposition them first.'
                );
            }

            Schema::table('sales_booking_attributions', function (Blueprint $table): void {
                $table->dropSoftDeletes();
            });
        }
    }
};
