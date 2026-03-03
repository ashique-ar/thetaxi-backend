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
        Schema::create('corporate_divisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('department_id');
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->uuid('created_user_id')->nullable();
            $table->uuid('updated_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // Partial unique composite index on (department_id, name) where deleted_at IS NULL
        DB::statement('CREATE UNIQUE INDEX corporate_divisions_department_id_name_unique ON corporate_divisions (department_id, name) WHERE deleted_at IS NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('corporate_divisions');
    }
};
