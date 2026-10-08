<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_performance_review_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('review_id')->constrained('hr_performance_reviews')->restrictOnDelete();
            $table->string('event_type', 60);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->json('evidence')->nullable();
            $table->foreignUuid('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('occurred_at');
            $table->index(['review_id', 'occurred_at']);
        });

        Schema::create('hr_achievement_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('achievement_id')->constrained('hr_achievements')->restrictOnDelete();
            $table->string('event_type', 60);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->text('reason')->nullable();
            $table->json('evidence')->nullable();
            $table->foreignUuid('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('occurred_at');
            $table->index(['achievement_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        foreach (['hr_achievement_events', 'hr_performance_review_events'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException("Rollback refused: export and reconcile talent/performance audit evidence from {$table} first.");
            }
        }

        Schema::dropIfExists('hr_achievement_events');
        Schema::dropIfExists('hr_performance_review_events');
    }
};
