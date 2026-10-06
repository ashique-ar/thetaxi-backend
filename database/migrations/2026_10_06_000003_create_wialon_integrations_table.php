<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('wialon_integrations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->unique();
            $table->text('token');
            $table->string('base_url')->default('https://hst-api.wialon.com');
            $table->json('resource_ids')->nullable();
            $table->json('unit_ids')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
        });
    }

    public function down(): void { Schema::dropIfExists('wialon_integrations'); }
};
