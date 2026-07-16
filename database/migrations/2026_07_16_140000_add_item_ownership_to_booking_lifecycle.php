<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_dispatches', function (Blueprint $table) {
            $table->uuid('booking_item_id')->nullable()->after('booking_id');
            $table->index(['booking_id', 'booking_item_id'], 'booking_dispatch_booking_item_idx');
            $table->unique(['booking_id', 'booking_item_id'], 'booking_dispatch_booking_item_unique');
        });

        Schema::table('booking_items', function (Blueprint $table) {
            $table->timestamp('returned_at')->nullable()->after('status');
            $table->timestamp('final_priced_at')->nullable()->after('returned_at');
            $table->timestamp('completed_at')->nullable()->after('final_priced_at');
            $table->json('lifecycle_data')->nullable()->after('completed_at');
            $table->index(['booking_id', 'completed_at'], 'booking_item_completion_idx');
        });

        // A legacy booking-level dispatch is unambiguous only when its booking
        // has exactly one item. Multi-item legacy records deliberately remain
        // null so runtime code can stop and request an explicit item selection.
        DB::table('booking_dispatches')
            ->whereNull('booking_item_id')
            ->orderBy('id')
            ->get(['id', 'booking_id'])
            ->each(function (object $dispatch): void {
                $dispatchCount = DB::table('booking_dispatches')
                    ->where('booking_id', $dispatch->booking_id)
                    ->whereNull('deleted_at')
                    ->count();
                $itemIds = DB::table('booking_items')
                    ->where('booking_id', $dispatch->booking_id)
                    ->whereNull('deleted_at')
                    ->limit(2)
                    ->pluck('id');

                if ($dispatchCount === 1 && $itemIds->count() === 1) {
                    DB::table('booking_dispatches')
                        ->where('id', $dispatch->id)
                        ->update(['booking_item_id' => $itemIds->first()]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('booking_items', function (Blueprint $table) {
            $table->dropIndex('booking_item_completion_idx');
            $table->dropColumn([
                'returned_at',
                'final_priced_at',
                'completed_at',
                'lifecycle_data',
            ]);
        });

        Schema::table('booking_dispatches', function (Blueprint $table) {
            $table->dropUnique('booking_dispatch_booking_item_unique');
            $table->dropIndex('booking_dispatch_booking_item_idx');
            $table->dropColumn('booking_item_id');
        });
    }
};
