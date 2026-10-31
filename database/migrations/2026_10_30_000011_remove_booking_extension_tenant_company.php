<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/Support/TenantIndexDropper.php';

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('booking_extension_requests') || ! Schema::hasColumn('booking_extension_requests', 'company_id')) {
            return;
        }

        $foreignKey = collect(Schema::getForeignKeys('booking_extension_requests'))
            ->first(fn (array $key): bool => in_array('company_id', $key['columns'], true));
        if ($foreignKey) {
            Schema::table('booking_extension_requests', fn (Blueprint $table) => $table->dropForeign($foreignKey['name']));
        }

        foreach (Schema::getIndexes('booking_extension_requests') as $index) {
            if (in_array('company_id', $index['columns'], true)) {
                TenantIndexDropper::drop('booking_extension_requests', $index);
            }
        }

        Schema::table('booking_extension_requests', function (Blueprint $table): void {
            $table->dropColumn('company_id');
            $table->index(['status', 'created_at'], 'booking_extension_queue_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('booking_extension_requests') || Schema::hasColumn('booking_extension_requests', 'company_id')) {
            return;
        }

        Schema::table('booking_extension_requests', function (Blueprint $table): void {
            $table->dropIndex('booking_extension_queue_idx');
            $table->foreignUuid('company_id')->nullable()->after('id')->constrained('companies')->restrictOnDelete();
            $table->index(['company_id', 'status', 'created_at'], 'booking_extension_queue_idx');
        });
    }
};
