<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('price_adjustments', function (Blueprint $table): void {
            $table->json('service_type_ids')->nullable();
            $table->json('vehicle_group_ids')->nullable();
        });

        DB::table('price_adjustments')->whereNotNull('service_type_id')->orderBy('id')->chunkById(100, function ($adjustments): void {
            foreach ($adjustments as $adjustment) {
                DB::table('price_adjustments')->where('id', $adjustment->id)->update([
                    'service_type_ids' => json_encode([$adjustment->service_type_id]),
                ]);
            }
        });
        DB::table('price_adjustments')->whereNotNull('vehicle_group_id')->orderBy('id')->chunkById(100, function ($adjustments): void {
            foreach ($adjustments as $adjustment) {
                DB::table('price_adjustments')->where('id', $adjustment->id)->update([
                    'vehicle_group_ids' => json_encode([$adjustment->vehicle_group_id]),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('price_adjustments', function (Blueprint $table): void {
            $table->dropColumn(['service_type_ids', 'vehicle_group_ids']);
        });
    }
};
