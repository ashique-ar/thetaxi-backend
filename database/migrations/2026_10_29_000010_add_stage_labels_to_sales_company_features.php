<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sales_company_feature_settings', function (Blueprint $table): void {
            $table->string('stage_label', 80)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sales_company_feature_settings', function (Blueprint $table): void {
            $table->dropColumn('stage_label');
        });
    }
};
