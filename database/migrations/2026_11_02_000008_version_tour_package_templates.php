<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tour_package_template_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('service_package_id')->constrained('service_packages')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->json('template');
            $table->uuid('created_user_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['service_package_id', 'version'], 'tour_pkg_template_version_unique');
        });
        Schema::table('service_packages', function (Blueprint $table): void {
            $table->unsignedInteger('tour_itinerary_template_version')->default(0);
        });
        Schema::table('tour_itineraries', function (Blueprint $table): void {
            $table->unsignedInteger('service_package_template_version')->nullable();
        });

        DB::table('service_packages')->whereNotNull('tour_itinerary_template')->orderBy('id')->each(function ($package): void {
            $versionId = (string) Str::uuid();
            DB::table('tour_package_template_versions')->insert([
                'id' => $versionId,
                'service_package_id' => $package->id,
                'version' => 1,
                'template' => $package->tour_itinerary_template,
                'created_user_id' => $package->updated_user_id ?? $package->created_user_id,
                'created_at' => now(),
            ]);
            DB::table('service_packages')->where('id', $package->id)->update(['tour_itinerary_template_version' => 1]);
        });
    }

    public function down(): void
    {
        if (DB::table('tour_package_template_versions')->exists() || DB::table('tour_itineraries')->whereNotNull('service_package_template_version')->exists()) {
            throw new RuntimeException('Preserve published tour template history before rolling back template versioning.');
        }

        Schema::table('tour_itineraries', fn(Blueprint $table) => $table->dropColumn('service_package_template_version'));
        Schema::table('service_packages', fn(Blueprint $table) => $table->dropColumn('tour_itinerary_template_version'));
        Schema::dropIfExists('tour_package_template_versions');
    }
};
