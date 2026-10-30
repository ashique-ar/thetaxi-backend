<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hr_organization_change_events') && Schema::hasColumn('hr_organization_change_events', 'company_id')) {
            Schema::table('hr_organization_change_events', fn (Blueprint $table) => $table->uuid('company_id')->nullable()->change());
        }

        if (Schema::hasTable('hr_organization_change_events')) {
            DB::table('hr_organization_change_events')->whereIn('aggregate_type', ['work_calendar', 'work_calendar_day'])
                ->orderBy('id')->chunkById(200, function ($events): void {
                    foreach ($events as $event) {
                        $after = $this->decode($event->after_snapshot);
                        unset($after['company_id']);
                        $before = $event->before_snapshot === null ? null : $this->decode($event->before_snapshot);
                        if ($before !== null) {
                            unset($before['company_id']);
                        }

                        $payload = $event->aggregate_type === 'work_calendar'
                            ? array_intersect_key($after, array_flip(['code', 'name', 'timezone', 'weekly_working_days', 'effective_from', 'effective_until']))
                            : array_intersect_key($after, array_flip(['calendar_date', 'day_type', 'name', 'paid']));
                        if ($event->aggregate_type === 'work_calendar_day') {
                            $payload['paid'] = (bool) $payload['paid'];
                        }
                        $checksumInput = $event->aggregate_type === 'work_calendar'
                            ? ['command' => 'create_work_calendar', 'payload' => $payload, 'reason' => $event->reason]
                            : ['command' => 'create_work_calendar_day', 'calendar_id' => $after['calendar_id'], 'payload' => $payload, 'reason' => $event->reason];

                        DB::table('hr_organization_change_events')->where('id', $event->id)->update([
                            'company_id' => null,
                            'before_snapshot' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
                            'after_snapshot' => json_encode($after, JSON_THROW_ON_ERROR),
                            'request_checksum' => hash('sha256', json_encode($checksumInput, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
                        ]);
                    }
                }, 'id');
        }

        if (Schema::hasTable('hr_work_calendars')) {
            if (Schema::hasColumn('hr_work_calendars', 'company_id')) {
                foreach (Schema::getForeignKeys('hr_work_calendars') as $foreignKey) {
                    if (in_array('company_id', $foreignKey['columns'], true)) {
                        Schema::table('hr_work_calendars', fn (Blueprint $table) => $table->dropForeign($foreignKey['name']));
                    }
                }

                foreach (Schema::getIndexes('hr_work_calendars') as $index) {
                    if ($index['type'] === 'primary' || ! in_array('company_id', $index['columns'], true)) {
                        continue;
                    }

                    Schema::table('hr_work_calendars', fn (Blueprint $table) => $index['unique']
                        ? $table->dropUnique($index['name'])
                        : $table->dropIndex($index['name']));
                }

                Schema::table('hr_work_calendars', fn (Blueprint $table) => $table->dropColumn('company_id'));
            }

            if (! Schema::hasIndex('hr_work_calendars', 'hr_work_calendars_code_effective_index')) {
                Schema::table('hr_work_calendars', fn (Blueprint $table) => $table->index(['code', 'effective_from'], 'hr_work_calendars_code_effective_index'));
            }
        }

    }

    public function down(): void
    {
        throw new LogicException('Cannot restore removed work-calendar company ownership; it was tenant metadata, not an operational location.');
    }

    private function decode(mixed $value): array
    {
        return is_array($value) ? $value : json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
    }
};
