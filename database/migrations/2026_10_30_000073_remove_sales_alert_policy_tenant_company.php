<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/Support/TenantIndexDropper.php';

return new class extends Migration
{
    public function up(): void
    {
        $tableName = 'sales_alert_policy_versions';
        if (! Schema::hasTable($tableName)) {
            return;
        }

        if (Schema::hasColumn($tableName, 'company_id')) {
            $overlaps = DB::table('sales_alert_policy_versions as earlier')
                ->join('sales_alert_policy_versions as later', function ($join): void {
                    $join->on('later.id', '>', 'earlier.id');
                })
                ->where('earlier.status', 'approved')
                ->where('later.status', 'approved')
                ->where(function ($query): void {
                    $query->whereNull('earlier.effective_until')
                        ->orWhereColumn('earlier.effective_until', '>=', 'later.effective_from');
                })
                ->where(function ($query): void {
                    $query->whereNull('later.effective_until')
                        ->orWhereColumn('earlier.effective_from', '<=', 'later.effective_until');
                })
                ->limit(20)
                ->get(['earlier.id as earlier_id', 'later.id as later_id']);

            if ($overlaps->isNotEmpty()) {
                $details = $overlaps->map(fn ($row) => "{$row->earlier_id}/{$row->later_id}")->implode(', ');
                throw new RuntimeException("Approved Sales alert policies overlap after removing former company partitions. Reconcile the policy IDs before retrying: {$details}");
            }

            foreach (Schema::getForeignKeys($tableName) as $foreignKey) {
                if (in_array('company_id', $foreignKey['columns'], true)) {
                    Schema::table($tableName, fn (Blueprint $table) => $table->dropForeign($foreignKey['name']));
                }
            }

            foreach (Schema::getIndexes($tableName) as $index) {
                if (! in_array('company_id', $index['columns'], true)) {
                    continue;
                }
                TenantIndexDropper::drop($tableName, $index);
            }
        }

        $version = 0;
        foreach (DB::table($tableName)->orderBy('created_at')->orderBy('id')->pluck('id') as $id) {
            DB::table($tableName)->where('id', $id)->update(['version' => ++$version]);
        }

        $hasInstallationUniqueVersion = collect(Schema::getIndexes($tableName))
            ->contains(fn (array $index) => $index['columns'] === ['version'] && ($index['unique'] ?? false));
        if (! $hasInstallationUniqueVersion) {
            Schema::table($tableName, fn (Blueprint $table) => $table->unique('version', 'sales_alert_policy_version_installation_unique'));
        }

        if (Schema::hasColumn($tableName, 'company_id')) {
            Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('company_id'));
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Sales alert policies cannot regain tenant company scope.');
    }
};
