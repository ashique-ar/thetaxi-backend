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
        Schema::table('cms_contents', function (Blueprint $table) {
            $table->string('pickup_location')->nullable()->after('url');
            $table->string('dropoff_location')->nullable()->after('pickup_location');
            $table->string('service_type')->nullable()->after('dropoff_location');
            $table->date('pickup_date')->nullable()->after('service_type');
            $table->time('pickup_time')->nullable()->after('pickup_date');
            $table->date('dropoff_date')->nullable()->after('pickup_time');
            $table->time('dropoff_time')->nullable()->after('dropoff_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cms_contents', function (Blueprint $table) {
            $table->dropColumn([
                'pickup_location',
                'dropoff_location',
                'service_type',
                'pickup_date',
                'pickup_time',
                'dropoff_date',
                'dropoff_time',
            ]);
        });
    }
};

