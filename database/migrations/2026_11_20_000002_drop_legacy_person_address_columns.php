<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['customers', 'staff', 'drivers'] as $tableName) {
            if (Schema::hasColumn($tableName, 'address')) {
                Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('address'));
            }
        }
    }

    public function down(): void
    {
        foreach (['customers', 'staff', 'drivers'] as $tableName) {
            if (! Schema::hasColumn($tableName, 'address')) {
                Schema::table($tableName, fn (Blueprint $table) => $table->string('address')->nullable());
            }
            if (Schema::hasTable('addresses')) {
                $type = match ($tableName) {
                    'customers' => \App\Models\Customer::class,
                    'staff' => \App\Models\Staff::class,
                    default => \App\Models\Driver\Driver::class,
                };
                DB::table('addresses')->where('addressable_type', $type)->where('is_default', true)
                    ->orderBy('id')->chunkById(500, fn ($rows) => $rows->each(fn ($row) => DB::table($tableName)->where('id', $row->addressable_id)->update(['address' => $row->address])));
            }
        }
    }

};
