<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE_INDEXES = [
        'hr_custom_field_definitions' => ['columns' => ['applies_to', 'field_key'], 'name' => 'hr_custom_field_owner_key_index'],
        'hr_custom_field_value_events' => ['columns' => ['owner_type', 'owner_id', 'occurred_at'], 'name' => 'hr_custom_value_event_owner_time_index'],
        'hr_custom_field_value_access_events' => ['columns' => ['owner_type', 'owner_id', 'occurred_at'], 'name' => 'hr_custom_value_access_owner_time_index'],
    ];

    public function up(): void
    {
        if (Schema::hasTable('hr_organization_change_events')) {
            DB::table('hr_organization_change_events')->where('aggregate_type', 'custom_field_definition')
                ->orderBy('id')->chunkById(200, function ($events): void {
                    foreach ($events as $event) {
                        $after = $this->decode($event->after_snapshot);
                        unset($after['company_id']);
                        $before = $event->before_snapshot === null ? null : $this->decode($event->before_snapshot);
                        if ($before !== null) {
                            unset($before['company_id']);
                        }

                        DB::table('hr_organization_change_events')->where('id', $event->id)->update([
                            'before_snapshot' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
                            'after_snapshot' => json_encode($after, JSON_THROW_ON_ERROR),
                        ]);
                    }
                }, 'id');
        }

        foreach (array_keys(self::TABLE_INDEXES) as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'company_id')) {
                continue;
            }

            foreach (Schema::getForeignKeys($tableName) as $foreignKey) {
                if (in_array('company_id', $foreignKey['columns'], true)) {
                    Schema::table($tableName, fn (Blueprint $table) => $table->dropForeign($foreignKey['name']));
                }
            }
            foreach (Schema::getIndexes($tableName) as $index) {
                if ($index['type'] === 'primary' || ! in_array('company_id', $index['columns'], true)) {
                    continue;
                }

                Schema::table($tableName, fn (Blueprint $table) => $index['unique']
                    ? $table->dropUnique($index['name'])
                    : $table->dropIndex($index['name']));
            }
            Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('company_id'));
        }

        foreach (self::TABLE_INDEXES as $tableName => $index) {
            if (Schema::hasTable($tableName) && ! Schema::hasIndex($tableName, $index['name'])) {
                Schema::table($tableName, fn (Blueprint $table) => $table->index($index['columns'], $index['name']));
            }
        }
    }

    public function down(): void
    {
        throw new LogicException('Cannot restore removed custom-field company ownership without inventing tenant assignments.');
    }

    private function decode(mixed $value): array
    {
        return is_array($value) ? $value : json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
    }
};
