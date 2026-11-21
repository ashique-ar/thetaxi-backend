<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_attribution_exceptions', function (Blueprint $table): void {
            $table->foreignUuid('created_user_id')->nullable()->after('detected_at')->constrained('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales_attribution_exceptions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('created_user_id');
        });
    }
};
