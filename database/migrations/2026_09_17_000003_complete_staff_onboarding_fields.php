<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->string('gender', 30)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('department')->nullable();
            $table->string('position')->nullable();
            $table->date('joining_date')->nullable();
            $table->uuid('reporting_to')->nullable()->index();
            $table->json('emergency_contact')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('staff', fn (Blueprint $table) => $table->dropColumn(['gender', 'postal_code', 'department', 'position', 'joining_date', 'reporting_to', 'emergency_contact']));
    }
};
