<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const LEGACY_CODE_MAP = [
        'corp_manual_dispatch' => 'corp_ride_now',
    ];

    public function up(): void
    {
        if (!Schema::hasTable('service_types')) {
            return;
        }

        $ownedServices = DB::table('service_types')
            ->where('context', 'corporate')
            ->where('owner_type', 'corporate')
            ->whereNotNull('owner_id')
            ->where('owner_id', '!=', '')
            ->orderBy('created_at')
            ->get();

        foreach ($ownedServices as $owned) {
            $sharedCode = self::LEGACY_CODE_MAP[$owned->code] ?? $owned->code;
            $shared = DB::table('service_types')
                ->where('code', $sharedCode)
                ->where('context', 'corporate')
                ->where('owner_type', '')
                ->where('owner_id', '')
                ->first();

            if (!$shared) {
                $payload = (array) $owned;
                $payload['id'] = (string) Str::uuid();
                $payload['code'] = $sharedCode;
                $payload['name'] = $owned->code === 'corp_manual_dispatch'
                    ? 'Corporate Ride Now'
                    : $owned->name;
                $payload['slug'] = Str::slug($sharedCode);
                $payload['owner_type'] = '';
                $payload['owner_id'] = '';
                $payload['deleted_at'] = null;
                $payload['created_at'] = now();
                $payload['updated_at'] = now();
                DB::table('service_types')->insert($payload);
                $shared = (object) $payload;
            }

            $this->repointCorporateAssignment($owned, $shared);
            $this->repointServiceReferences($owned->id, $shared->id);

            DB::table('service_types')
                ->where('id', $owned->id)
                ->update(['deleted_at' => now(), 'updated_at' => now()]);
        }
    }

    private function repointCorporateAssignment(object $owned, object $shared): void
    {
        if (!Schema::hasTable('corporate_service_types')) {
            return;
        }

        $assignments = DB::table('corporate_service_types')
            ->where('service_type_id', $owned->id)
            ->whereNull('deleted_at')
            ->get();

        foreach ($assignments as $assignment) {
            $existing = DB::table('corporate_service_types')
                ->where('corporate_id', $assignment->corporate_id)
                ->where('service_type_id', $shared->id)
                ->whereNull('deleted_at')
                ->exists();

            if ($existing) {
                DB::table('corporate_service_types')->where('id', $assignment->id)->delete();
            } else {
                DB::table('corporate_service_types')
                    ->where('id', $assignment->id)
                    ->update(['service_type_id' => $shared->id, 'updated_at' => now()]);
            }
        }
    }

    private function repointServiceReferences(string $ownedId, string $sharedId): void
    {
        foreach ([
            'vehicle_pricing_calculation_definitions',
            'vehicle_pricing_slab_definitions',
            'vehicle_pricing_common_rate_definitions',
            'price_adjustments',
            'km_range_pricing_rules',
            'vehicle_discounts',
            'service_packages',
            'corporate_transport_routes',
            'booking_items',
            'bookings',
        ] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'service_type_id')) {
                DB::table($table)
                    ->where('service_type_id', $ownedId)
                    ->update(['service_type_id' => $sharedId]);
            }
        }
    }

    public function down(): void
    {
        // Consolidation is intentionally irreversible because corporate pricing remains
        // scoped by owner_type/owner_id after service definitions become shared.
    }
};
