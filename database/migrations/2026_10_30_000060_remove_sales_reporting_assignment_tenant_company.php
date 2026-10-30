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
        if (! Schema::hasTable('sales_reporting_assignments')) return;

        if (Schema::hasColumn('sales_reporting_assignments', 'company_id')) {
            foreach (Schema::getForeignKeys('sales_reporting_assignments') as $foreignKey) {
                if (in_array('company_id', $foreignKey['columns'], true)) {
                    Schema::table('sales_reporting_assignments', fn (Blueprint $table) => $table->dropForeign($foreignKey['name']));
                }
            }
            foreach (Schema::getIndexes('sales_reporting_assignments') as $index) {
                if (in_array('company_id', $index['columns'], true)) {
                    TenantIndexDropper::drop('sales_reporting_assignments', $index);
                }
            }
            Schema::table('sales_reporting_assignments', fn (Blueprint $table) => $table->dropColumn('company_id'));
        }

        $this->removeCompanyMetadataFromEvents();
    }

    private function removeCompanyMetadataFromEvents(): void
    {
        if (! Schema::hasTable('sales_reporting_assignment_events')) return;

        foreach (DB::table('sales_reporting_assignment_events')->get(['id', 'before_snapshot', 'after_snapshot']) as $event) {
            $before = $this->withoutCompanyId($event->before_snapshot);
            $after = $this->withoutCompanyId($event->after_snapshot);
            if ($before !== $event->before_snapshot || $after !== $event->after_snapshot) {
                DB::table('sales_reporting_assignment_events')->where('id', $event->id)->update([
                    'before_snapshot' => $before,
                    'after_snapshot' => $after,
                ]);
            }
        }
    }

    private function withoutCompanyId(?string $snapshot): ?string
    {
        if ($snapshot === null) return null;
        $decoded = json_decode($snapshot, true);
        if (! is_array($decoded) || ! array_key_exists('company_id', $decoded)) return $snapshot;
        unset($decoded['company_id']);

        return json_encode($decoded, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function down(): void
    {
        throw new RuntimeException('Sales reporting assignments cannot regain company scope.');
    }
};
