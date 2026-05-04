<?php

use App\Models\Customer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->mergeDuplicateCustomers();

        Schema::table('customers', function (Blueprint $table) {
            $table->unique('user_id', 'customers_user_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique('customers_user_id_unique');
        });
    }

    private function mergeDuplicateCustomers(): void
    {
        $duplicateUserIds = DB::table('customers')
            ->select('user_id')
            ->whereNotNull('user_id')
            ->groupBy('user_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('user_id');

        foreach ($duplicateUserIds as $userId) {
            $customers = Customer::withTrashed()
                ->where('user_id', $userId)
                ->orderByRaw('deleted_at IS NOT NULL')
                ->orderBy('created_at')
                ->get();

            $keeper = $customers->first();
            $duplicates = $customers->slice(1);

            foreach ($duplicates as $duplicate) {
                $this->moveCustomerReferences($duplicate->id, $keeper->id);
                $duplicate->forceDelete();
            }
        }
    }

    private function moveCustomerReferences(string $fromCustomerId, string $toCustomerId): void
    {
        $tables = [
            'bookings',
            'inquiries',
            'billing_addresses',
            'customer_loyalty_points',
            'loyalty_point_transactions',
            'booking_searches',
            'carts',
            'promo_code_usages',
        ];

        foreach ($tables as $table) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'customer_id')) {
                continue;
            }

            DB::table($table)
                ->where('customer_id', $fromCustomerId)
                ->update(['customer_id' => $toCustomerId]);
        }

        if (Schema::hasTable('user_contexts') && Schema::hasColumn('user_contexts', 'context_id')) {
            DB::table('user_contexts')
                ->where('context_type', 'customer')
                ->where('context_id', $fromCustomerId)
                ->update([
                    'context_id' => $toCustomerId,
                    'is_active' => false,
                ]);
        }
    }
};
