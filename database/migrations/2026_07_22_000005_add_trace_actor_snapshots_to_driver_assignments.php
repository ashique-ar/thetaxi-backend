<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_assignments', function (Blueprint $table) {
            $table->string('assigned_by_name_snapshot')->nullable()->after('assigned_by');
            $table->string('confirmed_by_name_snapshot')->nullable()->after('confirmed_by');
            $table->string('driver_name_snapshot')->nullable()->after('driver_id');
        });
    }

    public function down(): void
    {
        Schema::table('driver_assignments', fn (Blueprint $table) => $table->dropColumn([
            'assigned_by_name_snapshot', 'confirmed_by_name_snapshot', 'driver_name_snapshot',
        ]));
    }
};
