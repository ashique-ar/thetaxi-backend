<?php

namespace App\Services\Sales;

use App\Support\Foundation\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class SalesCommissionNotificationService
{
    private const EVENT_LABELS = [
        'sales.commission.held' => 'Commission hold',
        'sales.commission.hold_released' => 'Commission hold released',
    ];

    public function __construct(private readonly SalesPolicySettingsService $policySettings) {}

    public function queueFromDomainEvent(object $event): void
    {
        if (! Schema::hasTable('sales_commission_notification_deliveries')) {
            return;
        }

        $notificationEvent = $this->notificationEvent($event);
        if ($notificationEvent === null) {
            return;
        }

        DB::transaction(function () use ($event, $notificationEvent) {
            $existing = DB::table('sales_commission_notification_deliveries')
                ->where('source_outbox_event_id', $event->id)
                ->where('channel', 'in_app')
                ->where('recipient_scope', 'beneficiary')
                ->lockForUpdate()
                ->first();
            if ($existing?->status === 'delivered') {
                return;
            }

            $decision = DB::table('sales_commission_decisions')->where('id', $notificationEvent['decision_id'])->first();
            if (! $decision || ! $decision->company_id || $decision->company_id !== $event->companyId) {
                return;
            }

            $id = $existing?->id ?? (string) Str::uuid();
            $base = [
                'company_id' => $decision->company_id,
                'source_outbox_event_id' => $event->id,
                'commission_decision_id' => $decision->id,
                'commission_hold_release_id' => $notificationEvent['release_id'],
                'event_type' => $notificationEvent['event_type'],
                'channel' => 'in_app',
                'recipient_scope' => 'beneficiary',
                'idempotency_key' => 'sales-commission-notification:'.$event->id.':in_app:beneficiary',
                'updated_at' => now(),
            ];

            if (! $this->policySettings->featureEnabled((string) $decision->company_id, 'commission_notifications')) {
                $this->persist($id, $existing, $base, $this->blocked('feature_disabled'));
                return;
            }

            $policies = DB::table('sales_commission_notification_policy_versions')
                ->where('company_id', $decision->company_id)
                ->where('event_type', $notificationEvent['event_type'])
                ->where('channel', 'in_app')
                ->where('recipient_scope', 'beneficiary')
                ->where('status', 'approved')
                ->whereNotNull('approved_by')
                ->whereNotNull('approved_at')
                ->where('effective_from', '<=', $event->occurredAt)
                ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>', $event->occurredAt))
                ->get();
            if ($policies->count() !== 1) {
                $this->persist($id, $existing, $base, $this->blocked($policies->isEmpty() ? 'approved_policy_missing' : 'approved_policy_ambiguous'));
                return;
            }

            $policy = $policies->first();
            if (! $this->validPolicyChecksum($policy)) {
                $this->persist($id, $existing, $base, $this->blocked('policy_checksum_invalid'));
                return;
            }

            $identity = $this->currentBeneficiaryIdentity($decision);
            if ($identity === null) {
                $this->persist($id, $existing, $base + ['policy_version_id' => $policy->id], $this->blocked('recipient_not_current'));
                return;
            }

            if (! $policy->mandatory) {
                $preference = DB::table('sales_commission_notification_preferences')
                    ->where('sales_profile_id', $identity->profile_id)
                    ->where('staff_id', $identity->staff_id)
                    ->where('event_type', $notificationEvent['event_type'])
                    ->where('channel', 'in_app')
                    ->value('enabled');
                if ($preference !== 1 && $preference !== true) {
                    $this->persist($id, $existing, $base + $this->identityColumns($identity) + ['policy_version_id' => $policy->id],
                        $this->blocked($preference === null ? 'explicit_preference_missing' : 'recipient_opted_out'));
                    return;
                }
            }

            $rendered = $this->render($policy, $notificationEvent['event_type']);
            if ($rendered === null) {
                $this->persist($id, $existing, $base + $this->identityColumns($identity) + ['policy_version_id' => $policy->id],
                    $this->blocked('template_contract_invalid'));
                return;
            }
            $checksum = hash('sha256', CanonicalJson::encode($rendered));
            $this->persist($id, $existing, $base + $this->identityColumns($identity) + [
                'policy_version_id' => $policy->id,
                'status' => 'queued',
                'blocked_code' => null,
                'encrypted_rendered_payload' => encrypt($rendered),
                'payload_checksum' => $checksum,
                'available_at' => now(),
                'last_error' => null,
            ]);
        });
    }

    public function refreshBlocked(int $limit = 100): int
    {
        if (! config('sales.features.commission_notifications', false)
            || ! Schema::hasTable('sales_commission_notification_deliveries')
            || ! Schema::hasTable('sales_company_feature_settings')) {
            return 0;
        }

        $rows = DB::table('sales_commission_notification_deliveries')
            ->whereIn('status', ['blocked_configuration', 'blocked_recipient', 'blocked_preference'])
            ->where('blocked_code', '!=', 'feature_disabled')
            ->whereIn('company_id', $this->enabledCompanyIds())
            ->oldest('created_at')->limit($limit)->get();
        foreach ($rows as $row) {
            $event = DB::table('domain_outbox_events')->where('id', $row->source_outbox_event_id)->first();
            if ($event) {
                $this->queueFromDomainEvent($this->eventObject($event));
            }
        }

        return $rows->count();
    }

    public function countDue(): int
    {
        return Schema::hasTable('sales_commission_notification_deliveries')
            && Schema::hasTable('sales_company_feature_settings')
            && config('sales.features.commission_notifications', false)
            ? DB::table('sales_commission_notification_deliveries')->where('status', 'queued')
                ->whereIn('company_id', $this->enabledCompanyIds())->where('available_at', '<=', now())->count()
            : 0;
    }

    public function deliverDue(int $limit = 100): int
    {
        if (! config('sales.features.commission_notifications', false)
            || ! Schema::hasTable('sales_commission_notification_deliveries')
            || ! Schema::hasTable('sales_company_feature_settings')) {
            return 0;
        }

        $ids = DB::table('sales_commission_notification_deliveries')->where('status', 'queued')
            ->whereIn('company_id', $this->enabledCompanyIds())
            ->where('available_at', '<=', now())->oldest('available_at')->limit($limit)->pluck('id');
        $delivered = 0;
        foreach ($ids as $id) {
            try {
                $delivered += DB::transaction(fn () => $this->deliverOne($id));
            } catch (Throwable $exception) {
                DB::transaction(function () use ($id, $exception) {
                    $row = DB::table('sales_commission_notification_deliveries')->where('id', $id)->lockForUpdate()->first();
                    if (! $row || $row->status !== 'queued') {
                        return;
                    }
                    $attempt = ((int) $row->attempt_count) + 1;
                    DB::table('sales_commission_notification_deliveries')->where('id', $id)->update([
                        'attempt_count' => $attempt,
                        'available_at' => now()->addMinutes(5),
                        'last_error' => Str::limit($exception->getMessage(), 1000),
                        'updated_at' => now(),
                    ]);
                    $this->appendEvent($id, 'retry_scheduled', $attempt, 'queued', 'delivery_failure', $row->payload_checksum);
                });
            }
        }

        return $delivered;
    }

    public function latestStatus(string $decisionId): ?array
    {
        if (! Schema::hasTable('sales_commission_notification_deliveries')) {
            return null;
        }
        $row = DB::table('sales_commission_notification_deliveries')->where('commission_decision_id', $decisionId)
            ->latest('created_at')->first();

        return $row ? [
            'event_type' => $row->event_type,
            'channel' => $row->channel,
            'status' => $row->status,
            'blocked_code' => $row->blocked_code,
            'attempt_count' => (int) $row->attempt_count,
            'delivered_at' => $row->delivered_at,
        ] : null;
    }

    private function deliverOne(string $id): int
    {
        $row = DB::table('sales_commission_notification_deliveries')->where('id', $id)->lockForUpdate()->first();
        if (! $row || $row->status !== 'queued') {
            return 0;
        }
        if (! $this->policySettings->featureEnabled((string) $row->company_id, 'commission_notifications')) {
            return 0;
        }
        $decision = DB::table('sales_commission_decisions')->where('id', $row->commission_decision_id)->first();
        $identity = $decision ? $this->currentBeneficiaryIdentity($decision) : null;
        if (! $identity || $identity->profile_id !== $row->recipient_sales_profile_id
            || $identity->staff_id !== $row->recipient_staff_id || $identity->user_id !== $row->recipient_user_id) {
            $this->transition($row, 'cancelled_scope', 'recipient_scope_changed');
            return 0;
        }

        $policy = DB::table('sales_commission_notification_policy_versions')->where('id', $row->policy_version_id)->first();
        if (! $policy || ! $policy->mandatory) {
            $enabled = DB::table('sales_commission_notification_preferences')->where('sales_profile_id', $identity->profile_id)
                ->where('event_type', $row->event_type)->where('channel', $row->channel)->value('enabled');
            if ($enabled !== 1 && $enabled !== true) {
                $this->transition($row, 'cancelled_preference', 'preference_not_enabled');
                return 0;
            }
        }

        $payload = decrypt($row->encrypted_rendered_payload);
        $checksum = hash('sha256', CanonicalJson::encode($payload));
        if (! $row->payload_checksum || ! hash_equals($row->payload_checksum, $checksum)) {
            throw new \RuntimeException('Commission notification payload checksum mismatch.');
        }

        $notificationId = $this->deterministicUuid($row->id);
        DB::table('notifications')->insertOrIgnore([
            'id' => $notificationId,
            'type' => 'App\\Notifications\\SalesCommissionGovernedNotification',
            'notifiable_type' => 'App\\Models\\User',
            'notifiable_id' => $identity->user_id,
            'data' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $attempt = ((int) $row->attempt_count) + 1;
        DB::table('sales_commission_notification_deliveries')->where('id', $row->id)->update([
            'status' => 'delivered', 'attempt_count' => $attempt, 'delivered_at' => now(),
            'database_notification_id' => $notificationId, 'last_error' => null, 'updated_at' => now(),
        ]);
        $this->appendEvent($row->id, 'delivered', $attempt, 'delivered', null, $row->payload_checksum);

        return 1;
    }

    private function notificationEvent(object $event): ?array
    {
        if ($event->eventType === 'sales.commission.decided') {
            $status = $event->payload['status'] ?? null;
            if (! in_array($status, ['held', 'shadow_held'], true)) {
                return null;
            }
            return ['event_type' => 'sales.commission.held', 'decision_id' => $event->aggregateId, 'release_id' => null];
        }
        if ($event->eventType === 'sales.commission.hold_released' && isset($event->payload['commission_decision_id'])) {
            return ['event_type' => $event->eventType, 'decision_id' => $event->payload['commission_decision_id'], 'release_id' => $event->aggregateId];
        }
        return null;
    }

    private function currentBeneficiaryIdentity(object $decision): ?object
    {
        if (! $decision->beneficiary_sales_profile_id || ! $decision->beneficiary_staff_id) {
            return null;
        }
        return DB::table('sales_profiles as profile')->join('staff as staff', 'staff.id', '=', 'profile.staff_id')
            ->join('users as user', 'user.id', '=', 'staff.user_id')
            ->where('profile.id', $decision->beneficiary_sales_profile_id)
            ->where('profile.staff_id', $decision->beneficiary_staff_id)
            ->where('profile.company_id', $decision->company_id)
            ->where('staff.company_id', $decision->company_id)
            ->where('profile.status', 'active')->where('profile.commission_eligible', true)
            ->where('profile.effective_from', '<=', now())
            ->where(fn ($query) => $query->whereNull('profile.effective_until')->orWhere('profile.effective_until', '>', now()))
            ->whereNull('profile.deleted_at')->whereNull('staff.deleted_at')->whereNull('staff.employment_ended_at')
            ->where('user.is_active', true)
            ->select(['profile.id as profile_id', 'staff.id as staff_id', 'user.id as user_id'])->first();
    }

    private function validPolicyChecksum(object $policy): bool
    {
        try {
            $facts = ['company_id' => $policy->company_id, 'event_type' => $policy->event_type, 'channel' => $policy->channel,
                'recipient_scope' => $policy->recipient_scope, 'version' => (int) $policy->version,
                'title_template' => $policy->title_template, 'body_template' => $policy->body_template,
                'allowed_placeholders' => json_decode($policy->allowed_placeholders, true, 512, JSON_THROW_ON_ERROR),
                'mandatory' => (bool) $policy->mandatory, 'escalation_after_minutes' => $policy->escalation_after_minutes,
                'effective_from' => $policy->effective_from, 'effective_until' => $policy->effective_until];
            return hash_equals($policy->policy_checksum, hash('sha256', CanonicalJson::encode($facts)));
        } catch (Throwable) {
            return false;
        }
    }

    private function render(object $policy, string $eventType): ?array
    {
        $variables = ['event_label' => self::EVENT_LABELS[$eventType], 'action_label' => 'Review commission holds'];
        $allowed = json_decode($policy->allowed_placeholders, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($allowed) || array_diff($allowed, array_keys($variables))) {
            return null;
        }
        preg_match_all('/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/', $policy->title_template.' '.$policy->body_template, $matches);
        if (array_diff(array_unique($matches[1]), $allowed)) {
            return null;
        }
        $replace = fn (string $text) => preg_replace_callback('/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/',
            fn ($match) => $variables[$match[1]], $text);
        return ['notification_type' => $eventType, 'title' => $replace($policy->title_template),
            'message' => $replace($policy->body_template), 'action_path' => '/sales/commission-holds'];
    }

    private function blocked(string $code): array
    {
        $status = str_contains($code, 'recipient') ? 'blocked_recipient'
            : (str_contains($code, 'preference') || $code === 'recipient_opted_out' ? 'blocked_preference' : 'blocked_configuration');
        return ['status' => $status, 'blocked_code' => $code, 'policy_version_id' => null,
            'recipient_sales_profile_id' => null, 'recipient_staff_id' => null, 'recipient_user_id' => null,
            'encrypted_rendered_payload' => null, 'payload_checksum' => null, 'available_at' => null, 'last_error' => null];
    }

    private function identityColumns(object $identity): array
    {
        return ['recipient_sales_profile_id' => $identity->profile_id, 'recipient_staff_id' => $identity->staff_id,
            'recipient_user_id' => $identity->user_id];
    }

    private function persist(string $id, ?object $existing, array $base, array $state = []): void
    {
        $values = $base + $state;
        if ($existing) {
            DB::table('sales_commission_notification_deliveries')->where('id', $id)->update($values);
        } else {
            DB::table('sales_commission_notification_deliveries')->insert(['id' => $id, 'attempt_count' => 0,
                'created_at' => now()] + $values);
        }
        $row = DB::table('sales_commission_notification_deliveries')->where('id', $id)->first();
        $this->appendEvent($id, 'prepared_'.$row->status, (int) $row->attempt_count, $row->status, $row->blocked_code, $row->payload_checksum);
    }

    private function transition(object $row, string $status, string $reason): void
    {
        $attempt = ((int) $row->attempt_count) + 1;
        DB::table('sales_commission_notification_deliveries')->where('id', $row->id)->update([
            'status' => $status, 'blocked_code' => $reason, 'attempt_count' => $attempt, 'updated_at' => now(),
        ]);
        $this->appendEvent($row->id, 'cancelled', $attempt, $status, $reason, $row->payload_checksum);
    }

    private function appendEvent(string $deliveryId, string $eventType, int $attempt, string $status, ?string $reason, ?string $checksum): void
    {
        DB::table('sales_commission_notification_delivery_events')->insertOrIgnore([
            'id' => (string) Str::uuid(), 'delivery_id' => $deliveryId, 'event_type' => $eventType,
            'attempt_number' => $attempt, 'status' => $status, 'reason_code' => $reason,
            'payload_checksum' => $checksum, 'actor_user_id' => null, 'occurred_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function eventObject(object $row): object
    {
        return (object) ['id' => $row->id, 'domain' => $row->domain, 'companyId' => $row->company_id,
            'aggregateType' => $row->aggregate_type, 'aggregateId' => $row->aggregate_id,
            'eventType' => $row->event_type, 'eventVersion' => $row->event_version,
            'schemaVersion' => $row->schema_version, 'payload' => json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR),
            'occurredAt' => $row->occurred_at, 'correlationId' => $row->correlation_id, 'causationId' => $row->causation_id];
    }

    private function deterministicUuid(string $value): string
    {
        $hash = hash('sha256', 'sales-commission-notification:'.$value);
        return substr($hash, 0, 8).'-'.substr($hash, 8, 4).'-4'.substr($hash, 13, 3).'-a'.substr($hash, 17, 3).'-'.substr($hash, 20, 12);
    }

    private function enabledCompanyIds(): \Illuminate\Database\Query\Builder
    {
        return DB::table('sales_company_feature_settings')->select('company_id')
            ->where('feature_key', 'commission_notifications')->where('status', 'approved')->where('enabled', true);
    }
}
