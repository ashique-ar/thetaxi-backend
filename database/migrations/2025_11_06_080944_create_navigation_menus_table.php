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
        Schema::create('navigation_menus', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('parent_id')->nullable();
            $table->string('title');
            $table->string('url')->nullable();
            $table->string('route_name')->nullable();
            $table->json('route_params')->nullable();
            $table->string('target')->default('_self'); // _self, _blank
            $table->string('icon')->nullable();
            $table->text('description')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('show_in_header')->default(true);
            $table->boolean('show_in_footer')->default(false);
            $table->json('permissions')->nullable();
            $table->uuid('created_user_id')->nullable();
            $table->uuid('updated_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['parent_id', 'is_active', 'sort_order']);
            $table->index(['show_in_header', 'is_active']);
            $table->index(['show_in_footer', 'is_active']);
        });
        
        // Add foreign key constraint separately after table creation
        Schema::table('navigation_menus', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('navigation_menus')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('navigation_menus');
    }
};
