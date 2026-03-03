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
        Schema::create('corporates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 255);
            $table->string('contact_email', 255)->nullable();
            $table->string('contact_phone', 50)->nullable();
            $table->text('billing_address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('approval_required')->default(true);
            $table->boolean('exempt_coordinator_from_approval')->default(false);
            $table->boolean('coordinator_can_view_payments')->default(false);
            $table->uuid('created_user_id')->nullable();
            $table->uuid('updated_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // Partial unique index on name where deleted_at IS NULL
        DB::statement('CREATE UNIQUE INDEX corporates_name_unique ON corporates (name) WHERE deleted_at IS NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('corporates');
    }
};
