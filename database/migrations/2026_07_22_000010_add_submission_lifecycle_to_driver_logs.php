<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE driver_logs DROP CONSTRAINT IF EXISTS driver_logs_status_check');
            DB::statement("ALTER TABLE driver_logs ALTER COLUMN status TYPE VARCHAR(20)");
            DB::statement("ALTER TABLE driver_logs ALTER COLUMN status SET DEFAULT 'draft'");
            DB::statement("ALTER TABLE driver_logs ADD CONSTRAINT driver_logs_status_check CHECK (status IN ('draft','pending','approved','rejected'))");
        } elseif ($driver === 'mysql') {
            DB::statement("ALTER TABLE driver_logs MODIFY status ENUM('draft','pending','approved','rejected') NOT NULL DEFAULT 'draft'");
        } else {
            Schema::table('driver_logs', fn (Blueprint $table) => $table->string('status', 20)->default('draft')->change());
        }

        Schema::table('driver_logs', function (Blueprint $table): void {
            $table->foreignUuid('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->text('correction_notes')->nullable();
            $table->unsignedInteger('revision_number')->default(0);
        });

        DB::table('driver_logs')->where('status', 'pending')->whereNull('submitted_at')->update([
            'submitted_by' => DB::raw('created_user_id'),
            'submitted_at' => DB::raw('created_at'),
        ]);
    }

    public function down(): void
    {
        Schema::table('driver_logs', function (Blueprint $table): void {
            $table->dropForeign(['submitted_by']);
            $table->dropColumn(['submitted_by', 'submitted_at', 'correction_notes', 'revision_number']);
        });
    }
};
