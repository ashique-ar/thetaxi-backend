<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('wialon_unique_id')->nullable();
            $table->unsignedBigInteger('wialon_hw_type_id')->nullable();
            $table->decimal('wialon_mileage', 12, 2)->nullable();
            $table->timestamp('wialon_last_message_at')->nullable();
            $table->timestamp('wialon_last_synced_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', fn (Blueprint $table) => $table->dropColumn([
            'wialon_unique_id', 'wialon_hw_type_id', 'wialon_mileage', 'wialon_last_message_at', 'wialon_last_synced_at',
        ]));
    }
};
