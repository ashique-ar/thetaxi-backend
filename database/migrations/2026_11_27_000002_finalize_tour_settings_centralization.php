<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('service_types', 'tour_booking_enabled')) {
            return;
        }

        $legacyServices = DB::table('service_types')
            ->where('tour_booking_enabled', true)
            ->where('context', 'public')
            ->where('pricing_basis', 'vehicle_class')
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get(['id']);
        $selectedId = DB::table('business_settings')->where('type', 'tour_service_type_id')->value('value');
        if (! $selectedId && $legacyServices->count() > 1) {
            throw new RuntimeException('More than one Website Tour service is enabled. Select one in Business Settings before upgrading.');
        }
        $legacyService = $legacyServices->first();
        $selectedId ??= $legacyService?->id;

        DB::transaction(function () use ($selectedId, $legacyServices): void {
            if (! DB::table('business_settings')->where('type', 'tour_service_type_id')->exists()) {
                DB::table('business_settings')->insert([
                    'id' => (string) Str::uuid(),
                    'type' => 'tour_service_type_id',
                    'value' => $selectedId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            if (! DB::table('business_settings')->where('type', 'tours_enabled')->exists()) {
                $enabled = $selectedId && $legacyServices->contains(
                    fn ($service) => (string) $service->id === (string) $selectedId
                );
                DB::table('business_settings')->insert([
                    'id' => (string) Str::uuid(),
                    'type' => 'tours_enabled',
                    'value' => $enabled ? 'true' : 'false',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        Schema::table('service_types', fn (Blueprint $table) => $table->dropColumn('tour_booking_enabled'));
    }

    public function down(): void
    {
        if (Schema::hasColumn('service_types', 'tour_booking_enabled')) {
            return;
        }

        Schema::table('service_types', fn (Blueprint $table) => $table->boolean('tour_booking_enabled')->default(false));
        $enabled = filter_var(DB::table('business_settings')->where('type', 'tours_enabled')->value('value'), FILTER_VALIDATE_BOOLEAN);
        $selectedId = DB::table('business_settings')->where('type', 'tour_service_type_id')->value('value');
        if ($enabled && $selectedId) {
            DB::table('service_types')->where('id', $selectedId)->update(['tour_booking_enabled' => true]);
        }
    }
};
