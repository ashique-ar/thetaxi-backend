<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('system_background_tasks', function (Blueprint $table): void {
            $table->index(['user_id', 'created_at'], 'system_background_tasks_user_created_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('system_background_tasks', function (Blueprint $table): void {
            $table->dropIndex('system_background_tasks_user_created_at_idx');
        });
    }
};
