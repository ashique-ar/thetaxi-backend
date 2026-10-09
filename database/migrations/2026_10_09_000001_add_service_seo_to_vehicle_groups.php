<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('vehicle_groups', 'service_seo')) {
            Schema::table('vehicle_groups', function (Blueprint $table) {
                $table->json('service_seo')->nullable();
            });
        }
        Schema::table('vehicle_groups', function (Blueprint $table) {
            if (!Schema::hasColumn('vehicle_groups', 'seo_slug')) $table->string('seo_slug', 150)->nullable();
            if (!Schema::hasColumn('vehicle_groups', 'seo_title')) $table->string('seo_title', 70)->nullable();
            if (!Schema::hasColumn('vehicle_groups', 'seo_description')) $table->string('seo_description', 180)->nullable();
            if (!Schema::hasColumn('vehicle_groups', 'seo_target_terms')) $table->string('seo_target_terms', 500)->nullable();
            if (!Schema::hasColumn('vehicle_groups', 'seo_og_image')) $table->string('seo_og_image', 2048)->nullable();
        });
    }

    public function down(): void
    {
        $columns = ['service_seo', 'seo_slug', 'seo_title', 'seo_description', 'seo_target_terms', 'seo_og_image'];
        $existing = array_values(array_filter($columns, fn ($column) => Schema::hasColumn('vehicle_groups', $column)));
        if ($existing) Schema::table('vehicle_groups', fn (Blueprint $table) => $table->dropColumn($existing));
    }
};
