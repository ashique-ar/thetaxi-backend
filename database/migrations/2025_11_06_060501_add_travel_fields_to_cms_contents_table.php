<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('cms_contents', function (Blueprint $table) {
            // Travel/Package specific fields for unified card templates
            if (!Schema::hasColumn('cms_contents', 'price')) {
                $table->decimal('price', 10, 2)->nullable()->after('excerpt');
            }
            if (!Schema::hasColumn('cms_contents', 'price_currency')) {
                $table->string('price_currency', 3)->default('USD')->after('price');
            }
            if (!Schema::hasColumn('cms_contents', 'duration')) {
                $table->string('duration')->nullable()->after('price_currency'); // e.g., "3 Days", "2 Hours"
            }
            if (!Schema::hasColumn('cms_contents', 'location')) {
                $table->string('location')->nullable()->after('duration');
            }
            if (!Schema::hasColumn('cms_contents', 'category')) {
                $table->string('category')->nullable()->after('location');
            }
            if (!Schema::hasColumn('cms_contents', 'difficulty_level')) {
                $table->enum('difficulty_level', ['easy', 'moderate', 'challenging', 'extreme'])->nullable()->after('category');
            }
            if (!Schema::hasColumn('cms_contents', 'rating')) {
                $table->decimal('rating', 2, 1)->default(0.0)->after('difficulty_level');
            }
            if (!Schema::hasColumn('cms_contents', 'reviews_count')) {
                $table->integer('reviews_count')->default(0)->after('rating');
            }
            if (!Schema::hasColumn('cms_contents', 'coordinates')) {
                $table->json('coordinates')->nullable()->after('reviews_count'); // {lat: x, lng: y}
            }
            if (!Schema::hasColumn('cms_contents', 'tags')) {
                $table->json('tags')->nullable()->after('coordinates'); // ["adventure", "family-friendly"]
            }
            if (!Schema::hasColumn('cms_contents', 'availability_status')) {
                $table->enum('availability_status', ['available', 'limited', 'sold_out', 'seasonal'])->default('available')->after('tags');
            }
            if (!Schema::hasColumn('cms_contents', 'special_offer')) {
                $table->boolean('special_offer')->default(false)->after('availability_status');
            }
            if (!Schema::hasColumn('cms_contents', 'discount_percentage')) {
                $table->integer('discount_percentage')->nullable()->after('special_offer');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cms_contents', function (Blueprint $table) {
            $columnsToRemove = [
                'price', 'price_currency', 'duration', 'location', 'category',
                'difficulty_level', 'rating', 'reviews_count', 'coordinates',
                'tags', 'availability_status', 'special_offer', 'discount_percentage'
            ];
            
            foreach ($columnsToRemove as $column) {
                if (Schema::hasColumn('cms_contents', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
