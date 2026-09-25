<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reference_number_registry', function (Blueprint $table): void {
            $table->id();
            $table->string('reference_code', 64)->unique('reference_number_registry_code_unique');
            $table->string('reference_type', 24);
            // Keep reservations if a company is later archived/deleted so its
            // old numbers can never be reused by another part of the system.
            $table->uuid('corporate_id')->nullable()->index();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reference_number_registry');
    }
};
