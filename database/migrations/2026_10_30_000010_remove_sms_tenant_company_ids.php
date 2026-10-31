<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/Support/TenantIndexDropper.php';

return new class extends Migration
{
    public function up(): void
    {
        $this->dropTenantCompanyId('sms_campaigns', 'status');
        $this->dropTenantCompanyId('sms_messages', 'created_at');
        $this->dropTenantCompanyId('booking_activities', 'event_at');
    }

    public function down(): void
    {
        throw new RuntimeException('SMS and booking activity records cannot regain tenant company scope.');
    }

    private function dropTenantCompanyId(string $tableName, string $retainedIndexColumn): void
    {
        if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'company_id')) {
            return;
        }

        $foreignKey = collect(Schema::getForeignKeys($tableName))
            ->first(fn (array $key): bool => in_array('company_id', $key['columns'], true));
        if ($foreignKey) {
            Schema::table($tableName, fn (Blueprint $table) => $table->dropForeign($foreignKey['name']));
        }

        foreach (Schema::getIndexes($tableName) as $index) {
            if (in_array('company_id', $index['columns'], true)) {
                TenantIndexDropper::drop($tableName, $index);
            }
        }

        Schema::table($tableName, function (Blueprint $table) use ($tableName, $retainedIndexColumn): void {
            $table->dropColumn('company_id');
            if (Schema::hasColumn($tableName, $retainedIndexColumn) && ! Schema::hasIndex($tableName, [$retainedIndexColumn])) {
                $table->index($retainedIndexColumn);
            }
        });
    }
};
