<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('website_settings')) {
            return;
        }

        $query = DB::table('website_settings')->where('type', 'sms_dry_run');

        if (Schema::hasColumn('website_settings', 'company_id')) {
            $query->whereNull('company_id');
        }

        $query->update([
            'value' => 'false',
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Do not silently disable live SMS delivery during a rollback.
    }
};
