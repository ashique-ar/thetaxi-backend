<?php

namespace App\Services;

use App\Models\Inquiry;
use App\Models\User;
use App\Notifications\InquiryAssignedNotification;
use Illuminate\Support\Facades\Log;

class InquiryAssignmentNotificationService
{
    public function notifyAssigned(Inquiry $inquiry): void
    {
        $recipient = $inquiry->assigned_to
            ? app(InquiryAssignmentService::class)->eligibleAssignees()
                ->where('users.id', $inquiry->assigned_to)->first(['users.id'])
            : null;
        if (! $recipient) return;
        $userId = (string) $recipient->id;

        try {
            User::query()->whereKey($userId)->first()?->notify(new InquiryAssignedNotification($inquiry));
        } catch (\Throwable $exception) {
            Log::warning('Inquiry assignment notification could not be queued', [
                'inquiry_id' => $inquiry->id,
                'user_id' => $userId,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
