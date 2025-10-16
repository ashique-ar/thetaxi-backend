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
        Schema::dropIfExists('users');
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->string('first_name');
            $table->string('last_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('profile_image')->nullable();
            $table->uuid('role_id')->nullable()->index();
            $table->uuid('agent_id')->nullable()->index();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('phone_verified_at')->nullable();
            $table->uuid('created_user_id')->nullable()->index();
            $table->uuid('updated_user_id')->nullable()->index();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_verified')->default(false);

            $table->timestamp('last_login_at')->nullable()->after('is_active');
            $table->timestamp('password_changed_at')->nullable()->after('last_login_at');

            // Two-factor authentication fields
            $table->boolean('two_factor_enabled')->default(false)->after('password_changed_at');
            $table->text('two_factor_secret')->nullable()->after('two_factor_enabled');
            $table->json('two_factor_recovery_codes')->nullable()->after('two_factor_secret');

            // Device and preferences
            $table->string('device_token')->nullable()->after('two_factor_recovery_codes');
            $table->string('timezone', 50)->nullable()->after('device_token');
            $table->string('language', 10)->default('en')->after('timezone');

            // Security fields
            $table->integer('login_attempts')->default(0)->after('language');
            $table->timestamp('locked_until')->nullable()->after('login_attempts');

            // Social authentication fields
            $table->string('social_id')->nullable()->after('locked_until');
            $table->string('social_provider')->nullable()->after('social_id');
            $table->string('social_avatar')->nullable()->after('social_provider');


            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            // Indexes for performance
            $table->index(['email', 'is_active']);
            $table->index(['phone', 'is_active']);
            $table->index(['social_id', 'social_provider']);
            $table->index(['locked_until']);
            $table->index(['last_login_at']);

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
