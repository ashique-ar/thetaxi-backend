<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('pending_payment_links', function (Blueprint $table): void {
            $table->unsignedInteger('revision_number')->default(1);
            $table->json('acceptance_evidence')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pending_payment_links', function (Blueprint $table): void {
            $table->dropColumn(['revision_number', 'acceptance_evidence']);
        });
    }
};
