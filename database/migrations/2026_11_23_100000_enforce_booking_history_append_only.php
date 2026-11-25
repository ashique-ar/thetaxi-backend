<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        switch (DB::connection()->getDriverName()) {
            case 'pgsql':
                $this->protectPostgres();
                return;
            case 'mysql':
            case 'mariadb':
                $this->protectMysql();
                return;
            case 'sqlsrv':
                $this->protectSqlServer();
                return;
            case 'sqlite':
                $this->protectSqlite();
                return;
            default:
                throw new LogicException('Booking history append-only guards are not implemented for this database driver.');
        }
    }

    private function protectPostgres(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION prevent_booking_history_event_mutation()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'booking_history_events is append-only';
            END;
            $$ LANGUAGE plpgsql
            SQL);

        DB::unprepared('DROP TRIGGER IF EXISTS booking_history_events_append_only ON booking_history_events');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER booking_history_events_append_only
            BEFORE UPDATE OR DELETE ON booking_history_events
            FOR EACH ROW EXECUTE FUNCTION prevent_booking_history_event_mutation()
            SQL);
    }

    private function protectMysql(): void
    {
        if (!$this->mysqlTriggerExists('booking_history_events_no_update')) {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER booking_history_events_no_update
                BEFORE UPDATE ON booking_history_events
                FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'booking_history_events is append-only'
                SQL);
        }
        if (!$this->mysqlTriggerExists('booking_history_events_no_delete')) {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER booking_history_events_no_delete
                BEFORE DELETE ON booking_history_events
                FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'booking_history_events is append-only'
                SQL);
        }
    }

    private function mysqlTriggerExists(string $name): bool
    {
        return DB::selectOne(
            'SELECT 1 FROM information_schema.triggers WHERE trigger_schema = DATABASE() AND event_object_table = ? AND trigger_name = ?',
            ['booking_history_events', $name],
        ) !== null;
    }

    private function protectSqlite(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER IF NOT EXISTS booking_history_events_no_update
            BEFORE UPDATE ON booking_history_events
            BEGIN
                SELECT RAISE(ABORT, 'booking_history_events is append-only');
            END
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER IF NOT EXISTS booking_history_events_no_delete
            BEFORE DELETE ON booking_history_events
            BEGIN
                SELECT RAISE(ABORT, 'booking_history_events is append-only');
            END
            SQL);
    }

    private function protectSqlServer(): void
    {
        $table = DB::connection()->getQueryGrammar()->wrapTable('booking_history_events');
        DB::unprepared(<<<SQL
            CREATE OR ALTER TRIGGER [booking_history_events_append_only]
            ON {$table}
            AFTER UPDATE, DELETE
            AS
            BEGIN
                SET NOCOUNT ON;
                THROW 51000, 'booking_history_events is append-only', 1;
            END
            SQL);
    }

    public function down(): void
    {
        throw new LogicException('Booking history append-only guards cannot be removed while the ledger is retained.');
    }
};
