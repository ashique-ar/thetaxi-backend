<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('pending_payment_links', function (Blueprint $table) {
            $table->json('revision_item_ids')->nullable()->after('booking_context');
            $table->decimal('revision_amount_due', 12, 2)->nullable()->after('amount_due');
            $table->decimal('revision_total', 12, 2)->nullable()->after('revision_amount_due');
            $table->json('revision_history')->nullable()->after('revision_total');
        });
    }

    public function down(): void
    {
        Schema::table('pending_payment_links', function (Blueprint $table) {
            $table->dropColumn(['revision_item_ids', 'revision_amount_due', 'revision_total', 'revision_history']);
        });
    }
};
