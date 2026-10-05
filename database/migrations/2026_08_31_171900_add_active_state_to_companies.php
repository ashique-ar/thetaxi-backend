<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('companies', 'is_active')) {
            Schema::table('companies', function (Blueprint $table): void {
                $table->boolean('is_active')->default(true);
            });
        }

        DB::table('companies')->whereNull('is_active')->update(['is_active' => true]);
    }

    public function down(): void
    {
        // Keep company lifecycle state; application code and older governance migrations depend on it.
    }
};
