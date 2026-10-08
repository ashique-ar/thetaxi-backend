<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_rehire_cases', function (Blueprint $table): void {
            $table->char('approval_payload_checksum', 64)->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('hr_rehire_cases')->whereNotNull('approval_payload_checksum')->exists()) {
            throw new RuntimeException('Rollback refused: export and reconcile rehire approval replay evidence first.');
        }

        Schema::table('hr_rehire_cases', function (Blueprint $table): void {
            $table->dropColumn('approval_payload_checksum');
        });
    }
};
