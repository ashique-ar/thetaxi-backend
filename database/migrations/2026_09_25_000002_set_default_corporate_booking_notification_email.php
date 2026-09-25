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
        DB::table('corporates')
            ->where('booking_notification_emails', json_encode(['info@thetaxi.lk']))
            ->update(['booking_notification_emails' => null]);
    }
};
