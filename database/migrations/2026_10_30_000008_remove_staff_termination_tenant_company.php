<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('staff_context_termination_events') || ! Schema::hasColumn('staff_context_termination_events', 'company_id')) return;

        Schema::table('staff_context_termination_events', function (Blueprint $table): void {
            $table->dropIndex('staff_termination_company_time_idx');
            $table->dropConstrainedForeignId('company_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('staff_context_termination_events') || Schema::hasColumn('staff_context_termination_events', 'company_id')) return;

        Schema::table('staff_context_termination_events', function (Blueprint $table): void {
            $table->foreignUuid('company_id')->nullable()->after('id')->constrained('companies')->restrictOnDelete();
            $table->index(['company_id', 'terminated_at'], 'staff_termination_company_time_idx');
        });
    }
};
