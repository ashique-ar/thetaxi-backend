<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
        $centralServiceId = DB::table('business_settings')->where('type', 'tour_service_type_id')->value('value');
        $centralServiceExists = DB::table('business_settings')->where('type', 'tour_service_type_id')->exists();
        if (! $centralServiceExists && $legacyServices->count() > 1) {
            throw new RuntimeException('More than one Website Tour service is enabled. Choose one in Business Settings before removing the service-level Tour flag.');
        }
        $legacyService = $legacyServices->first();

        if (! DB::table('business_settings')->where('type', 'tour_service_type_id')->exists()) {
            DB::table('business_settings')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'type' => 'tour_service_type_id',
                'value' => $legacyService?->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (! DB::table('business_settings')->where('type', 'tours_enabled')->exists()) {
            $legacyToursEnabled = $centralServiceExists
                ? $legacyServices->contains(fn ($service) => (string) $service->id === (string) $centralServiceId)
                : (bool) $legacyService;
            DB::table('business_settings')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'type' => 'tours_enabled',
                'value' => $legacyToursEnabled ? 'true' : 'false',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('service_types', fn (Blueprint $table) => $table->dropColumn('tour_booking_enabled'));
    }

    public function down(): void
    {
        if (Schema::hasColumn('service_types', 'tour_booking_enabled')) {
            return;
        }

        Schema::table('service_types', fn (Blueprint $table) => $table->boolean('tour_booking_enabled')->default(false));

        $enabled = filter_var(DB::table('business_settings')->where('type', 'tours_enabled')->value('value'), FILTER_VALIDATE_BOOLEAN);
        $serviceId = DB::table('business_settings')->where('type', 'tour_service_type_id')->value('value');
        if ($enabled && $serviceId) {
            DB::table('service_types')->where('id', $serviceId)->update(['tour_booking_enabled' => true]);
        }
    }
};
