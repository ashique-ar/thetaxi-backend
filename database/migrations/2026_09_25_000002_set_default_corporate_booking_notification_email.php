<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('corporates')
            ->whereNull('booking_notification_emails')
            ->update(['booking_notification_emails' => json_encode(['info@thetaxi.lk'])]);
    }

    public function down(): void
    {
        $query = DB::table('corporates');
        if (DB::getDriverName() === 'pgsql') {
            $query->whereRaw('booking_notification_emails::jsonb = ?::jsonb', [json_encode(['info@thetaxi.lk'])]);
        } else {
            $query->where('booking_notification_emails', json_encode(['info@thetaxi.lk']));
        }
        $query->update(['booking_notification_emails' => null]);
    }
};
