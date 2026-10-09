<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['booking_collection_work_items', 'booking_collection_submissions'] as $tableName) {
            if (Schema::hasTable($tableName) && ! Schema::hasColumn($tableName, 'deleted_at')) {
                Schema::table($tableName, function (Blueprint $table): void {
                    $table->softDeletes();
                });
            }
        }

        if (Schema::hasTable('financial_audit_events')) {
            Schema::table('financial_audit_events', function (Blueprint $table): void {
                if (! Schema::hasColumn('financial_audit_events', 'domain')) {
                    $table->string('domain', 40)->nullable();
                }
                if (! Schema::hasColumn('financial_audit_events', 'company_id')) {
                    $table->uuid('company_id')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['booking_collection_submissions', 'booking_collection_work_items'] as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'deleted_at')) {
                if (DB::table($tableName)->whereNotNull('deleted_at')->exists()) {
                    throw new LogicException("Cannot remove {$tableName}.deleted_at while soft-deleted records exist.");
                }

                Schema::table($tableName, function (Blueprint $table): void {
                    $table->dropSoftDeletes();
                });
            }
        }

        if (Schema::hasTable('financial_audit_events')) {
            $dropDomain = Schema::hasColumn('financial_audit_events', 'domain');
            $dropCompany = Schema::hasColumn('financial_audit_events', 'company_id');
            if (($dropDomain && DB::table('financial_audit_events')->whereNotNull('domain')->exists())
                || ($dropCompany && DB::table('financial_audit_events')->whereNotNull('company_id')->exists())) {
                throw new LogicException('Cannot remove populated financial audit event ownership fields.');
            }

            Schema::table('financial_audit_events', function (Blueprint $table) use ($dropDomain, $dropCompany): void {
                if ($dropCompany) {
                    $table->dropColumn('company_id');
                }
                if ($dropDomain) {
                    $table->dropColumn('domain');
                }
            });
        }
    }
};
