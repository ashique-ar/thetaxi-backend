<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::dropIfExists('vehicles');
        Schema::create('vehicles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('contract_type_id')->nullable()->index();
            $table->uuid('company_id')->nullable()->index();
            $table->uuid('owner_id')->nullable()->index();
            $table->uuid('vehicle_group_id')->nullable()->index();
            $table->string('title')->nullable();
            $table->string('registration_no')->nullable();
            $table->string('chasis_no')->nullable();
            $table->string('engine_no')->nullable();
            $table->string('license_plate')->nullable();
            $table->integer('model_year')->nullable();
            $table->string('color')->nullable();
            $table->string('no_od_doors')->nullable();
            $table->string('ac')->nullable();
            $table->string('thumbnail')->nullable();
            $table->string('slug')->nullable();
            $table->string('bags')->nullable();
            $table->string('seats')->nullable();
            $table->string('refundable_deposit')->nullable();
            $table->string('year')->nullable();
            $table->string('tagline')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->nullable();
            $table->uuid( 'created_user_id')->nullable()->index();
            $table->uuid('updated_user_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};
