<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        'booking_deposit_refunds',
        'booking_payment_adjustments',
        'booking_payment_receipt_finality_events',
        'booking_payment_schedule_revisions',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            if (! Schema::hasColumn($name, 'deleted_at')) {
                Schema::table($name, function (Blueprint $table): void {
                    $table->softDeletes();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            if (DB::table($name)->whereNotNull('deleted_at')->exists()) {
                throw new RuntimeException("Rollback refused: deleted {$name} records must be retained.");
            }
        }

        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropSoftDeletes();
            });
        }
    }
};
