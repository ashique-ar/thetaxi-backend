<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
        Schema::table('hr_people_identity_links', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_user_id');
            $table->dropConstrainedForeignId('updated_user_id');
        });
    }
};
