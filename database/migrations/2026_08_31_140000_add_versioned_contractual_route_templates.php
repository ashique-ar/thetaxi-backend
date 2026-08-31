<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('corporate_contract_locations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('corporate_id')->nullable();
            $table->string('owner_type', 30);
            $table->string('name');
            $table->text('address');
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->foreign('corporate_id')->references('id')->on('corporates')->cascadeOnDelete();
            $table->index(['corporate_id', 'owner_type', 'is_active']);
        });

        Schema::table('corporate_distance_pricing_policies', function (Blueprint $table) {
            $table->unsignedSmallInteger('route_contract_version')->default(1);
            $table->string('route_template', 60)->default('full_movement');
            $table->json('route_anchor_sequence')->nullable();
        });
        Schema::table('corporate_service_distance_policies', function (Blueprint $table) {
            $table->string('route_template_override', 60)->nullable();
            $table->json('route_anchor_sequence_override')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('corporate_service_distance_policies', fn (Blueprint $table) => $table->dropColumn(['route_template_override', 'route_anchor_sequence_override']));
        Schema::table('corporate_distance_pricing_policies', fn (Blueprint $table) => $table->dropColumn(['route_contract_version', 'route_template', 'route_anchor_sequence']));
        Schema::dropIfExists('corporate_contract_locations');
    }
};
