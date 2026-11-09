<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('service_types', 'requires_refundable_deposit')) {
            Schema::table('service_types', function (Blueprint $table): void {
                $table->boolean('requires_refundable_deposit')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('service_types', 'requires_refundable_deposit')) {
            Schema::table('service_types', function (Blueprint $table): void {
                $table->dropColumn('requires_refundable_deposit');
            });
        }
    }
};
