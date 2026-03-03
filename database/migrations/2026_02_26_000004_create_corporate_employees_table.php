<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('corporate_employees', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('corporate_id');
            $table->uuid('department_id');
            $table->uuid('division_id')->nullable();
            $table->string('employee_code', 50)->nullable();
            $table->boolean('is_active')->default(true);
            $table->uuid('created_user_id')->nullable();
            $table->uuid('updated_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // Partial unique composite index on (user_id, corporate_id) where deleted_at IS NULL
        DB::statement('CREATE UNIQUE INDEX corporate_employees_user_id_corporate_id_unique ON corporate_employees (user_id, corporate_id) WHERE deleted_at IS NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('corporate_employees');
    }
};
