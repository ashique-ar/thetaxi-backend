<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_form_tabs', function (Blueprint $table) {
            $table->string('service_type_code')->nullable()->after('label');
        });
    }

    public function down(): void
    {
        Schema::table('booking_form_tabs', function (Blueprint $table) {
            $table->dropColumn('service_type_code');
        });
    }
};
