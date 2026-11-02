<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tour_quotes', function (Blueprint $table): void {
            $table->json('terms_snapshot')->nullable();
            $table->string('accepted_terms_hash', 64)->nullable();
            $table->uuid('accepted_by_user_id')->nullable();
            $table->string('acceptance_channel', 24)->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('tour_quotes')->whereNotNull('accepted_terms_hash')->exists()) {
            throw new RuntimeException('Preserve accepted Tour terms evidence before rolling back this schema.');
        }

        Schema::table('tour_quotes', function (Blueprint $table): void {
            $table->dropColumn(['terms_snapshot', 'accepted_terms_hash', 'accepted_by_user_id', 'acceptance_channel']);
        });
    }
};
