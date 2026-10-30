<?php

namespace App\Console\Commands;

use App\Services\Sms\SmsService;
use Illuminate\Console\Command;

class MonitorSmsCycle extends Command
{
    protected $signature = 'sms:monitor-cycle
        {--days=1 : Reporting window from 1 to 90 days}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Read-only SMS operating-cycle health, compliance and cost report';

    public function __construct(private SmsService $smsService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $days = max(1, min(90, (int) $this->option('days')));
        $health = $this->smsService->getOperationalHealth();
        $compliance = $this->smsService->getTransactionalComplianceReport($days);
        $normalThree = $compliance['customer_normal_three'];
        $admin = $compliance['admin_summaries'];
        $issues = [
            'operational_alerts' => count($health['alerts'] ?? []),
            'incomplete_bookings' => (int) $normalThree['incomplete_bookings'],
            'bookings_with_extras' => (int) $normalThree['bookings_with_extras'],
            'duplicate_event_messages' => (int) $normalThree['duplicate_event_messages'],
            'unexpected_messages' => (int) $compliance['unexpected_messages']['total'],
            'admin_summary_count_mismatch' => (int) $admin['recorded_messages'] !== (int) $admin['expected_messages_for_confirmations'],
        ];
        $healthy = $health['status'] === 'healthy'
            && $issues['incomplete_bookings'] === 0
            && $issues['bookings_with_extras'] === 0
            && $issues['duplicate_event_messages'] === 0
            && $issues['unexpected_messages'] === 0
            && $issues['admin_summary_count_mismatch'] === false;

        $report = [
            'read_only' => true,
            'healthy' => $healthy,
            'window' => $compliance['window'],
            'health' => $health,
            'compliance' => $compliance,
            'issues' => $issues,
            'cost' => [
                'admin_summary_segments' => $admin['segments'],
                'admin_summary_estimated_cost' => $admin['estimated_cost'],
                'currency' => $admin['cost_currency'],
            ],
            'safety' => [
                'provider_contacted' => false,
                'jobs_dispatched' => false,
                'records_changed' => false,
            ],
        ];

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info("SMS operating-cycle report ({$days} day(s), read-only)");
            $this->table(['Measure', 'Value'], [
                ['Operational health', $health['status']],
                ['Bookings observed', $normalThree['bookings_observed']],
                ['Normal-three compliant', $normalThree['compliant_bookings']],
                ['Incomplete bookings', $issues['incomplete_bookings']],
                ['Bookings with extras', $issues['bookings_with_extras']],
                ['Unexpected messages', $issues['unexpected_messages']],
                ['Admin summaries expected', $admin['expected_messages_for_confirmations']],
                ['Admin summaries recorded', $admin['recorded_messages']],
                ['Admin summary cost', $admin['cost_currency'] . ' ' . number_format((float) $admin['estimated_cost'], 4)],
            ]);
            $healthy
                ? $this->info('Operating-cycle checks passed.')
                : $this->warn('Operating-cycle checks need attention; review the JSON report before enabling more events.');
        }

        return $healthy ? self::SUCCESS : self::FAILURE;
    }
}
