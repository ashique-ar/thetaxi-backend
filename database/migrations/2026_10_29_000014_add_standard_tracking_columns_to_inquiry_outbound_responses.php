<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inquiry_outbound_responses', function (Blueprint $table): void {
            $table->foreignUuid('created_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('inquiry_outbound_responses', function (Blueprint $table): void {
            $table->dropForeign(['created_user_id']);
            $table->dropForeign(['updated_user_id']);
            $table->dropColumn(['created_user_id', 'updated_user_id', 'deleted_at']);
        });
    }
};
