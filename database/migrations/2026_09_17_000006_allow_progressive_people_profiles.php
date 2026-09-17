<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('email')->nullable()->change();
            $table->string('first_name')->nullable()->change();
        });
        Schema::table('staff', function (Blueprint $table): void {
            $table->string('staff_type')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Partial portal profiles cannot safely be made mandatory again.
    }
};
