<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_payment_receipts', function (Blueprint $table): void {
            $table->string('idempotency_key', 160)->nullable()->change();
        });
    }

    public function down(): void
    {
        $hasNonUuidKeys = DB::table('booking_payment_receipts')
            ->whereNotNull('idempotency_key')
            ->cursor()
            ->contains(fn ($key) => ! Str::isUuid((string) $key));

        if ($hasNonUuidKeys) {
            throw new RuntimeException('Rollback refused: receipt idempotency keys now contain non-UUID values.');
        }

        Schema::table('booking_payment_receipts', function (Blueprint $table): void {
            $table->uuid('idempotency_key')->nullable()->change();
        });
    }
};
