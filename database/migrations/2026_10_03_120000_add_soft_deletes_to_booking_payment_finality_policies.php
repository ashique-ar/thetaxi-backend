<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('booking_payment_finality_policies', 'deleted_at')) {
            Schema::table('booking_payment_finality_policies', fn ($table) => $table->softDeletes());
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('booking_payment_finality_policies', 'deleted_at')) {
            return;
        }

        if (DB::table('booking_payment_finality_policies')->whereNotNull('deleted_at')->exists()) {
            throw new RuntimeException('Cannot remove finality-policy soft deletes while deleted policy history exists.');
        }

        Schema::table('booking_payment_finality_policies', fn ($table) => $table->dropSoftDeletes());
    }
};
