<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('inquiry_service_pages', 'seo_og_image')) {
            Schema::table('inquiry_service_pages', function (Blueprint $table) {
                $table->string('seo_og_image')->nullable()->after('seo_description');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('inquiry_service_pages', 'seo_og_image')) {
            Schema::table('inquiry_service_pages', function (Blueprint $table) {
                $table->dropColumn('seo_og_image');
            });
        }
    }
};
