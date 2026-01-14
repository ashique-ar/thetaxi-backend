<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('inquiries', function (Blueprint $table) {
            $table->string('inquiry_number')->nullable()->unique()->after('id');
        });

        // Backfill existing records with sequential INQ numbers
        $prefix = 'INQ';
        $inquiries = DB::table('inquiries')->orderBy('created_at')->get();
        $seq = 1;
        foreach ($inquiries as $inq) {
            $number = $prefix . str_pad($seq, 6, '0', STR_PAD_LEFT);
            DB::table('inquiries')->where('id', $inq->id)->update(['inquiry_number' => $number]);
            $seq++;
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inquiries', function (Blueprint $table) {
            $table->dropColumn('inquiry_number');
        });
    }
};
