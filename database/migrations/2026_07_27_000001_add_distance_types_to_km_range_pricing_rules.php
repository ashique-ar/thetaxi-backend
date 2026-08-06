<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('km_range_pricing_rules', function (Blueprint $table): void {
            $table->json('distance_types')
                ->default(json_encode([
                    'journey_distance',
                    'pickup_distance',
                    'delivery_distance',
                ]))
                ->after('vehicle_group_id')
                ->comment('Distance legs evaluated by this rule');
            $table->json('applicable_contexts')
                ->default(json_encode(['public']))
                ->after('distance_types')
                ->comment('Pricing contexts where this rule applies');
        });
    }

    public function down(): void
    {
        Schema::table('km_range_pricing_rules', function (Blueprint $table): void {
            $table->dropColumn(['distance_types', 'applicable_contexts']);
        });
    }
};
