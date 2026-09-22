<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_items', function (Blueprint $table): void {
            $table->decimal('price_override_amount', 12, 2)->nullable()->after('total_price');
            $table->text('price_override_reason')->nullable()->after('price_override_amount');
            $table->uuid('price_overridden_by')->nullable()->after('price_override_reason');
            $table->timestamp('price_overridden_at')->nullable()->after('price_overridden_by');
        });
    }

    public function down(): void
    {
        Schema::table('booking_items', function (Blueprint $table): void {
            $table->dropColumn([
                'price_override_amount',
                'price_override_reason',
                'price_overridden_by',
                'price_overridden_at',
            ]);
        });
    }
};
