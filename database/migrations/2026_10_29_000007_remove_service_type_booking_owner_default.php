<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('service_types', 'default_booking_responsible_user_id')) {
            Schema::table('service_types', fn (Blueprint $table) => $table->dropConstrainedForeignId('default_booking_responsible_user_id'));
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('service_types', 'default_booking_responsible_user_id')) {
            Schema::table('service_types', fn (Blueprint $table) => $table->foreignUuid('default_booking_responsible_user_id')->nullable()
                ->after('is_active')->constrained('users')->nullOnDelete());
        }
    }
};
