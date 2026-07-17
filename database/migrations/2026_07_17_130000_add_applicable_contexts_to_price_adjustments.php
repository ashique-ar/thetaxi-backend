<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('price_adjustments', function (Blueprint $table): void {
            $table->json('applicable_contexts')
                ->nullable()
                ->after('applies_to')
                ->comment('Portal, public, and/or corporate pricing contexts where this adjustment may run');
        });
    }

    public function down(): void
    {
        Schema::table('price_adjustments', function (Blueprint $table): void {
            $table->dropColumn('applicable_contexts');
        });
    }
};
