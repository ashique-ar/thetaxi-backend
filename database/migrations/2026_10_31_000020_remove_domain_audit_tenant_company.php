<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('domain_audit_events', 'company_id')) {
            Schema::table('domain_audit_events', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('company_id');
            });
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Domain audit events cannot regain tenant company scope.');
    }
};
