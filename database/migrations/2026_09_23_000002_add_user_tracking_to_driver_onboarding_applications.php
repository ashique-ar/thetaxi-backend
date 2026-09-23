<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_onboarding_applications', function (Blueprint $table): void {
            $table->uuid('created_user_id')->nullable()->index();
            $table->uuid('updated_user_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('driver_onboarding_applications', function (Blueprint $table): void {
            $table->dropColumn(['created_user_id', 'updated_user_id']);
        });
    }
};
