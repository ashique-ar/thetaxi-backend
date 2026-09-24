<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        foreach (['priority', 'response', 'responded_at', 'notes'] as $column) {
            if (Schema::hasColumn('inquiries', $column)) {
                continue;
            }
            Schema::table('inquiries', function (Blueprint $table) use ($column): void {
                match ($column) {
                    'priority' => $table->string('priority', 50)->nullable(),
                    'response', 'notes' => $table->text($column)->nullable(),
                    'responded_at' => $table->timestamp('responded_at')->nullable(),
                };
            });
        }
    }

    public function down(): void
    {
        foreach (['notes', 'responded_at', 'response', 'priority'] as $column) {
            if (Schema::hasColumn('inquiries', $column)) {
                Schema::table('inquiries', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
