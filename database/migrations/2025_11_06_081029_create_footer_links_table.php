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
        Schema::create('footer_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('footer_group_id')->nullable();
            $table->string('title');
            $table->string('url')->nullable();
            $table->string('route_name')->nullable();
            $table->json('route_params')->nullable();
            $table->string('target')->default('_self'); // _self, _blank
            $table->string('icon')->nullable();
            $table->text('description')->nullable();
            $table->string('footer_section')->default('links'); // links, social, legal, contact
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->json('additional_attributes')->nullable(); // for social links: class, data attributes
            $table->uuid('created_user_id')->nullable();
            $table->uuid('updated_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['footer_section', 'is_active', 'sort_order']);
            $table->index(['footer_group_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('footer_links');
    }
};
