<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('website_settings')) {
            return;
        }

        $hasCompanyId = Schema::hasColumn('website_settings', 'company_id');
        foreach ([
            'sms_quotation_ready_enabled' => 'false',
            'sms_quotation_ready_template' => 'Your quotation from {company_name} is ready. Reference: {booking_number}. Review and accept it here: {quotation_link}',
        ] as $type => $value) {
            $query = DB::table('website_settings')->where('type', $type);
            if ($hasCompanyId) {
                $query->whereNull('company_id');
            }
            if ($query->exists()) {
                continue;
            }

            $setting = [
                'id' => (string) Str::uuid(),
                'type' => $type,
                'value' => $value,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            if ($hasCompanyId) {
                $setting['company_id'] = null;
            }
            DB::table('website_settings')->insert($setting);
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('website_settings')) {
            return;
        }

        $query = DB::table('website_settings')
            ->whereIn('type', ['sms_quotation_ready_enabled', 'sms_quotation_ready_template']);
        if (Schema::hasColumn('website_settings', 'company_id')) {
            $query->whereNull('company_id');
        }
        $query->delete();
    }
};
