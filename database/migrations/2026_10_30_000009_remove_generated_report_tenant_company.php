<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('booking_generated_reports') || ! Schema::hasColumn('booking_generated_reports', 'company_id')) {
            return;
        }

        Schema::table('booking_generated_reports', function (Blueprint $table): void {
            $table->dropIndex('booking_generated_reports_company_created_idx');
            $table->dropConstrainedForeignId('company_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('booking_generated_reports') || Schema::hasColumn('booking_generated_reports', 'company_id')) {
            return;
        }

        Schema::table('booking_generated_reports', function (Blueprint $table): void {
            $table->foreignUuid('company_id')->nullable()->after('id')->constrained('companies')->restrictOnDelete();
            $table->index(['company_id', 'created_at'], 'booking_generated_reports_company_created_idx');
        });
    }
};
