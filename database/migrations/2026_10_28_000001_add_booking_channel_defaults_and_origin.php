<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('booking_channels', function (Blueprint $table) {
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
        });
        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignUuid('booking_channel_id')->nullable()->after('booking_source')->constrained('booking_channels')->nullOnDelete();
            $table->string('booking_origin', 40)->nullable()->after('booking_channel_id')->index();
        });
        DB::table('bookings')->whereIn('booking_source', ['public', 'website'])->update(['booking_origin' => 'website']);
        DB::table('bookings')->where(fn ($query) => $query->where('booking_source', 'internal')->orWhereNull('booking_source'))
            ->where('created_from', 'internal')->update(['booking_origin' => 'staff']);
        $first = DB::table('booking_channels')->whereNull('deleted_at')->orderBy('created_at')->value('id');
        if ($first) DB::table('booking_channels')->where('id', $first)->update(['is_default' => true]);
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('booking_channel_id');
            $table->dropColumn('booking_origin');
        });
        Schema::table('booking_channels', fn (Blueprint $table) => $table->dropColumn(['is_active', 'is_default']));
    }
};
