<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('side_menus', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->string('icon')->nullable();   
            $table->text('description')->nullable();   
            $table->string('route_name')->nullable();     
            $table->string('permission_name')->nullable();
            $table->string('crud_master')->nullable();
            $table->uuid('parent_id')->nullable()->index();
            $table->integer('sort')->default(0);
            $table->uuid('created_user_id')->nullable()->index();
            $table->uuid('updated_user_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('side_menus');
    }
};
