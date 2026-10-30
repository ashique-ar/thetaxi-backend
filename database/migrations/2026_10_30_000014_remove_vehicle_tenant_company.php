<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('vehicles') && Schema::hasColumn('vehicles', 'company_id')
            && DB::table('vehicles')->whereNotNull('company_id')->exists()) {
            throw new RuntimeException('vehicles.company_id contains values. Classify and preserve real location assignments before removing this tenant field.');
        }
        if (Schema::hasTable('vehicle_companies') && DB::table('vehicle_companies')->exists()) {
            throw new RuntimeException('vehicle_companies contains rows. Classify and preserve real location assignments before removing this tenant table.');
        }

        if (Schema::hasTable('vehicles') && Schema::hasColumn('vehicles', 'company_id')) {
            foreach (Schema::getForeignKeys('vehicles') as $foreignKey) {
                if (in_array('company_id', $foreignKey['columns'], true)) {
                    Schema::table('vehicles', fn (Blueprint $table) => $table->dropForeign($foreignKey['name']));
                }
            }
            foreach (Schema::getIndexes('vehicles') as $index) {
                if ($index['type'] !== 'primary' && in_array('company_id', $index['columns'], true)) {
                    Schema::table('vehicles', fn (Blueprint $table) => $table->dropIndex($index['name']));
                }
            }
            Schema::table('vehicles', fn (Blueprint $table) => $table->dropColumn('company_id'));
        }

        Schema::dropIfExists('vehicle_companies');
    }

    public function down(): void
    {
        if (Schema::hasTable('vehicles') && ! Schema::hasColumn('vehicles', 'company_id')) {
            Schema::table('vehicles', fn (Blueprint $table) => $table->uuid('company_id')->nullable()->index());
        }

        if (! Schema::hasTable('vehicle_companies')) {
            Schema::create('vehicle_companies', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('vehicle_id')->nullable();
                $table->uuid('company_id')->nullable();
                $table->boolean('is_active')->default(true);
                $table->uuid('created_user_id')->nullable();
                $table->uuid('updated_user_id')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }
    }
};
