<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! Schema::hasColumn('bookings', 'employee_id')) {
            return;
        }

        $columnType = DB::table('information_schema.columns')
            ->where('table_schema', DB::raw('current_schema()'))
            ->where('table_name', 'bookings')
            ->where('column_name', 'employee_id')
            ->value('udt_name');

        if ($columnType === 'uuid') {
            return;
        }

        $invalidEmployeeIds = DB::table('bookings')
            ->whereNotNull('employee_id')
            ->whereRaw("employee_id !~* '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'")
            ->count();

        if ($invalidEmployeeIds > 0) {
            throw new RuntimeException(
                "Cannot convert bookings.employee_id to uuid: {$invalidEmployeeIds} invalid value(s) require correction."
            );
        }

        DB::statement(
            'ALTER TABLE bookings ALTER COLUMN employee_id TYPE uuid USING employee_id::uuid'
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! Schema::hasColumn('bookings', 'employee_id')) {
            return;
        }

        DB::statement(
            'ALTER TABLE bookings ALTER COLUMN employee_id TYPE varchar(255) USING employee_id::text'
        );
    }
};
