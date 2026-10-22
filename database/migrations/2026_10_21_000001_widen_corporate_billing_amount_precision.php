<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private array $amountColumns = [
        'bookings' => ['total_estimated', 'total_actual', 'base_amount', 'driver_cost', 'distance_cost', 'addons_cost', 'discount_amount', 'tax_amount', 'payment_collected_amount', 'payment_schedule_installment_amount'],
        'booking_items' => ['unit_price', 'total_price', 'price_override_amount'],
        'invoices' => ['subtotal', 'discount_amount', 'tax_amount', 'total_amount'],
        'booking_payment_receipts' => ['amount', 'refunded_amount', 'allocated_amount', 'driver_company_settled_amount'],
        'financial_account_settlements' => ['charges_total', 'payments_total', 'refunds_total', 'adjustments_total', 'outstanding_total'],
        'financial_settlement_items' => ['charge_amount', 'paid_before_amount', 'refund_amount', 'adjustment_amount', 'allocated_amount', 'outstanding_amount'],
        'financial_payment_allocations' => ['amount'],
        'financial_adjustments' => ['amount'],
        'financial_audit_events' => ['amount'],
        'corporate_billing_terms' => ['credit_limit'],
        'corporate_remittances' => ['amount', 'allocated_amount', 'unapplied_amount'],
        'corporate_remittance_allocations' => ['amount'],
        'financial_allocation_reversals' => ['amount'],
        'booking_payment_schedules' => ['amount'],
        'booking_payment_schedule_allocations' => ['amount'],
    ];

    public function up(): void
    {
        Schema::table('corporate_remittances', function ($table): void {
            $table->decimal('fx_rate_to_lkr', 20, 10)->nullable();
            $table->timestamp('fx_rate_at')->nullable();
            $table->string('fx_source', 120)->nullable();
        });
        $this->resize(20, 4);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            foreach ($this->amountColumns as $table => $columns) {
                foreach ($columns as $column) {
                    if (Schema::hasTable($table) && Schema::hasColumn($table, $column)
                        && DB::table($table)->whereRaw("{$column} <> ROUND({$column}, 2)")->exists()) {
                        throw new RuntimeException("Cannot reduce {$table}.{$column} precision while sub-cent amounts exist.");
                    }
                }
            }
        }

        $this->resize(12, 2);
        Schema::table('corporate_remittances', function ($table): void {
            $table->dropColumn(['fx_rate_to_lkr', 'fx_rate_at', 'fx_source']);
        });
    }

    private function resize(int $precision, int $scale): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'sqlite') {
            return;
        }

        foreach ($this->amountColumns as $table => $columns) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            $columns = array_values(array_filter($columns, fn (string $column): bool => Schema::hasColumn($table, $column)));
            if (!$columns) {
                continue;
            }

            $columnPrecision = $table === 'corporate_billing_terms' && $precision === 12 ? 14 : $precision;
            if ($driver === 'pgsql') {
                foreach ($columns as $column) {
                    DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} TYPE NUMERIC({$columnPrecision}, {$scale})");
                }
                continue;
            }

            if ($driver === 'mysql') {
                $metadata = collect(Schema::getColumns($table))->keyBy('name');
                Schema::table($table, function (Blueprint $blueprint) use ($columns, $columnPrecision, $scale, $metadata): void {
                    foreach ($columns as $column) {
                        $details = $metadata->get($column);
                        $definition = $blueprint->decimal($column, $columnPrecision, $scale)
                            ->nullable((bool) ($details['nullable'] ?? false));
                        if (($details['default'] ?? null) !== null) {
                            $definition->default($details['default']);
                        }
                        $definition->change();
                    }
                });
                continue;
            }

            throw new RuntimeException("Unsupported database driver for corporate amount precision: {$driver}");
        }
    }
};
