<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('corporates', function (Blueprint $table): void {
            $table->string('approval_mode', 20)->default('role');
            $table->json('approval_role_ids')->nullable();
            $table->json('approval_employee_ids')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('corporates', function (Blueprint $table): void {
            $table->dropColumn(['approval_mode', 'approval_role_ids', 'approval_employee_ids']);
        });
    }
};
