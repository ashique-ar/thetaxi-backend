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
        // Modify activity_log table to support UUID subject_id and causer_id
        Schema::table('activity_log', function (Blueprint $table) {
            // Change subject_id to string (UUID)
            if (Schema::hasColumn('activity_log', 'subject_id')) {
                $table->string('subject_id')->nullable()->change();
            }
            
            // Change causer_id to string (UUID) if it exists
            if (Schema::hasColumn('activity_log', 'causer_id')) {
                $table->string('causer_id')->nullable()->change();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert activity_log table
        // Schema::table('activity_log', function (Blueprint $table) {
        //     // Revert subject_id to unsignedBigInteger
        //     if (Schema::hasColumn('activity_log', 'subject_id')) {
        //         $table->unsignedBigInteger('subject_id')->nullable()->change();
        //     }
            
        //     // Revert causer_id to unsignedBigInteger if it exists
        //     if (Schema::hasColumn('activity_log', 'causer_id')) {
        //         $table->unsignedBigInteger('causer_id')->nullable()->change();
        //     }
        // });
    }
};
