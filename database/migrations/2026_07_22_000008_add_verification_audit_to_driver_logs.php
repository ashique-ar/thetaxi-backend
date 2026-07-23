<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_logs', function (Blueprint $table): void {
            $table->text('verification_notes')->nullable();
            $table->foreignUuid('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('driver_logs', function (Blueprint $table): void {
            $table->dropForeign(['verified_by']);
            $table->dropColumn(['verification_notes', 'verified_by', 'verified_at']);
        });
    }
};
