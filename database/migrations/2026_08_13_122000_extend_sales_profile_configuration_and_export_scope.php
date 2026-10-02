<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_profiles', function (Blueprint $table) {
            $table->boolean('acquisition_eligible')->default(false)->after('status');
            $table->boolean('collection_eligible')->default(false)->after('acquisition_eligible');
            $table->boolean('commission_eligible')->default(false)->after('collection_eligible');
            $table->char('reporting_currency', 3)->nullable()->after('commission_eligible');
            $table->unsignedInteger('version')->default(1)->after('reporting_currency');
            $table->index(
                ['company_id', 'status', 'acquisition_eligible', 'collection_eligible'],
                'sales_profiles_scope_eligibility_idx'
            );
        });

        Schema::table('sales_profile_events', function (Blueprint $table) {
            // Nullable for existing history; every new event written by the governed API is versioned.
            $table->unsignedInteger('profile_version')->nullable()->after('sales_profile_id');
            $table->json('before_configuration')->nullable()->after('reason');
            $table->json('after_configuration')->nullable()->after('before_configuration');
            $table->string('idempotency_key', 160)->nullable()->after('after_configuration');
            $table->unique(
                ['sales_profile_id', 'profile_version'],
                'sales_profile_events_profile_version_unique'
            );
            $table->unique(
                ['sales_profile_id', 'idempotency_key'],
                'sales_profile_events_idempotency_unique'
            );
        });

        Schema::table('sales_profile_exports', function (Blueprint $table) {
            $table->string('scope_type', 20)->nullable()->after('status_filter');
            $table->json('scope_profile_ids')->nullable()->after('scope_type');
            $table->char('scope_checksum', 64)->nullable()->after('scope_profile_ids');
            $table->char('request_checksum', 64)->nullable()->after('scope_checksum');
            $table->timestamp('expires_at')->nullable()->after('generated_at');
            $table->unsignedInteger('download_count')->default(0)->after('expires_at');
            $table->index(['generated_by', 'expires_at'], 'sales_profile_exports_owner_expiry_idx');
        });
    }

    public function down(): void
    {
        Schema::table('sales_profile_exports', function (Blueprint $table) {
            $table->dropIndex('sales_profile_exports_owner_expiry_idx');
            $table->dropColumn([
                'scope_type',
                'scope_profile_ids',
                'scope_checksum',
                'request_checksum',
                'expires_at',
                'download_count',
            ]);
        });

        Schema::table('sales_profile_events', function (Blueprint $table) {
            $table->dropUnique('sales_profile_events_profile_version_unique');
            $table->dropUnique('sales_profile_events_idempotency_unique');
            $table->dropColumn(['profile_version', 'before_configuration', 'after_configuration', 'idempotency_key']);
        });

        Schema::table('sales_profiles', function (Blueprint $table) {
            $table->dropIndex('sales_profiles_scope_eligibility_idx');
            $table->dropColumn([
                'acquisition_eligible',
                'collection_eligible',
                'commission_eligible',
                'reporting_currency',
                'version',
            ]);
        });
    }
};
