<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sales_opportunities', function (Blueprint $table): void {
            $table->foreignUuid('workflow_quotation_stage_setting_id')->nullable()
                ->references('id', 'sales_opp_workflow_quote_setting_fk')->on('sales_company_feature_settings')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales_opportunities', function (Blueprint $table): void {
            $table->dropForeign('sales_opp_workflow_quote_setting_fk');
            $table->dropColumn('workflow_quotation_stage_setting_id');
        });
    }
};
