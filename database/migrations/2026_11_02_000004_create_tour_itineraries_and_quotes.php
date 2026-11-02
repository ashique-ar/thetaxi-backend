<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tour_itineraries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('service_type_id')->constrained('service_types')->restrictOnDelete();
            $table->foreignUuid('service_package_id')->nullable()->constrained('service_packages')->restrictOnDelete();
            $table->foreignUuid('vehicle_class_id')->constrained('vehicle_classes')->restrictOnDelete();
            $table->date('start_date');
            $table->unsignedSmallInteger('duration_days');
            $table->unsignedSmallInteger('adult_count')->default(1);
            $table->unsignedSmallInteger('child_count')->default(0);
            $table->unsignedSmallInteger('luggage_count')->default(0);
            $table->string('currency', 3);
            $table->string('pricing_context', 16)->default('public');
            $table->unsignedInteger('revision')->default(1);
            $table->string('status', 24)->default('draft');
            $table->uuid('created_user_id')->nullable();
            $table->uuid('updated_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['user_id', 'updated_at'], 'tour_itin_owner_updated_idx');
            $table->index(['service_type_id', 'status'], 'tour_itin_service_status_idx');
        });

        Schema::create('tour_itinerary_days', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tour_itinerary_id')->constrained('tour_itineraries')->cascadeOnDelete();
            $table->unsignedSmallInteger('day_number');
            $table->date('service_date');
            $table->string('timezone', 64)->default('Asia/Colombo');
            $table->foreignUuid('start_destination_id')->nullable()->constrained('tour_destinations')->restrictOnDelete();
            $table->foreignUuid('end_destination_id')->nullable()->constrained('tour_destinations')->restrictOnDelete();
            $table->json('start_location_snapshot')->nullable();
            $table->json('end_location_snapshot')->nullable();
            $table->foreignUuid('overnight_destination_id')->nullable()->constrained('tour_destinations')->restrictOnDelete();
            $table->boolean('is_locked')->default(false);
            $table->uuid('created_user_id')->nullable();
            $table->uuid('updated_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tour_itinerary_id', 'day_number']);
        });

        Schema::create('tour_itinerary_stops', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tour_itinerary_day_id')->constrained('tour_itinerary_days')->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->string('kind', 16);
            $table->foreignUuid('tour_destination_id')->nullable()->constrained('tour_destinations')->restrictOnDelete();
            $table->foreignUuid('tour_activity_id')->nullable()->constrained('tour_activities')->restrictOnDelete();
            $table->json('location_snapshot');
            $table->unsignedSmallInteger('visit_duration_minutes')->default(0);
            $table->boolean('is_locked')->default(false);
            $table->time('arrival_time')->nullable();
            $table->uuid('created_user_id')->nullable();
            $table->uuid('updated_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tour_itinerary_day_id', 'sequence']);
            $table->index(['tour_destination_id', 'tour_activity_id'], 'tour_stop_catalogue_idx');
        });

        Schema::create('tour_quotes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tour_itinerary_id')->constrained('tour_itineraries')->restrictOnDelete();
            $table->unsignedInteger('itinerary_revision');
            $table->string('status', 24)->default('current');
            // Match the application's UTC-naive timestamps; timestamptz would reinterpret Eloquent's unzoned binding in the DB session timezone.
            $table->dateTime('expires_at')->nullable();
            $table->string('currency', 3);
            $table->decimal('total_amount', 14, 2);
            $table->decimal('distance_km', 12, 3);
            $table->json('route_snapshot');
            $table->json('pricing_snapshot');
            $table->dateTime('accepted_at')->nullable();
            $table->uuid('created_user_id')->nullable();
            $table->uuid('updated_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tour_itinerary_id', 'itinerary_revision'], 'tour_quote_itin_revision_idx');
            $table->index(['status', 'expires_at'], 'tour_quote_status_expiry_idx');
        });
    }

    public function down(): void
    {
        if (DB::table('tour_quotes')->exists() || DB::table('tour_itineraries')->exists()) {
            throw new RuntimeException('Preserve Tours itineraries and accepted quote snapshots before rolling back this schema.');
        }

        Schema::dropIfExists('tour_quotes');
        Schema::dropIfExists('tour_itinerary_stops');
        Schema::dropIfExists('tour_itinerary_days');
        Schema::dropIfExists('tour_itineraries');
    }
};
