<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sales_opportunities')
            && ! Schema::hasColumn('sales_opportunities', 'deleted_at')) {
            Schema::table('sales_opportunities', function (Blueprint $table): void {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sales_opportunities')
            && Schema::hasColumn('sales_opportunities', 'deleted_at')) {
            Schema::table('sales_opportunities', function (Blueprint $table): void {
                $table->dropSoftDeletes();
            });
        }
    }
};
