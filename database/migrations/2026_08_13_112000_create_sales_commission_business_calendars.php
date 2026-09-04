<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_commission_business_calendars', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('code', 80);
            $table->string('name', 160);
            $table->string('timezone', 80);
            $table->json('weekly_working_days');
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->string('status', 20)->default('draft');
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'code', 'effective_from'], 'sales_commission_calendar_version_unique');
            $table->index(['company_id', 'status', 'effective_from'], 'sales_commission_calendar_effective_idx');
        });

        Schema::create('sales_commission_business_calendar_dates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('calendar_id')->constrained('sales_commission_business_calendars')->restrictOnDelete();
            $table->date('calendar_date');
            $table->string('day_type', 20);
            $table->string('name', 160);
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['calendar_id', 'calendar_date'], 'sales_commission_calendar_date_unique');
        });

        Schema::table('sales_commission_cycle_versions', function (Blueprint $table) {
            $table->foreignUuid('business_calendar_id')->nullable()->after('timezone')
                ->constrained('sales_commission_business_calendars')->restrictOnDelete();
        });

        Schema::table('sales_commission_statements', function (Blueprint $table) {
            $table->foreignUuid('business_calendar_id')->nullable()->after('cycle_assignment_id')
                ->constrained('sales_commission_business_calendars')->restrictOnDelete();
            $table->timestamp('finalization_at')->nullable()->after('cutoff_at');
            $table->timestamp('approval_deadline_at')->nullable()->after('finalization_at');
            $table->timestamp('settlement_at')->nullable()->after('approval_deadline_at');
            $table->json('cycle_schedule_snapshot')->nullable();
            $table->char('cycle_schedule_checksum', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sales_commission_statements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('business_calendar_id');
            $table->dropColumn(['finalization_at', 'approval_deadline_at', 'settlement_at', 'cycle_schedule_snapshot', 'cycle_schedule_checksum']);
        });
        Schema::table('sales_commission_cycle_versions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('business_calendar_id');
        });
        Schema::dropIfExists('sales_commission_business_calendar_dates');
        Schema::dropIfExists('sales_commission_business_calendars');
    }
};
