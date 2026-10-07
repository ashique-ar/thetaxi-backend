<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('booking_payment_receipt_components', 'deleted_at')) {
            Schema::table('booking_payment_receipt_components', function (Blueprint $table): void {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        if (DB::table('booking_payment_receipt_components')->whereNotNull('deleted_at')->exists()) {
            throw new RuntimeException('Rollback refused: deleted payment receipt components must be retained.');
        }

        Schema::table('booking_payment_receipt_components', function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });
    }
};
