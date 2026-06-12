<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_owners', function (Blueprint $table) {
            if (!Schema::hasColumn('vehicle_owners', 'nic')) {
                $table->string('nic', 20)->nullable()->after('user_id')->index();
            }
        });

        Schema::table('staff', function (Blueprint $table) {
            if (!Schema::hasColumn('staff', 'nic')) {
                $table->string('nic', 20)->nullable()->after('code')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_owners', function (Blueprint $table) {
            if (Schema::hasColumn('vehicle_owners', 'nic')) {
                $table->dropColumn('nic');
            }
        });

        Schema::table('staff', function (Blueprint $table) {
            if (Schema::hasColumn('staff', 'nic')) {
                $table->dropColumn('nic');
            }
        });
    }
};
