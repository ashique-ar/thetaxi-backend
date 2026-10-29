<?php

namespace App\Services;

use App\Models\Inquiry;
use App\Models\IntegrationConnection;
use App\Models\IntegrationWebhookEvent;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RespondIoInquiryAssignmentSync
{
    public function sync(Inquiry $inquiry): array
    {
        if ($inquiry->source !== 'respond_io') {
            return ['status' => 'not_applicable'];
        }

        $connection = IntegrationConnection::query()->where('company_id', $inquiry->company_id)
            ->where('provider', 'respond_io')->where('enabled', true)->first();
        if (! $connection?->respond_io_api_token) {
            return ['status' => 'not_configured'];
        }

        $contactId = IntegrationWebhookEvent::query()->where('company_id', $inquiry->company_id)
            ->where('connection_id', $connection->id)->where('provider', 'respond_io')
            ->where('linked_inquiry_id', $inquiry->id)->whereNotNull('external_contact_id')
            ->latest('received_at')->value('external_contact_id');
        if (! $contactId) {
            return ['status' => 'missing_contact'];
        }

        $email = trim((string) $inquiry->assignedUser()->value('email'));
        if ($email === '') {
            return ['status' => 'missing_employee_email'];
        }

        try {
            $response = Http::withToken($connection->respond_io_api_token)->acceptJson()->timeout(10)
                ->post('https://api.respond.io/v2/contact/'.rawurlencode('id:'.$contactId).'/conversation/assignee', [
                    'assignee' => $email,
                ]);
        } catch (\Throwable $exception) {
            Log::warning('Respond.io inquiry assignee sync request failed', [
                'connection_id' => $connection->id,
                'inquiry_id' => $inquiry->id,
                'error' => $exception->getMessage(),
            ]);
            return ['status' => 'failed'];
        }

        if (! $response->successful()) {
            Log::warning('Respond.io inquiry assignee sync was rejected', [
                'connection_id' => $connection->id,
                'inquiry_id' => $inquiry->id,
                'http_status' => $response->status(),
            ]);
            return ['status' => 'failed'];
        }

        return ['status' => 'synced'];
    }
}
