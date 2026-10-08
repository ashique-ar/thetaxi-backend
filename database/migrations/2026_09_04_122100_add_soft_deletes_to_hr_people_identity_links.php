<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('hr_people_identity_links', fn (Blueprint $table) => $table->softDeletes());
    }

    public function down(): void
    {
        if (DB::table('hr_people_identity_links')->whereNotNull('deleted_at')->exists()) {
            throw new \RuntimeException('Rollback refused: export and reconcile identity-link deletion evidence first.');
        }

        Schema::table('hr_people_identity_links', fn (Blueprint $table) => $table->dropSoftDeletes());
    }
};
