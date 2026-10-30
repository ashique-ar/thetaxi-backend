<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('corporates', 'company_id')) {
            Schema::table('corporates', function (Blueprint $table): void {
                $table->foreignUuid('company_id')->nullable()->after('id')->constrained('companies')->restrictOnDelete();
                $table->index('company_id', 'corporates_company_idx');
            });
        }

        if (! Schema::hasColumn('bookings', 'company_id')) {
            Schema::table('bookings', function (Blueprint $table): void {
                $table->foreignUuid('company_id')->nullable()->after('id')->constrained('companies')->restrictOnDelete();
                $table->index('company_id', 'bookings_company_idx');
            });
        }

        if (Schema::hasTable('sales_booking_attributions')) {
            DB::statement(<<<'SQL'
                UPDATE corporates AS corporate
                SET company_id = attributed.company_id
                FROM (
                    SELECT booking.corporate_account_id,
                           (array_agg(DISTINCT attribution.company_id))[1] AS company_id
                    FROM bookings AS booking
                    JOIN sales_booking_attributions AS attribution ON attribution.booking_id = booking.id
                    WHERE booking.corporate_account_id IS NOT NULL
                      AND attribution.company_id IS NOT NULL
                    GROUP BY booking.corporate_account_id
                    HAVING COUNT(DISTINCT attribution.company_id) = 1
                ) AS attributed
                WHERE corporate.id = attributed.corporate_account_id
                  AND corporate.company_id IS NULL
            SQL);
        }

        $companyId = DB::table('companies')
            ->where('is_active', true)
            ->where('is_default', true)
            ->whereNull('deleted_at')
            ->value('id');

        if (! $companyId && DB::table('companies')->where('is_active', true)->whereNull('deleted_at')->count() === 1) {
            $companyId = DB::table('companies')->where('is_active', true)->whereNull('deleted_at')->value('id');
        }

        if ($companyId) {
            DB::table('corporates')->whereNull('company_id')->update(['company_id' => $companyId]);
        }

        DB::statement(<<<'SQL'
            UPDATE bookings AS booking
            SET company_id = corporate.company_id
            FROM corporates AS corporate
            WHERE booking.corporate_account_id = corporate.id
              AND booking.company_id IS NULL
              AND corporate.company_id IS NOT NULL
        SQL);

        if (Schema::hasTable('sales_booking_attributions')) {
            DB::statement(<<<'SQL'
                UPDATE bookings AS booking
                SET company_id = evidence.company_id
                FROM (
                    SELECT attribution.booking_id,
                           (array_agg(DISTINCT attribution.company_id))[1] AS company_id
                    FROM sales_booking_attributions AS attribution
                    WHERE attribution.company_id IS NOT NULL
                    GROUP BY attribution.booking_id
                    HAVING COUNT(DISTINCT attribution.company_id) = 1
                ) AS evidence
                WHERE booking.id = evidence.booking_id
                  AND booking.company_id IS NULL
            SQL);
        }

        DB::statement(<<<'SQL'
            UPDATE bookings AS booking
            SET company_id = evidence.company_id
            FROM (
                SELECT candidates.booking_id,
                       (array_agg(DISTINCT candidates.company_id))[1] AS company_id
                FROM (
                    SELECT booking.id AS booking_id, attribution.company_id
                    FROM bookings AS booking
                    JOIN sales_booking_attributions AS attribution ON attribution.booking_id = booking.id
                    WHERE booking.company_id IS NULL AND booking.corporate_account_id IS NULL AND attribution.company_id IS NOT NULL
                    UNION ALL
                    SELECT booking.id AS booking_id, staff.company_id
                    FROM bookings AS booking
                    JOIN staff ON staff.user_id = booking.responsible_user_id
                    WHERE booking.company_id IS NULL AND booking.corporate_account_id IS NULL AND staff.company_id IS NOT NULL
                    UNION ALL
                    SELECT booking.id, staff.company_id
                    FROM bookings AS booking
                    JOIN staff ON staff.user_id = booking.created_by_user_id
                    WHERE booking.company_id IS NULL AND booking.corporate_account_id IS NULL AND staff.company_id IS NOT NULL
                    UNION ALL
                    SELECT booking.id, staff.company_id
                    FROM bookings AS booking
                    JOIN staff ON staff.id = booking.commission_owner_staff_id
                    WHERE booking.company_id IS NULL AND booking.corporate_account_id IS NULL AND staff.company_id IS NOT NULL
                    UNION ALL
                    SELECT booking.id, opportunity.company_id
                    FROM bookings AS booking
                    JOIN sales_opportunities AS opportunity ON opportunity.id = booking.sales_opportunity_id
                    WHERE booking.company_id IS NULL AND booking.corporate_account_id IS NULL AND opportunity.company_id IS NOT NULL
                    UNION ALL
                    SELECT booking.id, vehicle.company_id
                    FROM bookings AS booking
                    JOIN booking_items AS item ON item.booking_id = booking.id
                    JOIN vehicles AS vehicle ON vehicle.id = item.vehicle_id
                    WHERE booking.company_id IS NULL AND booking.corporate_account_id IS NULL AND vehicle.company_id IS NOT NULL
                    UNION ALL
                    SELECT booking.id, driver_staff.company_id
                    FROM bookings AS booking
                    JOIN booking_items AS item ON item.booking_id = booking.id
                    JOIN drivers AS driver ON driver.id = item.driver_id
                    JOIN staff AS driver_staff ON driver_staff.user_id = driver.user_id
                    WHERE booking.company_id IS NULL AND booking.corporate_account_id IS NULL AND driver_staff.company_id IS NOT NULL
                    UNION ALL
                    SELECT booking.id, company.id
                    FROM bookings AS booking
                    JOIN booking_items AS item ON item.booking_id = booking.id
                    JOIN service_types AS service ON service.id = item.service_type_id AND service.owner_type = 'company'
                    JOIN companies AS company ON company.id::text = service.owner_id
                    WHERE booking.company_id IS NULL AND booking.corporate_account_id IS NULL
                ) AS candidates
                GROUP BY candidates.booking_id
                HAVING COUNT(DISTINCT candidates.company_id) = 1
            ) AS evidence
            WHERE booking.id = evidence.booking_id
              AND booking.company_id IS NULL
              AND booking.corporate_account_id IS NULL
        SQL);
    }

    public function down(): void
    {
        if (Schema::hasColumn('bookings', 'company_id')) {
            Schema::table('bookings', function (Blueprint $table): void {
                $table->dropIndex('bookings_company_idx');
                $table->dropConstrainedForeignId('company_id');
            });
        }

        if (Schema::hasColumn('corporates', 'company_id')) {
            Schema::table('corporates', function (Blueprint $table): void {
                $table->dropIndex('corporates_company_idx');
                $table->dropConstrainedForeignId('company_id');
            });
        }
    }
};
