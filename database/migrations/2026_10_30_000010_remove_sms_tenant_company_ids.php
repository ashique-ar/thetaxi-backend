<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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
        if (Schema::hasTable('sms_campaigns') && ! Schema::hasColumn('sms_campaigns', 'company_id')) {
            Schema::table('sms_campaigns', function (Blueprint $table): void {
                $table->foreignUuid('company_id')->nullable()->after('id')->constrained('companies')->restrictOnDelete();
                $table->index(['company_id', 'status']);
            });
        }

        if (Schema::hasTable('sms_messages') && ! Schema::hasColumn('sms_messages', 'company_id')) {
            Schema::table('sms_messages', function (Blueprint $table): void {
                $table->foreignUuid('company_id')->nullable()->after('id')->constrained('companies')->restrictOnDelete();
                $table->index(['company_id', 'created_at'], 'sms_messages_company_created_idx');
            });
        }

        if (Schema::hasTable('booking_activities') && ! Schema::hasColumn('booking_activities', 'company_id')) {
            Schema::table('booking_activities', function (Blueprint $table): void {
                $table->foreignUuid('company_id')->nullable()->after('id')->constrained('companies')->restrictOnDelete();
                $table->index(['company_id', 'event_at'], 'booking_activities_company_event_idx');
            });
        }
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
                Schema::table($tableName, fn (Blueprint $table) => $table->dropIndex($index['name']));
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
