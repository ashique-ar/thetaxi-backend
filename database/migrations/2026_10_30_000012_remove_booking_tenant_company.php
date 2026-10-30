<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bookings') || ! Schema::hasColumn('bookings', 'company_id')) {
            return;
        }

        $foreignKey = collect(Schema::getForeignKeys('bookings'))
            ->first(fn (array $key): bool => in_array('company_id', $key['columns'], true));
        if ($foreignKey) {
            Schema::table('bookings', fn (Blueprint $table) => $table->dropForeign($foreignKey['name']));
        }

        foreach (Schema::getIndexes('bookings') as $index) {
            if (in_array('company_id', $index['columns'], true)) {
                Schema::table('bookings', fn (Blueprint $table) => $table->dropIndex($index['name']));
            }
        }

        Schema::table('bookings', fn (Blueprint $table) => $table->dropColumn('company_id'));
    }

    public function down(): void
    {
        if (! Schema::hasTable('bookings') || Schema::hasColumn('bookings', 'company_id')) {
            return;
        }

        Schema::table('bookings', function (Blueprint $table): void {
            $table->foreignUuid('company_id')->nullable()->after('id')->constrained('companies')->restrictOnDelete();
            $table->index('company_id', 'bookings_company_idx');
        });
    }
};
