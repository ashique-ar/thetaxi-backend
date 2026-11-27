<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tour_destinations', function (Blueprint $table): void {
            $table->string('category', 80)->nullable()->after('description')->index();
        });
    }

    public function down(): void
    {
        Schema::table('tour_destinations', function (Blueprint $table): void {
            $table->dropIndex(['category']);
            $table->dropColumn('category');
        });
    }
};
