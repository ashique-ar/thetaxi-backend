<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('predefined_locations', function (Blueprint $table): void {
            $table->string('google_place_id')->nullable()->index();
        });

        Schema::create('tour_destinations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('service_type_id')->constrained('service_types')->restrictOnDelete();
            $table->foreignUuid('predefined_location_id')->constrained('predefined_locations')->restrictOnDelete();
            $table->string('code', 100);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('timezone', 64)->default('Asia/Colombo');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_published')->default(false);
            $table->uuid('created_user_id')->nullable();
            $table->uuid('updated_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['service_type_id', 'code']);
            $table->index(['service_type_id', 'is_published', 'sort_order'], 'tour_dest_svc_pub_sort_idx');
        });

        Schema::create('tour_activities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tour_destination_id')->constrained('tour_destinations')->restrictOnDelete();
            $table->foreignUuid('predefined_location_id')->constrained('predefined_locations')->restrictOnDelete();
            $table->string('code', 100);
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('visit_duration_minutes')->default(0);
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_published')->default(false);
            $table->uuid('created_user_id')->nullable();
            $table->uuid('updated_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tour_destination_id', 'code']);
            $table->index(['tour_destination_id', 'is_published', 'sort_order'], 'tour_act_dest_pub_sort_idx');
        });
    }

    public function down(): void
    {
        if (DB::table('tour_activities')->exists() || DB::table('tour_destinations')->exists()) {
            throw new RuntimeException('Archive Tours catalogue content before rolling back its schema.');
        }
        if (DB::table('predefined_locations')->whereNotNull('google_place_id')->exists()) {
            throw new RuntimeException('Preserve mapped Google Place IDs before rolling back the Tours catalogue schema.');
        }

        Schema::dropIfExists('tour_activities');
        Schema::dropIfExists('tour_destinations');
        Schema::table('predefined_locations', function (Blueprint $table): void {
            $table->dropIndex(['google_place_id']);
            $table->dropColumn('google_place_id');
        });
    }
};
