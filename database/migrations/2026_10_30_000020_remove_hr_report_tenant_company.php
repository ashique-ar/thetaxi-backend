<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/Support/TenantIndexDropper.php';

return new class extends Migration
{
    private const TABLES = [
        'hr_report_saved_views',
        'hr_report_schedules',
        'hr_report_runs',
        'hr_data_quality_snapshots',
    ];

    public function up(): void
    {
        $this->makeSharedViews();
        $this->refreshScheduleChecksums();
        $this->refreshQueuedRunChecksums();

        foreach (self::TABLES as $tableName) {
            $this->dropCompanyField($tableName);
        }

        if (Schema::hasTable('hr_report_saved_views') && ! Schema::hasIndex('hr_report_saved_views', 'hr_report_saved_views_kind_status_index')) {
            Schema::table('hr_report_saved_views', fn (Blueprint $table) => $table->index(['report_kind', 'status'], 'hr_report_saved_views_kind_status_index'));
        }
        if (Schema::hasTable('hr_report_runs') && ! Schema::hasIndex('hr_report_runs', 'hr_report_runs_status_queued_at_index')) {
            Schema::table('hr_report_runs', fn (Blueprint $table) => $table->index(['status', 'queued_at'], 'hr_report_runs_status_queued_at_index'));
        }
        if (Schema::hasTable('hr_data_quality_snapshots') && ! Schema::hasIndex('hr_data_quality_snapshots', 'hr_data_quality_snapshot_lookup')) {
            Schema::table('hr_data_quality_snapshots', fn (Blueprint $table) => $table->index(['as_of_date', 'source_checksum'], 'hr_data_quality_snapshot_lookup'));
        }
    }

    private function makeSharedViews(): void
    {
        if (! Schema::hasTable('hr_report_saved_views')) {
            return;
        }

        DB::table('hr_report_saved_views')->orderBy('id')->chunkById(100, function ($views): void {
            foreach ($views as $view) {
                $filters = json_decode($view->filter_contract, true) ?: [];
                unset($filters['company_id']);
                $columns = json_decode($view->column_contract, true) ?: [];
                $visibility = $view->visibility === 'company' ? 'shared' : $view->visibility;
                $checksum = hash('sha256', json_encode([
                    'report_kind' => $view->report_kind,
                    'filters' => $filters,
                    'columns' => $columns,
                    'visibility' => $visibility,
                ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                DB::table('hr_report_saved_views')->where('id', $view->id)->update([
                    'filter_contract' => json_encode($filters, JSON_THROW_ON_ERROR),
                    'visibility' => $visibility,
                    'view_checksum' => $checksum,
                ]);
            }
        });
    }

    private function refreshScheduleChecksums(): void
    {
        if (! Schema::hasTable('hr_report_schedules')) {
            return;
        }

        DB::table('hr_report_schedules as schedule')
            ->join('hr_report_saved_views as view', 'view.id', '=', 'schedule.saved_view_id')
            ->select(['schedule.*', 'view.view_checksum'])
            ->orderBy('schedule.id')->chunkById(100, function ($schedules): void {
                foreach ($schedules as $schedule) {
                    $recipients = array_values(array_unique(json_decode($schedule->recipient_user_ids, true) ?: []));
                    $payload = [
                        'saved_view_id' => $schedule->saved_view_id,
                        'name' => $schedule->name,
                        'cadence' => $schedule->cadence,
                        'timezone' => $schedule->timezone,
                        'local_time' => substr($schedule->local_time, 0, 5),
                        'day_of_week' => $schedule->cadence === 'weekly' ? (int) $schedule->day_of_week : null,
                        'day_of_month' => $schedule->cadence === 'monthly' ? (int) $schedule->day_of_month : null,
                        'format' => $schedule->format,
                        'recipient_user_ids' => $recipients,
                        'delivery_channel' => $schedule->delivery_channel,
                        'view_checksum' => $schedule->view_checksum,
                    ];
                    DB::table('hr_report_schedules')->where('id', $schedule->id)->update([
                        'schedule_checksum' => hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
                    ]);
                }
            }, 'schedule.id', 'id');
    }

    private function refreshQueuedRunChecksums(): void
    {
        if (! Schema::hasTable('hr_report_runs')) {
            return;
        }

        DB::table('hr_report_runs')->orderBy('id')->chunkById(100, function ($runs): void {
            foreach ($runs as $run) {
                $view = DB::table('hr_report_saved_views')->where('id', $run->saved_view_id)->first();
                $format = $run->transfer_job_id
                    ? DB::table('domain_transfer_jobs')->where('id', $run->transfer_job_id)->value('format')
                    : null;
                $filters = json_decode($run->filter_snapshot, true) ?: [];
                $columns = json_decode($run->column_snapshot, true) ?: [];
                $privacy = json_decode($run->privacy_snapshot, true) ?: [];
                unset($privacy['legal_entity_id']);
                if (! $view || ! $format) {
                    DB::table('hr_report_runs')->where('id', $run->id)->update([
                        'privacy_snapshot' => json_encode($privacy, JSON_THROW_ON_ERROR),
                    ]);
                    continue;
                }
                $request = [
                    'view_checksum' => $view->view_checksum,
                    'as_of_date' => $run->as_of_date,
                    'format' => $format,
                    'filters' => $filters,
                    'columns' => $columns,
                    'privacy' => $privacy,
                ];
                DB::table('hr_report_runs')->where('id', $run->id)->update([
                    'privacy_snapshot' => json_encode($privacy, JSON_THROW_ON_ERROR),
                    'request_checksum' => hash('sha256', json_encode($request, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
                ]);
            }
        });
    }

    private function dropCompanyField(string $tableName): void
    {
        if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'company_id')) {
            return;
        }

        foreach (Schema::getForeignKeys($tableName) as $foreignKey) {
            if (in_array('company_id', $foreignKey['columns'], true)) {
                Schema::table($tableName, fn (Blueprint $table) => $table->dropForeign($foreignKey['name']));
            }
        }

        foreach (Schema::getIndexes($tableName) as $index) {
            if ($index['type'] === 'primary' || ! in_array('company_id', $index['columns'], true)) {
                continue;
            }

            TenantIndexDropper::drop($tableName, $index);
        }

        Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('company_id'));
    }

    public function down(): void
    {
        foreach (self::TABLES as $tableName) {
            if (Schema::hasTable($tableName) && ! Schema::hasColumn($tableName, 'company_id')) {
                Schema::table($tableName, fn (Blueprint $table) => $table->uuid('company_id')->nullable()->index());
            }
        }
    }
};
