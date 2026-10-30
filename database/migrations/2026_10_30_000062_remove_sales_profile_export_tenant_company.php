<?php

use App\Support\Foundation\CanonicalJson;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/Support/TenantIndexDropper.php';

return new class extends Migration
{
    public function up(): void
    {
        $tableName = 'sales_profile_exports';
        if (! Schema::hasTable($tableName)) {
            return;
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

        if (! Schema::hasColumn($tableName, 'scope_profile_ids') || ! Schema::hasColumn($tableName, 'scope_checksum')) {
            return;
        }

        DB::table($tableName)->whereNotNull('scope_type')->whereNotNull('scope_profile_ids')
            ->orderBy('id')->chunkById(100, function ($exports): void {
                foreach ($exports as $export) {
                    if (! in_array($export->scope_type, ['self', 'team', 'all'], true)) {
                        continue;
                    }
                    $profileIds = is_array($export->scope_profile_ids)
                        ? $export->scope_profile_ids
                        : json_decode((string) $export->scope_profile_ids, true);
                    if (! is_array($profileIds) || ! array_is_list($profileIds)) {
                        continue;
                    }
                    $profileIds = array_map('strval', $profileIds);
                    sort($profileIds, SORT_STRING);
                    $checksum = hash('sha256', CanonicalJson::encode([
                        'scope_type' => $export->scope_type,
                        'profile_ids' => array_values($profileIds),
                    ]));
                    DB::table('sales_profile_exports')->where('id', $export->id)->update([
                        'scope_checksum' => $checksum,
                        'request_checksum' => null,
                    ]);
                }
            });

        DB::table($tableName)->whereNotNull('request_checksum')->update(['request_checksum' => null]);
    }

    public function down(): void
    {
        throw new RuntimeException('Sales Profile exports cannot regain tenant company scope.');
    }
};
