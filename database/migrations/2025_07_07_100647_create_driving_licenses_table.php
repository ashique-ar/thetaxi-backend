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
        Schema::create('driving_licenses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->index();
            $table->string('license_number')->index();
            $table->uuid('license_type')->nullable();
            $table->date('issue_date');
            $table->date('expiry_date')->index();
            $table->string('issuing_authority')->nullable();
            $table->string('license_class')->nullable();
            $table->text('restrictions')->nullable();
            $table->text('endorsements')->nullable();
            $table->char('country', 3)->nullable();
            $table->string('state')->nullable();
            $table->string('document_path')->nullable();
            $table->enum('status', ['active', 'expired', 'suspended'])->default('active');
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
        Schema::dropIfExists('driving_licenses');
    }
};
