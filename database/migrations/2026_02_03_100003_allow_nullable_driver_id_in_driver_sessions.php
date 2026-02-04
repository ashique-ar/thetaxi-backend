<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Modifies the driver_sessions table to allow nullable driver_id
     * for guest/meter sessions that don't require driver authentication.
     * 
     * @see Requirement 3.2 - Track by device_uuid without authentication
     * @see Requirement 3.3 - Track guest usage by Device_UUID
     */
    public function up(): void
    {
        Schema::table('driver_sessions', function (Blueprint $table) {
            // Drop the existing foreign key constraint
            $table->dropForeign(['driver_id']);
            
            // Modify driver_id to be nullable
            $table->uuid('driver_id')->nullable()->change();
            
            // Re-add the foreign key constraint with nullable support
            $table->foreign('driver_id')
                ->references('id')
                ->on('drivers')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('driver_sessions', function (Blueprint $table) {
            // Drop the foreign key constraint
            $table->dropForeign(['driver_id']);
            
            // Make driver_id required again
            $table->uuid('driver_id')->nullable(false)->change();
            
            // Re-add the foreign key constraint
            $table->foreign('driver_id')
                ->references('id')
                ->on('drivers')
                ->onDelete('cascade');
        });
    }
};
