<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inquiries', function (Blueprint $table): void {
            $table->timestamp('first_response_saved_at')->nullable()->after('responded_at')->index();
            $table->foreignUuid('first_response_saved_by_user_id')->nullable()->after('first_response_saved_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inquiries', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('first_response_saved_by_user_id');
            $table->dropIndex(['first_response_saved_at']);
            $table->dropColumn('first_response_saved_at');
        });
    }
};
