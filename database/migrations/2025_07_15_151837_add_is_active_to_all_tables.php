<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    private array $skip = [
        'migrations',
        'jobs',
        'failed_jobs',
        'personal_access_tokens',
        'password_reset_tokens',
        'sessions',
        'cache',
        'cache_locks',
        'queue_batches',
        'oauth_clients',
        'oauth_access_tokens',
        'oauth_refresh_tokens',
        'oauth_personal_access_clients',
        'oauth_auth_codes',
        'model_has_permissions',
        'model_has_roles',
        'role_has_permissions',
        'audit_logs',
        'notification_logs',
        'driver_logs',
        'booking_statuses',
        'vehicle_maintenance_records',
        'payment_transactions',
        'taxi_sessions',
        'search_saved',
    ];

    public function up(): void
    {
        $tables = DB::table('information_schema.tables')
            ->select('table_name')
            ->where('table_schema', 'public')
            ->pluck('table_name')
            ->toArray();

        foreach ($tables as $tbl) {
            if (in_array($tbl, $this->skip, true)) {
                continue;
            }

            if (!Schema::hasColumn($tbl, 'is_active')) {
                Schema::table($tbl, function (Blueprint $table) {
                    $table->boolean('is_active')->default(true)->after('updated_at')->index();
                });
            }
        }
    }

    public function down(): void
    {
        $tables = DB::table('information_schema.tables')
            ->select('table_name')
            ->where('table_schema', 'public')
            ->pluck('table_name')
            ->toArray();

        foreach ($tables as $tbl) {
            if (Schema::hasColumn($tbl, 'is_active')) {
                Schema::table($tbl, fn(Blueprint $table) => $table->dropColumn('is_active'));
            }
        }
    }
};
