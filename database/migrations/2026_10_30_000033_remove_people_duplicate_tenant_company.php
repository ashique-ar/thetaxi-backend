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
            foreach (DB::table('activity_log')->whereIn('log_name', ['HrPeopleDuplicateReview', 'HrPeopleIdentityLink'])->get(['id', 'properties']) as $activity) {
                $properties = is_array($activity->properties)
                    ? $activity->properties
                    : json_decode((string) ($activity->properties ?: '{}'), true, 512, JSON_THROW_ON_ERROR);
                unset($properties['attributes']['company_id'], $properties['old']['company_id']);
                DB::table('activity_log')->where('id', $activity->id)->update(['properties' => json_encode($properties, JSON_THROW_ON_ERROR)]);
            }
        }

        foreach (['hr_people_duplicate_reviews', 'hr_people_identity_links'] as $tableName) {
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
                TenantIndexDropper::drop($tableName, $index);
            }

            Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('company_id'));
        }
    }

    public function down(): void
    {
        throw new RuntimeException('People Core duplicate reviews and identity links cannot regain tenant company scope.');
    }
};
