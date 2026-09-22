<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        DB::table('notification_templates')->insertOrIgnore([
            'id' => (string) Str::uuid(), 'code' => 'corporate_payment_reminder', 'channel' => 'email',
            'subject' => 'Payment reminder for invoice {{invoice_number}}',
            'body' => '<p>Dear {{billing_name}},</p><p>This is a reminder that invoice <strong>{{invoice_number}}</strong> has an outstanding balance of {{currency}} {{outstanding_total}}.</p><p>Due date: {{due_date}}</p><p>Please quote {{invoice_number}} with your payment.</p>',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('notification_templates')->where('code', 'corporate_payment_reminder')->delete();
    }
};
