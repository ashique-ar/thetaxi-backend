<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('pending_payment_links', function (Blueprint $table): void {
            $table->timestamp('accepted_at')->nullable()->after('accessed_at');
        });
    }

    public function down(): void
    {
        Schema::table('pending_payment_links', function (Blueprint $table): void {
            $table->dropColumn('accepted_at');
        });
    }
};
