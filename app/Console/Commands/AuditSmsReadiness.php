<?php

namespace App\Console\Commands;

use App\Models\Sms\SmsMessage;
use App\Services\Sms\SmsSettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AuditSmsReadiness extends Command
{
    protected $signature = 'sms:audit-readiness
        {--json : Emit machine-readable JSON}
        {--require-ready : Return failure when the Gate 1 rollout guard has blockers}';

    protected $description = 'Read-only SMS deployment, queue backlog and configuration readiness audit';

    public function __construct(private SmsSettingsService $settingsService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $connection = (string) config('queue.default');
        $connectionConfig = config("queue.connections.{$connection}", []);
        $databaseConnection = (string) config('database.default');
        $settings = $this->settingsService->getSettings();
        $jobInventory = $this->databaseJobInventory();
        $failedJobs = $this->failedSmsJobInventory();
        $messageInventory = $this->messageInventory();
        $runtime = [
            'deployment_directory' => base_path(),
            'php_binary' => PHP_BINARY,
            'php_version' => PHP_VERSION,
            'process_user' => $this->processUser(),
            'environment' => app()->environment(),
            'log_directory_writable' => is_writable(storage_path('logs')),
        ];
        $rolloutGuard = $this->rolloutGuard($settings, $runtime, $jobInventory, $messageInventory);

        $report = [
            'generated_at' => now()->toIso8601String(),
            'read_only' => true,
            'runtime' => $runtime,
            'database' => [
                'connection' => $databaseConnection,
                'database' => config("database.connections.{$databaseConnection}.database"),
            ],
            'queue' => [
                'connection' => $connection,
                'driver' => $connectionConfig['driver'] ?? null,
                'configured_default_queue' => $connectionConfig['queue'] ?? null,
                'scheduled_worker_enabled' => (bool) config('queue.scheduled_worker.enabled', false),
                'scheduled_worker_queues' => config('queue.scheduled_worker.queues'),
                'database_jobs' => $jobInventory,
                'failed_sms_jobs' => $failedJobs,
            ],
            'messages' => $messageInventory,
            'provider' => [
                'enabled' => (bool) ($settings['enabled'] ?? false),
                'provider' => $settings['provider'] ?? null,
                'queue_enabled' => (bool) ($settings['queue_enabled'] ?? false),
                'dry_run' => (bool) ($settings['dry_run'] ?? false),
                'callback_configured' => !empty($settings['providers'][$settings['provider'] ?? '']['delivery_callback_url'] ?? null),
                'credentials' => $this->credentialPresence($settings),
            ],
            'rollout_guard' => $rolloutGuard,
            'safety' => [
                'worker_started' => false,
                'jobs_retried' => false,
                'jobs_deleted' => false,
                'provider_contacted' => false,
                'next_action' => 'Classify historical SMS jobs and messages before any worker activation or retry.',
            ],
        ];

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return $this->exitCode($rolloutGuard);
        }

        $this->info('SMS readiness audit (read-only)');
        $this->table(['Runtime', 'Value'], $this->rows($report['runtime']));
        $this->table(['Queue fact', 'Value'], $this->rows(array_diff_key($report['queue'], ['database_jobs' => true, 'failed_sms_jobs' => true])));
        $this->table(['Queue', 'Jobs', 'SMS jobs', 'Oldest SMS job'], $report['queue']['database_jobs']);
        $this->table(['Status', 'Count', 'Oldest'], $report['messages']['by_status']);
        $this->table(['Provider safety', 'Value'], $this->rows($report['provider']));
        $this->table(['Rollout guard', 'Value'], $this->rows($report['rollout_guard']));
        $this->warn($report['safety']['next_action']);

        return $this->exitCode($rolloutGuard);
    }

    private function databaseJobInventory(): array
    {
        if (!Schema::hasTable('jobs')) {
            return [];
        }

        return DB::table('jobs')
            ->selectRaw("queue, COUNT(*) as jobs, SUM(CASE WHEN payload LIKE '%SendSmsMessageJob%' OR payload LIKE '%LaunchSmsCampaignJob%' THEN 1 ELSE 0 END) as sms_jobs, MIN(CASE WHEN payload LIKE '%SendSmsMessageJob%' OR payload LIKE '%LaunchSmsCampaignJob%' THEN created_at ELSE NULL END) as oldest_sms_job")
            ->groupBy('queue')
            ->orderBy('queue')
            ->get()
            ->map(fn ($row): array => [
                'queue' => $row->queue,
                'jobs' => (int) $row->jobs,
                'sms_jobs' => (int) $row->sms_jobs,
                'oldest_sms_job' => $row->oldest_sms_job ? date(DATE_ATOM, (int) $row->oldest_sms_job) : null,
            ])->all();
    }

    private function failedSmsJobInventory(): array
    {
        if (!Schema::hasTable('failed_jobs')) {
            return ['count' => 0, 'oldest_at' => null];
        }

        $query = DB::table('failed_jobs')->where(function ($query): void {
            $query->where('payload', 'like', '%SendSmsMessageJob%')
                ->orWhere('payload', 'like', '%LaunchSmsCampaignJob%');
        });

        return ['count' => (clone $query)->count(), 'oldest_at' => (clone $query)->min('failed_at')];
    }

    private function messageInventory(): array
    {
        if (!Schema::hasTable('sms_messages')) {
            return ['total' => 0, 'by_status' => []];
        }

        $byStatus = SmsMessage::query()->withInactive()
            ->selectRaw('status, COUNT(*) as aggregate, MIN(created_at) as oldest_at')
            ->groupBy('status')
            ->orderBy('status')
            ->get()
            ->map(fn ($row): array => ['status' => $row->status, 'count' => (int) $row->aggregate, 'oldest_at' => $row->oldest_at])
            ->all();

        return ['total' => array_sum(array_column($byStatus, 'count')), 'by_status' => $byStatus];
    }

    private function credentialPresence(array $settings): array
    {
        $provider = $settings['providers'][$settings['provider'] ?? ''] ?? [];

        return [
            'username_configured' => !empty($provider['username']),
            'password_configured' => !empty($provider['password']),
            'api_key_configured' => !empty($provider['api_key']),
            'url_message_key_configured' => !empty($provider['esmsqk']),
        ];
    }

    private function rolloutGuard(array $settings, array $runtime, array $jobs, array $messages): array
    {
        $eventSwitches = [
            'booking_confirmation_enabled',
            'quotation_requested_enabled',
            'inquiry_received_enabled',
            'driver_dispatched_enabled',
            'driver_arrived_enabled',
            'trip_completion_enabled',
            'payment_confirmation_enabled',
            'driver_assignment_fallback_enabled',
            'admin_booking_summary_enabled',
        ];
        $enabledSwitches = array_values(array_filter(
            $eventSwitches,
            fn (string $key): bool => !empty($settings[$key])
        ));
        $smsJobs = array_sum(array_column($jobs, 'sms_jobs'));
        $nonTerminalStatuses = ['queued', 'pending', 'processing'];
        $nonTerminalMessages = collect($messages['by_status'] ?? [])
            ->whereIn('status', $nonTerminalStatuses)
            ->sum('count');
        $requiredSchema = [
            'sms_messages' => Schema::hasTable('sms_messages')
                && Schema::hasColumns('sms_messages', [
                    'event_key', 'booking_id', 'booking_item_id', 'driver_assignment_id',
                    'idempotency_key', 'source', 'triggered_at', 'provider_status', 'segments', 'total_cost',
                ]),
            'booking_activities' => Schema::hasTable('booking_activities'),
            'driver_assignment_notifications' => Schema::hasTable('driver_assignment_notifications'),
        ];

        $blockers = [];
        if (in_array(false, $requiredSchema, true)) $blockers[] = 'Required additive SMS schema is incomplete.';
        if (empty($settings['dry_run'])) $blockers[] = 'SMS dry-run is not enabled.';
        if ($enabledSwitches !== []) $blockers[] = 'Transactional event switches are already enabled: ' . implode(', ', $enabledSwitches) . '.';
        if ($smsJobs > 0) $blockers[] = "{$smsJobs} historical SMS queue job(s) require classification.";
        if ($nonTerminalMessages > 0) $blockers[] = "{$nonTerminalMessages} non-terminal SMS message record(s) require classification.";
        if (!empty(config('queue.scheduled_worker.enabled'))) $blockers[] = 'The scheduler-managed queue worker is enabled.';
        if (empty($runtime['log_directory_writable'])) $blockers[] = 'The runtime cannot write to storage/logs.';

        return [
            'gate_1_ready' => $blockers === [],
            'required_schema' => $requiredSchema,
            'dry_run_enabled' => (bool) ($settings['dry_run'] ?? false),
            'enabled_event_switches' => $enabledSwitches,
            'historical_sms_jobs' => $smsJobs,
            'non_terminal_sms_messages' => (int) $nonTerminalMessages,
            'blockers' => $blockers,
        ];
    }

    private function processUser(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            return (string) (posix_getpwuid(posix_geteuid())['name'] ?? posix_geteuid());
        }

        return (string) (getenv('USERNAME') ?: getenv('USER') ?: 'unknown');
    }

    private function exitCode(array $rolloutGuard): int
    {
        return $this->option('require-ready') && empty($rolloutGuard['gate_1_ready'])
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function rows(array $values): array
    {
        return collect($values)->map(fn ($value, $key): array => [
            str_replace('_', ' ', (string) $key),
            is_array($value) ? json_encode($value, JSON_UNESCAPED_SLASHES) : var_export($value, true),
        ])->values()->all();
    }
}
