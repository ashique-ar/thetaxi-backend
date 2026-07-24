<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {

        DB::table('vehicle_lease_releases')
            ->where('settlement_status', 'settled')
            ->update([
                'settlement_direction' => DB::raw("
                    CASE
                        WHEN net_settlement_amount > 0 THEN 'payable_to_provider'
                        WHEN net_settlement_amount < 0 THEN 'receivable_from_provider'
                        ELSE 'none'
                    END
                "),
                'settlement_amount' => DB::raw('ABS(net_settlement_amount)'),
                'settlement_method' => DB::raw("COALESCE(settlement_method, 'legacy_unverified')"),
                'settlement_reference' => DB::raw("COALESCE(settlement_reference, 'LEGACY-' || id)"),
                'settlement_idempotency_key' => DB::raw('id'),
                'settled_at' => DB::raw('COALESCE(settled_at, effective_at, updated_at, CURRENT_TIMESTAMP)'),
            ]);

        Schema::table('vehicle_leases', function (Blueprint $table) {
            $table->date('down_payment_paid_date')->nullable()->after('down_payment');
            $table->string('down_payment_method', 40)->nullable()->after('down_payment_paid_date');
            $table->string('down_payment_reference')->nullable()->after('down_payment_method');
            $table->string('deposit_payment_method', 40)->nullable()->after('deposit_paid_date');
        });

        DB::table('vehicle_leases')
            ->whereNotNull('activated_at')
            ->where('down_payment', '>', 0)
            ->update([
                'down_payment_paid_date' => DB::raw('COALESCE(start_date, CAST(activated_at AS date))'),
                'down_payment_method' => 'legacy_unverified',
                'down_payment_reference' => DB::raw("'LEGACY-DOWN-' || id"),
            ]);

        DB::table('vehicle_leases')
            ->whereNotNull('activated_at')
            ->where('deposit_paid_amount', '>', 0)
            ->whereNull('deposit_payment_method')
            ->update(['deposit_payment_method' => 'legacy_unverified']);

        Schema::create('vehicle_lease_deposit_dispositions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('vehicle_lease_id')->constrained('vehicle_leases')->restrictOnDelete();
            $table->string('disposition_type', 40);
            $table->decimal('amount', 14, 2);
            $table->date('transaction_date');
            $table->string('payment_method', 40);
            $table->string('reference');
            $table->uuid('idempotency_key')->unique();
            $table->string('status', 30)->default('recorded')->index();
            $table->text('notes')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignUuid('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reversal_reason')->nullable();
            $table->foreignUuid('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('created_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(
                ['vehicle_lease_id', 'transaction_date'],
                'vehicle_lease_deposit_disposition_date_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_lease_deposit_dispositions');

        Schema::table('vehicle_leases', function (Blueprint $table) {
            $table->dropColumn([
                'down_payment_paid_date',
                'down_payment_method',
                'down_payment_reference',
                'deposit_payment_method',
            ]);
        });
    }
};
