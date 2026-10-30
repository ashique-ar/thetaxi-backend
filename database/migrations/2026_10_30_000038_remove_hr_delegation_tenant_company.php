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
        if (Schema::hasTable('activity_log') && Schema::hasColumn('activity_log', 'properties')) {
            foreach (DB::table('activity_log')->where('log_name', 'hr-self-service')->get(['id', 'properties']) as $activity) {
                $properties = is_array($activity->properties) ? $activity->properties : json_decode((string) ($activity->properties ?: '{}'), true, 512, JSON_THROW_ON_ERROR);
                unset($properties['company_id'], $properties['attributes']['company_id'], $properties['old']['company_id']);
                DB::table('activity_log')->where('id', $activity->id)->update(['properties' => json_encode($properties, JSON_THROW_ON_ERROR)]);
            }
        }

        if (! Schema::hasTable('hr_approval_delegations') || ! Schema::hasColumn('hr_approval_delegations', 'company_id')) {
            return;
        }
        foreach (Schema::getForeignKeys('hr_approval_delegations') as $foreignKey) {
            if (in_array('company_id', $foreignKey['columns'], true)) {
                Schema::table('hr_approval_delegations', fn (Blueprint $table) => $table->dropForeign($foreignKey['name']));
            }
        }
        foreach (Schema::getIndexes('hr_approval_delegations') as $index) {
            if ($index['type'] === 'primary' || ! in_array('company_id', $index['columns'], true)) {
                continue;
            }
            TenantIndexDropper::drop('hr_approval_delegations', $index);
        }
        Schema::table('hr_approval_delegations', fn (Blueprint $table) => $table->dropColumn('company_id'));

        $legacyKeyCollisions = DB::table('hr_approval_delegations')->whereNotNull('idempotency_key')
            ->select('idempotency_key')->groupBy('idempotency_key')->havingRaw('COUNT(*) > 1')->exists();
        Schema::table('hr_approval_delegations', function (Blueprint $table) use ($legacyKeyCollisions): void {
            if ($legacyKeyCollisions) {
                $table->index('idempotency_key', 'hr_delegations_installation_idempotency_idx');
            } else {
                $table->unique('idempotency_key', 'hr_delegations_installation_idempotency_unique');
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('HR approval delegations cannot regain tenant company scope.');
    }
};
