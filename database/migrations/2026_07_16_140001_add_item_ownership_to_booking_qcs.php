<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_qcs', function (Blueprint $table) {
            $table->uuid('booking_item_id')->nullable()->after('booking_id');
            $table->index(['booking_id', 'booking_item_id'], 'booking_qc_booking_item_idx');
            $table->unique(['booking_id', 'booking_item_id'], 'booking_qc_booking_item_unique');
        });

        DB::table('booking_qcs')
            ->whereNull('booking_item_id')
            ->orderBy('id')
            ->get(['id', 'booking_id', 'dispatch_id'])
            ->each(function (object $qc): void {
                $dispatchItemId = $qc->dispatch_id
                    ? DB::table('booking_dispatches')
                        ->where('id', $qc->dispatch_id)
                        ->value('booking_item_id')
                    : null;

                $itemAlreadyOwned = $dispatchItemId && DB::table('booking_qcs')
                    ->where('booking_id', $qc->booking_id)
                    ->where('booking_item_id', $dispatchItemId)
                    ->where('id', '!=', $qc->id)
                    ->exists();

                if ($dispatchItemId && !$itemAlreadyOwned) {
                    DB::table('booking_qcs')
                        ->where('id', $qc->id)
                        ->update(['booking_item_id' => $dispatchItemId]);
                    return;
                }

                $qcCount = DB::table('booking_qcs')
                    ->where('booking_id', $qc->booking_id)
                    ->whereNull('deleted_at')
                    ->count();
                $itemIds = DB::table('booking_items')
                    ->where('booking_id', $qc->booking_id)
                    ->whereNull('deleted_at')
                    ->limit(2)
                    ->pluck('id');

                if ($qcCount === 1 && $itemIds->count() === 1) {
                    DB::table('booking_qcs')
                        ->where('id', $qc->id)
                        ->update(['booking_item_id' => $itemIds->first()]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('booking_qcs', function (Blueprint $table) {
            $table->dropUnique('booking_qc_booking_item_unique');
            $table->dropIndex('booking_qc_booking_item_idx');
            $table->dropColumn('booking_item_id');
        });
    }
};
