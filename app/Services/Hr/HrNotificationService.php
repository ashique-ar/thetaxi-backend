<?php

namespace App\Services\Hr;

use App\Models\Staff;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class HrNotificationService
{
    public function companyIsActive(string $companyId): bool
    {
        return DB::table('companies')->where('id', $companyId)
            ->where('is_active', true)->whereNull('deleted_at')->exists();
    }

    public function recipientMatchesCompany(string $companyId, string $staffId, string $userId): bool
    {
        return DB::table('staff')->join('users', 'users.id', '=', 'staff.user_id')
            ->where('staff.id', $staffId)->where('staff.company_id', $companyId)
            ->where('staff.user_id', $userId)->exists();
    }

    public function recipientIsActive(string $companyId, string $staffId, string $userId): bool
    {
        return DB::table('staff')->join('users', 'users.id', '=', 'staff.user_id')
            ->where('staff.id', $staffId)->where('staff.company_id', $companyId)
            ->where('staff.user_id', $userId)->whereNull('staff.employment_ended_at')
            ->where('users.is_active', true)->exists();
    }

    public function queue(string $companyId, string $eventType, string $sourceType, string $sourceId, string $staffId, array $variables): array
    {
        if (! $this->companyIsActive($companyId)) return [];

        $staff = Staff::query()->whereKey($staffId)->where('company_id', $companyId)
            ->whereNull('employment_ended_at')->first();
        if (!$staff || !$staff->user_id) return [];

        $user = DB::table('users')->where('id', $staff->user_id)->where('is_active', true)->first();
        if (!$user) return [];

        $rows = [];
        foreach (['in_app', 'email', 'sms'] as $channel) {
            $template = DB::table('hr_notification_template_versions')->where('company_id', $companyId)
                ->where('event_type', $eventType)->where('channel', $channel)->where('status', 'approved')
                ->whereDate('effective_from', '<=', now())
                ->where(fn ($query) => $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', now()))
                ->latest('version')->first();
            if (!$template) continue;

            $destination = $channel === 'email' ? ($user->email ?? null)
                : ($channel === 'sms' ? ($user->phone ?? null) : (string) $user->id);
            if (!$destination) continue;

            $preference = DB::table('hr_notification_preferences')->where('staff_id', $staffId)
                ->where('event_type', $eventType)->where('channel', $channel)->value('enabled');
            if (!$template->mandatory && $preference !== null && !(bool) $preference) continue;

            $allowed = json_decode($template->allowed_placeholders, true) ?: [];
            if (array_diff(array_keys($variables), $allowed)) {
                throw ValidationException::withMessages(['variables' => 'Notification variables exceed the approved template contract.']);
            }
            preg_match_all('/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/', (string) $template->subject_template.' '.(string) $template->body_template, $matches);
            if (array_diff(array_unique($matches[1]), array_keys($variables))) {
                throw ValidationException::withMessages(['variables' => 'Notification variables do not satisfy every required template placeholder.']);
            }

            $render = fn ($text) => preg_replace_callback(
                '/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/',
                fn ($match) => (string) $variables[$match[1]],
                (string) $text
            );
            $payload = [
                'destination' => $destination,
                'subject' => $render($template->subject_template),
                'body' => $render($template->body_template),
                'source_type' => $sourceType,
                'source_id' => $sourceId,
            ];
            $checksum = hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            $key = 'hr-notification:'.$template->id.':'.$sourceType.':'.$sourceId.':'.$staffId;
            $existing = DB::table('hr_notification_outbox')->where('idempotency_key', $key)->first();
            if ($existing) {
                abort_unless(hash_equals($existing->payload_checksum, $checksum), 409, 'Notification idempotency key was reused with different content.');
                $rows[] = $existing;
                continue;
            }

            $id = (string) Str::uuid();
            $outbox = [
                'id' => $id, 'company_id' => $companyId, 'template_version_id' => $template->id,
                'recipient_staff_id' => $staffId, 'recipient_user_id' => $staff->user_id,
                'event_type' => $eventType, 'channel' => $channel, 'source_type' => $sourceType,
                'source_id' => $sourceId, 'encrypted_rendered_payload' => encrypt($payload),
                'payload_checksum' => $checksum, 'idempotency_key' => $key, 'status' => 'queued',
                'available_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ];
            if (DB::table('hr_notification_outbox')->insertOrIgnore([$outbox]) !== 1) {
                $existing = DB::table('hr_notification_outbox')->where('idempotency_key', $key)->lockForUpdate()->first();
                abort_unless($existing && hash_equals($existing->payload_checksum, $checksum), 409, 'Notification idempotency key was reused with different content.');
                $rows[] = $existing;
                continue;
            }

            $rows[] = DB::table('hr_notification_outbox')->find($id);
        }

        return $rows;
    }
}
