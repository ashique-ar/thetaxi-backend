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
        Schema::dropIfExists('vehicle_owners');
        Schema::create('vehicle_owners', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('owner_type_id')->index();           
            $table->uuid('user_id')->index();           
            $table->string('address')->nullable();
            $table->uuid('country_id')->nullable()->index();
            $table->uuid('state_id')->nullable()->index();
            $table->string('city')->nullable()->index();
            $table->string('postal_code')->nullable()->index();
            $table->date('dob')->nullable()->index();
            $table->date('license_expiry')->nullable()->index();
            $table->string('license_number')->nullable()->index();
            $table->text('notes')->nullable();
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
        Schema::dropIfExists('vehicle_owners');
    }
};
