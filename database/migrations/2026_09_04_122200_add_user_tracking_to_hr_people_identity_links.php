<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('hr_people_identity_links', function (Blueprint $table) {
            $table->foreignUuid('created_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignUuid('updated_user_id')->nullable()->constrained('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('hr_people_identity_links')->where(function ($query): void {
            $query->whereNotNull('created_user_id')->orWhereNotNull('updated_user_id');
        })->exists()) {
            throw new \RuntimeException('Rollback refused: export and reconcile identity-link actor evidence first.');
        }

        Schema::table('hr_people_identity_links', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_user_id');
            $table->dropConstrainedForeignId('updated_user_id');
        });
    }
};
