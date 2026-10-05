<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_collection_submissions', function (Blueprint $table): void {
            $table->foreignUuid('submitted_by_staff_id')->nullable()->after('submitted_by_sales_profile_id')
                ->constrained('staff')->restrictOnDelete();
            $table->foreignUuid('submitted_by_user_id')->nullable()->after('submitted_by_staff_id')
                ->constrained('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('booking_collection_submissions')
            ->whereNotNull('submitted_by_staff_id')->orWhereNotNull('submitted_by_user_id')->exists()) {
            throw new RuntimeException('Cannot remove immutable collection submitter identity after it has been recorded.');
        }

        Schema::table('booking_collection_submissions', function (Blueprint $table): void {
            $table->dropForeign(['submitted_by_staff_id']);
            $table->dropForeign(['submitted_by_user_id']);
            $table->dropColumn(['submitted_by_staff_id', 'submitted_by_user_id']);
        });
    }
};
