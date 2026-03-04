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
        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('marketing_consent')->default(false)->after('email');
            $table->timestamp('marketing_consent_date')->nullable()->after('marketing_consent');
            $table->string('marketing_consent_ip')->nullable()->after('marketing_consent_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['marketing_consent', 'marketing_consent_date', 'marketing_consent_ip']);
        });
    }
};
