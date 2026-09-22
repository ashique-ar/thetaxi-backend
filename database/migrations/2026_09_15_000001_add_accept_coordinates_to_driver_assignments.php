<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_assignments', function (Blueprint $table): void {
            $table->decimal('accept_latitude', 10, 8)->nullable()->after('confirmed_at');
            $table->decimal('accept_longitude', 11, 8)->nullable()->after('accept_latitude');
        });
    }

    public function down(): void
    {
        Schema::table('driver_assignments', fn (Blueprint $table) => $table->dropColumn([
            'accept_latitude', 'accept_longitude',
        ]));
    }
};
