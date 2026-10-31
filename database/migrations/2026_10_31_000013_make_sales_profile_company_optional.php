<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sales_profiles') && Schema::hasColumn('sales_profiles', 'company_id')) {
            Schema::table('sales_profiles', function (Blueprint $table): void {
                $table->foreignUuid('company_id')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Sales Profiles cannot regain company tenancy.');
    }
};
