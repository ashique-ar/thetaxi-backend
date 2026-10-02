<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('hr_people_identity_links', fn (Blueprint $table) => $table->softDeletes());
    }

    public function down(): void
    {
        Schema::table('hr_people_identity_links', fn (Blueprint $table) => $table->dropSoftDeletes());
    }
};
