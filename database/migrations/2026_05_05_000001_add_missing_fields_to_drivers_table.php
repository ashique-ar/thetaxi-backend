<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            // Employee management fields
            $table->date('hire_date')->nullable()->after('contract_expiry_date');
            $table->date('termination_date')->nullable()->after('hire_date');

            // Health information
            $table->string('blood_group')->nullable()->after('termination_date');
            $table->text('medical_conditions')->nullable()->after('blood_group');

            // Emergency contact (if not already added)
            if (!Schema::hasColumn('drivers', 'emergency_contact_name')) {
                $table->string('emergency_contact_name')->nullable()->after('medical_conditions');
                $table->string('emergency_contact_phone')->nullable()->after('emergency_contact_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $columns = ['hire_date', 'termination_date', 'blood_group', 'medical_conditions'];

            if (Schema::hasColumn('drivers', 'emergency_contact_name')) {
                $columns[] = 'emergency_contact_name';
                $columns[] = 'emergency_contact_phone';
            }

            $table->dropColumn($columns);
        });
    }
};
