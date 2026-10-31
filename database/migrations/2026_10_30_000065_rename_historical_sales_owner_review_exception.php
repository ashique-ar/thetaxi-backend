<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('sales_attribution_exceptions')) {
            return;
        }

        $conflict = DB::table('sales_attribution_exceptions as old')
            ->join('sales_attribution_exceptions as new', function ($join): void {
                $join->on('new.booking_id', '=', 'old.booking_id')
                    ->on('new.status', '=', 'old.status')
                    ->where('new.exception_type', '=', 'historical_owner_review_required');
            })
            ->where('old.exception_type', 'historical_company_owner_review_required')
            ->exists();
        if ($conflict) {
            throw new RuntimeException('Resolve duplicate historical Sales owner-review exceptions before renaming the company-specific type.');
        }

        DB::table('sales_attribution_exceptions')
            ->where('exception_type', 'historical_company_owner_review_required')
            ->update(['exception_type' => 'historical_owner_review_required']);
    }

    public function down(): void
    {
        throw new RuntimeException('Historical Sales owner-review exceptions cannot regain company-specific naming.');
    }
};
