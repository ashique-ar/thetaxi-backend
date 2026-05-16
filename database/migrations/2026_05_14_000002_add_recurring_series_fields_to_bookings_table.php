<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->uuid('recurring_series_id')->nullable()->after('recurrence_days');
            $table->unsignedInteger('recurring_sequence')->nullable()->after('recurring_series_id');
            $table->date('recurring_occurrence_date')->nullable()->after('recurring_sequence');

            $table->index(['recurring_series_id', 'recurring_occurrence_date'], 'bookings_recurring_series_date_index');
            $table->index(['recurring_series_id', 'recurring_sequence'], 'bookings_recurring_series_sequence_index');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex('bookings_recurring_series_date_index');
            $table->dropIndex('bookings_recurring_series_sequence_index');
            $table->dropColumn([
                'recurring_series_id',
                'recurring_sequence',
                'recurring_occurrence_date',
            ]);
        });
    }
};
