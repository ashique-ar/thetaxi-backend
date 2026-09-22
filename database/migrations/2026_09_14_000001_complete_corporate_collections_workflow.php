<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('corporate_remittances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('corporate_id')->index();
            $table->string('reference')->nullable()->index();
            $table->string('idempotency_key')->unique();
            $table->string('currency', 3);
            $table->string('payment_method');
            $table->decimal('amount', 12, 2);
            $table->decimal('allocated_amount', 12, 2)->default(0);
            $table->decimal('unapplied_amount', 12, 2)->default(0);
            $table->timestamp('received_at')->index();
            $table->text('notes')->nullable();
            $table->uuid('received_by')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('corporate_remittance_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('remittance_id')->index();
            $table->uuid('settlement_id')->index();
            $table->decimal('amount', 12, 2);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['remittance_id', 'settlement_id']);
        });

        Schema::table('financial_account_settlements', function (Blueprint $table) {
            $table->uuid('collection_owner_id')->nullable()->index()->after('payment_reference');
            $table->timestamp('collection_last_contact_at')->nullable()->after('collection_owner_id');
            $table->date('promised_payment_date')->nullable()->index()->after('collection_last_contact_at');
            $table->timestamp('next_follow_up_at')->nullable()->index()->after('promised_payment_date');
            $table->text('collection_notes')->nullable()->after('next_follow_up_at');
        });

        Schema::table('financial_adjustments', fn(Blueprint $table) => $table->json('metadata')->nullable()->after('reference'));

        foreach (['api', 'web'] as $guard) {
            foreach (['financial-settlements.view', 'financial-settlements.manage'] as $name) {
                DB::table('permissions')->insertOrIgnore(['name' => $name, 'guard_name' => $guard, 'created_at' => now(), 'updated_at' => now()]);
            }
            $permissionIds = DB::table('permissions')->where('guard_name', $guard)->whereIn('name', ['financial-settlements.view', 'financial-settlements.manage'])->pluck('id');
            $roleIds = DB::table('roles')->where('guard_name', $guard)->whereIn('name', ['sub-admin', 'accountant'])->pluck('id');
            foreach ($roleIds as $roleId)
                foreach ($permissionIds as $permissionId) {
                    DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
                }
        }
    }

    public function down(): void
    {
        Schema::table('financial_adjustments', fn(Blueprint $table) => $table->dropColumn('metadata'));
        Schema::table('financial_account_settlements', fn(Blueprint $table) => $table->dropColumn(['collection_owner_id', 'collection_last_contact_at', 'promised_payment_date', 'next_follow_up_at', 'collection_notes']));
        Schema::dropIfExists('corporate_remittance_allocations');
        Schema::dropIfExists('corporate_remittances');
    }
};
