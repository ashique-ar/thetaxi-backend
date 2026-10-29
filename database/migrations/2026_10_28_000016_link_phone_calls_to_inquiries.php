<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phone_calls', function (Blueprint $table): void {
            $table->foreignUuid('inquiry_id')->nullable()->after('booking_id')->constrained('inquiries')->restrictOnDelete();
            $table->unique('inquiry_id', 'phone_calls_inquiry_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('phone_calls', function (Blueprint $table): void {
            $table->dropUnique('phone_calls_inquiry_id_unique');
            $table->dropConstrainedForeignId('inquiry_id');
        });
    }
};
