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
        Schema::dropIfExists('customers');

        Schema::create('customers', function (Blueprint $table) {
           $table->uuid('id')->primary();
            $table->uuid('user_id')->index();
            $table->string('code')->nullable();
            $table->string('type')->nullable();
            $table->string('sub_type')->nullable();
            $table->string('category')->nullable();
            $table->string('passport_number')->unique()->nullable();
            $table->string('nic')->unique()->nullable();
            $table->string('license_no')->unique()->nullable();
            $table->date('license_expiry')->nullable();
            $table->string('license_type')->nullable();
            $table->date('dob')->nullable();
            $table->date('wedding_date')->nullable();
            $table->string('address')->nullable();
            $table->string('gender')->nullable();
            $table->string('postal_code')->nullable();
            $table->uuid('country_id')->nullable()->index();
            $table->uuid('state_id')->nullable()->index();
            $table->string('city')->nullable()->index();
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
        Schema::dropIfExists('customers');
    }
};
