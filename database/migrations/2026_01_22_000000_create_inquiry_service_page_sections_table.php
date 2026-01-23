<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('inquiry_service_page_sections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('inquiry_service_page_id');
            $table->string('type', 100);
            $table->json('data')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->uuid('created_user_id')->nullable();
            $table->uuid('updated_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // Migrate existing page content sections into rows (best-effort)
        try {
            $pages = DB::table('inquiry_service_pages')->select('id', 'content')->get();
            foreach ($pages as $page) {
                $content = json_decode($page->content, true);
                if (is_array($content) && isset($content['sections']) && is_array($content['sections'])) {
                    foreach ($content['sections'] as $index => $section) {
                        DB::table('inquiry_service_page_sections')->insert([
                            'id' => (string) Str::uuid(),
                            'inquiry_service_page_id' => $page->id,
                            'type' => $section['type'] ?? 'unknown',
                            'data' => json_encode($section['data'] ?? ($section['data'] ?? $section)),
                            'sort_order' => $index + 1,
                            'is_active' => true,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            }
        } catch (\Exception $e) {
            // Migration should not fail if something unexpected exists in the data. Log and continue.
            logger()->error('Failed to migrate inquiry page sections: ' . $e->getMessage());
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inquiry_service_page_sections');
    }
};
