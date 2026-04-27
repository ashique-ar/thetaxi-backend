<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_assignment_stops', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('assignment_id');
            $table->uuid('booking_id')->nullable();
            $table->uuid('booking_item_id')->nullable();
            $table->string('stop_type', 20);
            $table->unsignedInteger('route_order');
            $table->string('status', 30)->default('pending');
            $table->json('location')->nullable();
            $table->string('label')->nullable();
            $table->text('address')->nullable();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->timestamp('arrived_at')->nullable();
            $table->decimal('arrived_latitude', 10, 8)->nullable();
            $table->decimal('arrived_longitude', 11, 8)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->decimal('completed_latitude', 10, 8)->nullable();
            $table->decimal('completed_longitude', 11, 8)->nullable();
            $table->string('completed_action', 30)->nullable();
            $table->text('skip_reason')->nullable();
            $table->text('notes')->nullable();
            $table->uuid('created_user_id')->nullable();
            $table->uuid('updated_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['assignment_id', 'route_order']);
            $table->index(['booking_id', 'booking_item_id']);
            $table->index(['assignment_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_assignment_stops');
    }
};
