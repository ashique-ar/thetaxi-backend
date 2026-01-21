<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('inquiry_service_pages', 'canonical_url')) {
            Schema::table('inquiry_service_pages', function (Blueprint $table) {
                $table->string('canonical_url')->nullable()->after('seo_og_image');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('inquiry_service_pages', 'canonical_url')) {
            Schema::table('inquiry_service_pages', function (Blueprint $table) {
                $table->dropColumn('canonical_url');
            });
        }
    }
};
