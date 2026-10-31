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
        $tableName = 'booking_payment_finality_policies';
        if (! Schema::hasTable($tableName)) {
            return;
        }

        $policies = DB::table($tableName)->orderBy('payment_method')->orderBy('effective_from')->orderBy('created_at')->orderBy('id')->get();
        $approved = $policies->where('status', 'approved')->groupBy('payment_method');
        foreach ($approved as $method => $rows) {
            $rows = $rows->values();
            for ($left = 0; $left < $rows->count(); $left++) {
                for ($right = $left + 1; $right < $rows->count(); $right++) {
                    $leftEnd = $rows[$left]->effective_until ? strtotime((string) $rows[$left]->effective_until) : PHP_INT_MAX;
                    $rightEnd = $rows[$right]->effective_until ? strtotime((string) $rows[$right]->effective_until) : PHP_INT_MAX;
                    if (strtotime((string) $rows[$left]->effective_from) < $rightEnd
                        && strtotime((string) $rows[$right]->effective_from) < $leftEnd) {
                        throw new RuntimeException("Cannot make finality policies installation-wide; approved {$method} policies {$rows[$left]->id} and {$rows[$right]->id} overlap.");
                    }
                }
            }
        }

        foreach ($policies->groupBy('payment_method') as $rows) {
            foreach ($rows->values() as $index => $policy) {
                DB::table($tableName)->where('id', $policy->id)->update(['version' => $index + 1]);
            }
        }

        if (Schema::hasColumn($tableName, 'company_id')) {
            foreach (Schema::getForeignKeys($tableName) as $foreignKey) {
                if (in_array('company_id', $foreignKey['columns'], true)) {
                    Schema::table($tableName, fn (Blueprint $table) => $table->dropForeign($foreignKey['name']));
                }
            }
            foreach (Schema::getIndexes($tableName) as $index) {
                if (in_array('company_id', $index['columns'], true)) {
                    TenantIndexDropper::drop($tableName, $index);
                }
            }
            Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('company_id'));
        }
        $hasGlobalUnique = collect(Schema::getIndexes($tableName))->contains(fn (array $index): bool => $index['columns'] === ['payment_method', 'version']
            && (($index['unique'] ?? false) || ($index['type'] ?? null) === 'unique'));
        if (! $hasGlobalUnique) {
            Schema::table($tableName, fn (Blueprint $table) => $table->unique(['payment_method', 'version'], 'booking_finality_policy_version_unique'));
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Payment finality policies cannot regain tenant company scope.');
    }
};
