<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->date('license_issued_at')->nullable()->after('license_type');
            $table->unsignedSmallInteger('license_reminder_days')->default(30)->after('license_expiry');
            $table->date('license_last_reminded_on')->nullable()->after('license_reminder_days');
        });

        Schema::create('driver_license_renewals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('driver_id')->constrained('drivers')->cascadeOnDelete();
            $table->string('previous_license_no')->nullable();
            $table->date('previous_expiry')->nullable();
            $table->string('new_license_no');
            $table->date('new_issued_at')->nullable();
            $table->date('new_expiry');
            $table->foreignUuid('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('driver_license_renewals');
        Schema::table('drivers', fn (Blueprint $table) => $table->dropColumn(['license_issued_at', 'license_reminder_days', 'license_last_reminded_on']));
    }
};
