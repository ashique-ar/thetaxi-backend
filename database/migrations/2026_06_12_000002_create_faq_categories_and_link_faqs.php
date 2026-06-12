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
        if (!Schema::hasTable('faq_categories')) {
            Schema::create('faq_categories', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('name');
                $table->string('slug')->unique();
                $table->text('description')->nullable();
                $table->integer('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();

                $table->index(['is_active', 'sort_order']);
            });
        }

        if (Schema::hasTable('faqs') && !Schema::hasColumn('faqs', 'faq_category_id')) {
            Schema::table('faqs', function (Blueprint $table) {
                $table->uuid('faq_category_id')->nullable();
                $table->index('faq_category_id');
            });
        }

        if (Schema::hasTable('faqs') && Schema::hasColumn('faqs', 'category')) {
            $categoryNames = DB::table('faqs')
                ->whereNotNull('category')
                ->where('category', '<>', '')
                ->distinct()
                ->pluck('category');

            foreach ($categoryNames as $index => $categoryName) {
                $slug = Str::slug($categoryName);
                $category = DB::table('faq_categories')->where('slug', $slug)->first();

                if (!$category) {
                    $categoryId = (string) Str::uuid();

                    DB::table('faq_categories')->insert([
                        'id' => $categoryId,
                        'name' => $categoryName,
                        'slug' => $slug,
                        'sort_order' => $index,
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } else {
                    $categoryId = $category->id;
                }

                DB::table('faqs')
                    ->where('category', $categoryName)
                    ->update(['faq_category_id' => $categoryId]);
            }
        }

        if (Schema::hasTable('faqs') && Schema::hasColumn('faqs', 'faq_category_id')) {
            Schema::table('faqs', function (Blueprint $table) {
                $table->foreign('faq_category_id')
                    ->references('id')
                    ->on('faq_categories')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('faqs') && Schema::hasColumn('faqs', 'faq_category_id')) {
            Schema::table('faqs', function (Blueprint $table) {
                $table->dropForeign(['faq_category_id']);
                $table->dropColumn('faq_category_id');
            });
        }

        Schema::dropIfExists('faq_categories');
    }
};
