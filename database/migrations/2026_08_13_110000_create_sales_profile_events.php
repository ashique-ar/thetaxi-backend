<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_profile_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('sales_profile_id')->constrained('sales_profiles')->restrictOnDelete();
            $table->string('event_type', 60);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->text('reason');
            $table->foreignUuid('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['sales_profile_id', 'occurred_at']);
        });
        Schema::create('sales_reporting_assignment_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('sales_reporting_assignment_id')->constrained('sales_reporting_assignments')->restrictOnDelete();
            $table->string('event_type', 60);
            $table->json('before_snapshot')->nullable();
            $table->json('after_snapshot');
            $table->text('reason');
            $table->foreignUuid('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['sales_reporting_assignment_id', 'occurred_at'], 'sales_reporting_event_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_reporting_assignment_events');
        Schema::dropIfExists('sales_profile_events');
    }
};
