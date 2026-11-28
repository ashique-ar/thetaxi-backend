<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tour_activities', function (Blueprint $table): void {
            $table->boolean('is_selectable')->default(false)->after('is_published');
        });
    }

    public function down(): void
    {
        Schema::table('tour_activities', function (Blueprint $table): void {
            $table->dropColumn('is_selectable');
        });
    }
};
