<?php

use App\Services\Pricing\PricingContextPolicyService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('business_settings')->where('type', PricingContextPolicyService::SETTING_KEY)->exists()) {
            return;
        }

        DB::table('business_settings')->insert([
            'id' => (string) Str::uuid(),
            'type' => PricingContextPolicyService::SETTING_KEY,
            'value' => PricingContextPolicyService::MODE_INHERIT_WEBSITE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('business_settings')
            ->where('type', PricingContextPolicyService::SETTING_KEY)
            ->delete();
    }
};
