<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'hr_epf_etf_contribution_policies' => [
                'hr_epf_etf_policy_version_unique', 'hr_epf_etf_policy_effective_idx',
                'hr_epf_etf_policy_status_effective_idx', 'hr_epf_etf_policy_version_idx',
            ],
            'hr_gratuity_policies' => [
                'hr_gratuity_policy_version_unique', 'hr_gratuity_policy_effective_idx',
                'hr_gratuity_policy_status_effective_idx', 'hr_gratuity_policy_version_idx',
            ],
        ] as $tableName => [$uniqueIndex, $effectiveIndex, $statusIndex, $versionIndex]) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'company_id')) {
                continue;
            }

            Schema::table($tableName, fn (Blueprint $table) => $table->dropForeign(['company_id']));
            Schema::table($tableName, function (Blueprint $table) use ($uniqueIndex, $effectiveIndex): void {
                $table->dropUnique($uniqueIndex);
                $table->dropIndex($effectiveIndex);
                $table->dropColumn('company_id');
            });
            Schema::table($tableName, function (Blueprint $table) use ($statusIndex, $versionIndex): void {
                $table->index(['status', 'effective_from'], $statusIndex);
                $table->index('version', $versionIndex);
            });
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Statutory policy company assignments cannot be reconstructed after installation-wide consolidation.');
    }
};
