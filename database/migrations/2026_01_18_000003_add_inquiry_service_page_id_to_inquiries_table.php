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
        Schema::table('inquiries', function (Blueprint $table) {
            $table->uuid('inquiry_service_page_id')->nullable()->index()->after('inquiry_type');

            $table->foreign('inquiry_service_page_id')
                ->references('id')
                ->on('inquiry_service_pages')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inquiries', function (Blueprint $table) {
            $table->dropForeign(['inquiry_service_page_id']);
            $table->dropColumn('inquiry_service_page_id');
        });
    }
};
