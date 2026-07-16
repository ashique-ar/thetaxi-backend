<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_price_adjustment_history', function (Blueprint $table): void {
            $table->uuid('booking_item_id')->nullable()->after('booking_id');
            $table->softDeletes();
            $table->unique(
                ['booking_item_id', 'price_adjustment_id'],
                'booking_item_price_adjustment_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('booking_price_adjustment_history', function (Blueprint $table): void {
            $table->dropUnique('booking_item_price_adjustment_unique');
            $table->dropSoftDeletes();
            $table->dropColumn('booking_item_id');
        });
    }
};
