<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_assignment_stops', function (Blueprint $table) {
            $table->string('booking_stop_id')->nullable()->after('booking_item_id');
            $table->unsignedInteger('type_sequence')->nullable()->after('route_order');

            $table->index(['assignment_id', 'booking_stop_id']);
            $table->index(['assignment_id', 'stop_type', 'type_sequence']);
        });
    }

    public function down(): void
    {
        Schema::table('driver_assignment_stops', function (Blueprint $table) {
            $table->dropIndex(['assignment_id', 'booking_stop_id']);
            $table->dropIndex(['assignment_id', 'stop_type', 'type_sequence']);
            $table->dropColumn(['booking_stop_id', 'type_sequence']);
        });
    }
};
