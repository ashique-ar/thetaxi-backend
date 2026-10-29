<?php

namespace App\Services;

use App\Models\Inquiry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InquiryStatusHistoryService
{
    public function record(Inquiry $inquiry, ?string $from, ?string $to, ?string $actorUserId, ?string $reason = null): void
    {
        if ($from === $to) return;

        $now = now();
        DB::table('inquiry_status_events')->insert([
            'id' => (string) Str::uuid(),
            'company_id' => $inquiry->company_id,
            'inquiry_id' => $inquiry->id,
            'from_status' => $from,
            'to_status' => $to,
            'actor_user_id' => $actorUserId,
            'reason' => $reason ? Str::limit(trim($reason), 500, '') : null,
            'occurred_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
