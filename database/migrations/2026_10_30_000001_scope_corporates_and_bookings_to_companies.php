<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('corporates', 'company_id')) {
            Schema::table('corporates', function (Blueprint $table): void {
                $table->foreignUuid('company_id')->nullable()->after('id')->constrained('companies')->restrictOnDelete();
                $table->index('company_id', 'corporates_company_idx');
            });
        }

        if (! Schema::hasColumn('bookings', 'company_id')) {
            Schema::table('bookings', function (Blueprint $table): void {
                $table->foreignUuid('company_id')->nullable()->after('id')->constrained('companies')->restrictOnDelete();
                $table->index('company_id', 'bookings_company_idx');
            });
        }

        // Null company IDs remain legacy default-company records and are resolved at query time.
    }

    public function down(): void
    {
        if (Schema::hasColumn('bookings', 'company_id')) {
            Schema::table('bookings', function (Blueprint $table): void {
                $table->dropIndex('bookings_company_idx');
                $table->dropConstrainedForeignId('company_id');
            });
        }

        if (Schema::hasColumn('corporates', 'company_id')) {
            Schema::table('corporates', function (Blueprint $table): void {
                $table->dropIndex('corporates_company_idx');
                $table->dropConstrainedForeignId('company_id');
            });
        }
    }
};
