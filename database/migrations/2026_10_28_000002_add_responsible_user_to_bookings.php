<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->foreignUuid('responsible_user_id')->nullable()->after('created_by_user_id')
                ->constrained('users')->nullOnDelete();
            $table->index(['responsible_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropIndex(['responsible_user_id', 'status']);
            $table->dropConstrainedForeignId('responsible_user_id');
        });
    }
};
