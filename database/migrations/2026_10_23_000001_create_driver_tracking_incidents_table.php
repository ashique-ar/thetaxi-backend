<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('driver_tracking_incidents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('incident_id')->unique();
            $table->foreignUuid('driver_id')->constrained('drivers')->cascadeOnDelete();
            $table->foreignUuid('assignment_id')->nullable()->constrained('driver_assignments')->nullOnDelete();
            $table->foreignUuid('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->foreignUuid('booking_item_id')->nullable()->constrained('booking_items')->nullOnDelete();
            $table->string('state', 20);
            $table->string('cause', 50);
            $table->timestampTz('started_at');
            $table->timestampTz('reported_at');
            $table->timestampTz('recovered_at')->nullable();
            $table->json('first_evidence');
            $table->json('last_evidence');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['booking_id', 'booking_item_id', 'started_at']);
            $table->index(['driver_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_tracking_incidents');
    }
};
