<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sms_campaigns', function (Blueprint $table) {
            $table->foreignUuid('company_id')->nullable()->after('id')->constrained('companies')->restrictOnDelete();
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        if (DB::table('sms_campaigns')->whereNotNull('company_id')->exists()) {
            throw new RuntimeException('Cannot remove company ownership from retained SMS campaigns.');
        }

        Schema::table('sms_campaigns', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropIndex(['company_id', 'status']);
            $table->dropColumn('company_id');
        });
    }
};
