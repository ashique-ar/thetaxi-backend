<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('website_settings')) {
            return;
        }

        DB::table('website_settings')
            ->where('type', 'sms_admin_booking_summary_numbers')
            ->orderBy('id')
            ->each(function (object $setting): void {
                $value = trim((string) $setting->value);
                if ($value === '' || str_starts_with($value, 'enc:v1:')) {
                    return;
                }

                $decoded = json_decode($value, true);
                $numbers = is_array($decoded) ? $decoded : preg_split('/[,\r\n]+/', $value);
                DB::table('website_settings')->where('id', $setting->id)->update([
                    'value' => 'enc:v1:' . Crypt::encryptString(json_encode(array_values($numbers), JSON_THROW_ON_ERROR)),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        if (!Schema::hasTable('website_settings')) {
            return;
        }

        DB::table('website_settings')
            ->where('type', 'sms_admin_booking_summary_numbers')
            ->where('value', 'like', 'enc:v1:%')
            ->orderBy('id')
            ->each(function (object $setting): void {
                try {
                    $value = Crypt::decryptString(substr((string) $setting->value, 7));
                } catch (Throwable) {
                    return;
                }

                DB::table('website_settings')->where('id', $setting->id)->update([
                    'value' => $value,
                    'updated_at' => now(),
                ]);
            });
    }
};
