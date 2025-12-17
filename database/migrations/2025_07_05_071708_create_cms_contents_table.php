<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Schema::create('cms_contents', function (Blueprint $table) {
        //     $table->uuid('id')->primary();
        //     $table->uuid('cms_content_type_id')->index();
        //     $table->string('title')->nullable();
        //     $table->string('slug')->unique();
        //     $table->string('author')->unique();
        //     $table->string('thumbnail')->unique();
        //     $table->text('body')->nullable();
        //     $table->string('meta_title')->nullable();
        //     $table->text('meta_description')->nullable();
        //     $table->text('meta_tags')->nullable();
        //     $table->boolean('is_active')->default(true);
        //     $table->integer('display_order')->nullable();
        //     $table->string('url')->nullable();
        //     $table->uuid('created_user_id')->nullable()->index();
        //     $table->uuid('updated_user_id')->nullable()->index();
        //     $table->timestamps();
        //     $table->softDeletes();
        // });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cms_contents');
    }
};
