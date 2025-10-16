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
        Schema::dropIfExists('drivers');
        Schema::create('drivers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->index();
            $table->string('code')->unique()->nullable();
            $table->string('nic')->nullable();
            $table->string('license_no')->nullable();
            $table->date('license_expiry')->nullable();
            $table->uuid('license_type')->nullable();
            $table->date('dob')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('address')->nullable();
            $table->uuid('country_id')->nullable()->index();
            $table->uuid('state_id')->nullable()->index();
            $table->string('city')->nullable()->index();
            $table->text('remarks')->nullable();
            $table->date('company_id_renewal_date')->nullable();
            $table->date('contract_expiry_date')->nullable();
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
        Schema::dropIfExists('drivers');
    }
};
