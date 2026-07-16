<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('invoices', 'pdf_generated_at')) {
                $table->timestamp('pdf_generated_at')->nullable()->after('pdf_disk');
            }
            if (!Schema::hasColumn('invoices', 'pdf_last_error')) {
                $table->text('pdf_last_error')->nullable()->after('pdf_generated_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $columns = array_values(array_filter(
                ['pdf_generated_at', 'pdf_last_error'],
                fn (string $column): bool => Schema::hasColumn('invoices', $column)
            ));
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
