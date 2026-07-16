<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ACTIVE_BOOKING_INDEX = 'invoices_booking_active_unique';

    public function up(): void
    {
        if (!Schema::hasTable('invoices')) {
            return;
        }

        Schema::table('invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('invoices', 'email_sending_at')) {
                $table->timestamp('email_sending_at')->nullable();
            }
            if (!Schema::hasColumn('invoices', 'email_sent_at')) {
                $table->timestamp('email_sent_at')->nullable();
            }
            if (!Schema::hasColumn('invoices', 'email_attempts')) {
                $table->unsignedInteger('email_attempts')->default(0);
            }
            if (!Schema::hasColumn('invoices', 'email_last_error')) {
                $table->text('email_last_error')->nullable();
            }
        });

        $this->reconcileActiveDuplicates();
        $this->createActiveBookingUniqueIndex();
    }

    public function down(): void
    {
        if (!Schema::hasTable('invoices')) {
            return;
        }

        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::statement('DROP INDEX ' . self::ACTIVE_BOOKING_INDEX . ' ON invoices');
            if (Schema::hasColumn('invoices', 'active_booking_id')) {
                DB::statement('ALTER TABLE invoices DROP COLUMN active_booking_id');
            }
        } elseif (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS ' . self::ACTIVE_BOOKING_INDEX);
        }

        Schema::table('invoices', function (Blueprint $table) {
            $columns = collect([
                'email_sending_at',
                'email_sent_at',
                'email_attempts',
                'email_last_error',
            ])->filter(fn (string $column) => Schema::hasColumn('invoices', $column))->all();

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }

    private function reconcileActiveDuplicates(): void
    {
        $duplicateBookingIds = DB::table('invoices')
            ->select('booking_id')
            ->whereNull('deleted_at')
            ->where('status', '!=', 'void')
            ->groupBy('booking_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('booking_id');

        foreach ($duplicateBookingIds as $bookingId) {
            $rows = DB::table('invoices')
                ->where('booking_id', $bookingId)
                ->whereNull('deleted_at')
                ->where('status', '!=', 'void')
                ->orderByDesc('created_at')
                ->get();

            $paid = $rows->where('status', 'paid');
            if ($paid->count() > 1) {
                throw new RuntimeException(
                    "Booking {$bookingId} has multiple paid invoices. Reconcile them before applying invoice idempotency."
                );
            }

            $linkedNumber = null;
            if (Schema::hasTable('bookings') && Schema::hasColumn('bookings', 'invoice_number')) {
                $linkedNumber = DB::table('bookings')->where('id', $bookingId)->value('invoice_number');
            }

            $canonical = $paid->first()
                ?? ($linkedNumber ? $rows->firstWhere('invoice_number', $linkedNumber) : null)
                ?? $rows->first();

            foreach ($rows->where('id', '!=', $canonical->id) as $duplicate) {
                $auditNote = "Automatically voided as a duplicate of {$canonical->invoice_number} during invoice idempotency migration.";
                $notes = trim(implode(PHP_EOL, array_filter([(string) ($duplicate->notes ?? ''), $auditNote])));

                DB::table('invoices')->where('id', $duplicate->id)->update([
                    'status' => 'void',
                    'notes' => $notes,
                    'updated_at' => now(),
                ]);
            }

            if (Schema::hasTable('bookings') && Schema::hasColumn('bookings', 'invoice_number')) {
                DB::table('bookings')->where('id', $bookingId)->update([
                    'invoice_number' => $canonical->invoice_number,
                    'updated_at' => now(),
                ]);
            }
        }
    }

    private function createActiveBookingUniqueIndex(): void
    {
        $driver = DB::getDriverName();

        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement(
                "CREATE UNIQUE INDEX IF NOT EXISTS " . self::ACTIVE_BOOKING_INDEX
                . " ON invoices (booking_id) WHERE deleted_at IS NULL AND status <> 'void'"
            );
            return;
        }

        if ($driver === 'mysql') {
            if (!Schema::hasColumn('invoices', 'active_booking_id')) {
                DB::statement(
                    "ALTER TABLE invoices ADD COLUMN active_booking_id CHAR(36) "
                    . "GENERATED ALWAYS AS (CASE WHEN deleted_at IS NULL AND status <> 'void' THEN booking_id ELSE NULL END) STORED"
                );
            }
            DB::statement('CREATE UNIQUE INDEX ' . self::ACTIVE_BOOKING_INDEX . ' ON invoices (active_booking_id)');
        }
    }
};
