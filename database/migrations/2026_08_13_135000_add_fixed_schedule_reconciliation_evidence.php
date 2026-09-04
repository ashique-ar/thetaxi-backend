<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_payment_schedules', function (Blueprint $table) {
            $table->string('reconciliation_role', 20)->nullable()->after('schedule_kind');
        });

        Schema::table('booking_payment_schedule_revisions', function (Blueprint $table) {
            $table->string('contract_basis', 30)->nullable()->after('effective_at');
            $table->string('reconciliation_rule', 20)->nullable()->after('contract_basis');
            $table->decimal('contractual_source_amount', 20, 4)->nullable()->after('reconciliation_rule');
            $table->string('source_currency', 3)->nullable()->after('contractual_source_amount');
            $table->decimal('retained_source_amount', 20, 4)->nullable()->after('source_currency');
            $table->decimal('replacement_source_amount', 20, 4)->nullable()->after('retained_source_amount');
            $table->decimal('reconciliation_amount', 20, 4)->nullable()->after('replacement_source_amount');
            $table->decimal('contractual_lkr_amount', 20, 4)->nullable()->after('reconciliation_amount');
            $table->decimal('retained_lkr_amount', 20, 4)->nullable()->after('contractual_lkr_amount');
            $table->decimal('replacement_lkr_amount', 20, 4)->nullable()->after('retained_lkr_amount');
            $table->char('preview_checksum', 64)->nullable()->after('replacement_lkr_amount');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('booking_payment_schedule_revisions')
            && Schema::hasColumn('booking_payment_schedule_revisions', 'contractual_source_amount')
            && DB::table('booking_payment_schedule_revisions')->whereNotNull('contractual_source_amount')->exists()) {
            throw new RuntimeException('Refusing to drop fixed schedule reconciliation evidence.');
        }

        if (Schema::hasTable('booking_payment_schedule_revisions')
            && Schema::hasColumn('booking_payment_schedule_revisions', 'contractual_source_amount')) {
            Schema::table('booking_payment_schedule_revisions', function (Blueprint $table) {
                $table->dropColumn([
                    'contract_basis', 'reconciliation_rule', 'contractual_source_amount', 'source_currency',
                    'retained_source_amount', 'replacement_source_amount', 'reconciliation_amount', 'preview_checksum',
                    'contractual_lkr_amount', 'retained_lkr_amount', 'replacement_lkr_amount',
                ]);
            });
        }
        if (Schema::hasTable('booking_payment_schedule_revisions')
            && Schema::hasColumn('booking_payment_schedule_revisions', 'deleted_at')) {
            Schema::table('booking_payment_schedule_revisions', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }
        if (Schema::hasTable('booking_payment_schedules')
            && Schema::hasColumn('booking_payment_schedules', 'reconciliation_role')) {
            Schema::table('booking_payment_schedules', function (Blueprint $table) {
                $table->dropColumn('reconciliation_role');
            });
        }
    }
};
