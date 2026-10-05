<?php

namespace App\Services\Sms;

use App\Models\Booking\BookingActivity;
use App\Models\Sms\SmsMessage;
use App\Services\SingleCompanyScope;

class BookingCommunicationActivityService
{
    public function record(array $data): BookingActivity
    {
        $defaultCompanyId = app(SingleCompanyScope::class)->defaultCompany()?->id;
        $companyId = array_key_exists('company_id', $data) ? $data['company_id'] : $defaultCompanyId;
        if (! $defaultCompanyId || $companyId !== $defaultCompanyId) {
            throw new \RuntimeException('Booking communication activity requires the active default company.');
        }

        $identity = (string) ($data['idempotency_key'] ?? hash('sha256', implode('|', [
            $data['booking_id'] ?? $data['inquiry_id'] ?? 'none',
            $data['event_key'],
            $data['result_status'],
            $data['recipient_masked'] ?? 'none',
        ])));

        $activity = BookingActivity::query()->firstOrCreate(
            ['idempotency_key' => $identity],
            [
                'company_id' => $companyId,
                'booking_id' => $data['booking_id'] ?? null,
                'booking_item_id' => $data['booking_item_id'] ?? null,
                'driver_assignment_id' => $data['driver_assignment_id'] ?? null,
                'inquiry_id' => $data['inquiry_id'] ?? null,
                'sms_message_id' => $data['sms_message_id'] ?? null,
                'event_key' => $data['event_key'],
                'channel' => $data['channel'] ?? 'sms',
                'result_status' => $data['result_status'],
                'source' => $data['source'] ?? 'automation',
                'recipient_masked' => $data['recipient_masked'] ?? null,
                'title' => $data['title'] ?? 'SMS automation decision',
                'detail' => $data['detail'] ?? null,
                'meta' => $data['meta'] ?? null,
                'event_at' => $data['event_at'] ?? now(),
            ]
        );
        if ($activity->company_id !== $companyId) {
            throw new \RuntimeException('This booking communication activity key belongs to another company.');
        }

        return $activity;
    }

    public function recordMessage(SmsMessage $message, string $resultStatus): BookingActivity
    {
        return $this->record([
            'company_id' => $message->company_id,
            'booking_id' => $message->booking_id,
            'booking_item_id' => $message->booking_item_id,
            'driver_assignment_id' => $message->driver_assignment_id,
            'inquiry_id' => $message->inquiry_id,
            'sms_message_id' => $message->id,
            'event_key' => $message->event_key,
            'result_status' => $resultStatus,
            'recipient_masked' => $this->mask((string) $message->normalized_recipient),
            'title' => 'Transactional SMS ' . str_replace('_', ' ', $resultStatus),
            'idempotency_key' => hash('sha256', 'sms-activity|' . $message->id . '|' . $resultStatus),
            'event_at' => $message->triggered_at ?? now(),
            'meta' => ['message_status' => $message->status],
        ]);
    }

    public function mask(string $number): ?string
    {
        $digits = preg_replace('/\D+/', '', $number);
        return $digits === '' ? null : str_repeat('*', max(0, strlen($digits) - 4)) . substr($digits, -4);
    }
}
