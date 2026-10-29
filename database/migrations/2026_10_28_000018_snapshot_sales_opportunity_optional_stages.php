<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sales_opportunities', function (Blueprint $table): void {
            $table->foreignUuid('workflow_contact_stage_setting_id')->nullable()
                ->references('id', 'sales_opp_workflow_contact_fk')->on('sales_company_feature_settings')->restrictOnDelete();
            $table->foreignUuid('workflow_qualification_stage_setting_id')->nullable()
                ->references('id', 'sales_opp_workflow_qualify_fk')->on('sales_company_feature_settings')->restrictOnDelete();
            $table->foreignUuid('workflow_negotiation_stage_setting_id')->nullable()
                ->references('id', 'sales_opp_workflow_negotiate_fk')->on('sales_company_feature_settings')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales_opportunities', function (Blueprint $table): void {
            $table->dropForeign('sales_opp_workflow_contact_fk');
            $table->dropForeign('sales_opp_workflow_qualify_fk');
            $table->dropForeign('sales_opp_workflow_negotiate_fk');
            $table->dropColumn([
                'workflow_contact_stage_setting_id',
                'workflow_qualification_stage_setting_id',
                'workflow_negotiation_stage_setting_id',
            ]);
        });
    }
};
