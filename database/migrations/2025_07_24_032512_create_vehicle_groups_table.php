<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::dropIfExists('vehicle_groups');
        Schema::create('vehicle_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('grade_id')->index()->nullable();
            $table->uuid('make_id')->index()->nullable();
            $table->uuid('model_id')->index()->nullable();
            $table->uuid('transmission_id')->index()->nullable();
            $table->uuid('fuel_type_id')->index()->nullable();
            $table->uuid('category_id')->index()->nullable();
            $table->uuid('class_id')->index()->nullable();
            $table->string('name');                // e.g. "Land Cruiser ZX Series"
            $table->text('description')->nullable();
            $table->json('specs')->nullable();     // store group-level specs
            $table->json('images')->nullable();    // group images
            $table->boolean('is_active')->default(true);
            $table->uuid('created_user_id')->nullable()->index();
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
        Schema::dropIfExists('vehicle_groups');
    }
};
