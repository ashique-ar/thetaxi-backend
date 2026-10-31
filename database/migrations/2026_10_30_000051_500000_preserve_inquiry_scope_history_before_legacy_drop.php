<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $oldTable = 'inquiry_company_scope_events';
        $historyTable = 'inquiry_scope_history_events';

        if (! Schema::hasTable($oldTable)) {
            return;
        }

        if (Schema::hasTable($historyTable)) {
            throw new RuntimeException("Cannot preserve inquiry scope history; both {$oldTable} and {$historyTable} exist.");
        }

        Schema::rename($oldTable, $historyTable);
    }

    public function down(): void
    {
        throw new RuntimeException('Inquiry scope history cannot be safely restored to the obsolete company-scoped table.');
    }
};
