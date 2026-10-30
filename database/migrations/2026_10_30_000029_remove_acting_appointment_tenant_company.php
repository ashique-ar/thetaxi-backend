<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $checksums = [];
        if (Schema::hasTable('hr_acting_appointments')) {
            foreach (DB::table('hr_acting_appointments')->get() as $appointment) {
                $checksumPayload = [
                    'staff_id' => $appointment->staff_id,
                    'source_assignment_id' => $appointment->source_assignment_id,
                    'acting_position_id' => $appointment->acting_position_id,
                    'acting_manager_staff_id' => $appointment->acting_manager_staff_id,
                    'effective_from' => $appointment->effective_from,
                    'effective_until' => $appointment->effective_until,
                    'reason' => $appointment->reason,
                    'source_assignment_snapshot' => json_decode($appointment->source_assignment_snapshot, true, 512, JSON_THROW_ON_ERROR),
                    'acting_assignment_snapshot' => json_decode($appointment->acting_assignment_snapshot, true, 512, JSON_THROW_ON_ERROR),
                    'restoration_assignment_snapshot' => json_decode($appointment->restoration_assignment_snapshot, true, 512, JSON_THROW_ON_ERROR),
                    'excluded_impact_snapshot' => json_decode($appointment->excluded_impact_snapshot, true, 512, JSON_THROW_ON_ERROR),
                ];
                ksort($checksumPayload);
                $checksums[$appointment->id] = hash('sha256', json_encode($checksumPayload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                DB::table('hr_acting_appointments')->where('id', $appointment->id)->update(['request_checksum' => $checksums[$appointment->id]]);
            }
        }

        if (Schema::hasTable('hr_acting_appointment_events')) {
            foreach (DB::table('hr_acting_appointment_events')->get(['id', 'acting_appointment_id', 'event_type', 'before_snapshot', 'after_snapshot']) as $event) {
                $before = $event->before_snapshot ? json_decode($event->before_snapshot, true, 512, JSON_THROW_ON_ERROR) : null;
                $after = json_decode($event->after_snapshot, true, 512, JSON_THROW_ON_ERROR);
                unset($before['company_id'], $after['company_id']);
                if (isset($checksums[$event->acting_appointment_id])) {
                    if (isset($after['request_checksum'])) $after['request_checksum'] = $checksums[$event->acting_appointment_id];
                    $updates = ['before_snapshot' => $before ? json_encode($before, JSON_THROW_ON_ERROR) : null,
                        'after_snapshot' => json_encode($after, JSON_THROW_ON_ERROR)];
                    if ($event->event_type === 'requested') $updates['command_checksum'] = $checksums[$event->acting_appointment_id];
                    DB::table('hr_acting_appointment_events')->where('id', $event->id)->update($updates);
                } else {
                    DB::table('hr_acting_appointment_events')->where('id', $event->id)->update([
                        'before_snapshot' => $before ? json_encode($before, JSON_THROW_ON_ERROR) : null,
                        'after_snapshot' => json_encode($after, JSON_THROW_ON_ERROR),
                    ]);
                }
            }
        }

        if (Schema::hasTable('hr_acting_appointments') && Schema::hasColumn('hr_acting_appointments', 'company_id')) {
            foreach (Schema::getForeignKeys('hr_acting_appointments') as $foreignKey) {
                if (in_array('company_id', $foreignKey['columns'], true)) {
                    Schema::table('hr_acting_appointments', fn (Blueprint $table) => $table->dropForeign($foreignKey['name']));
                }
            }
            foreach (Schema::getIndexes('hr_acting_appointments') as $index) {
                if ($index['type'] === 'primary' || ! in_array('company_id', $index['columns'], true)) continue;
                $dropMethod = $index['type'] === 'unique' ? 'dropUnique' : 'dropIndex';
                Schema::table('hr_acting_appointments', fn (Blueprint $table) => $table->{$dropMethod}($index['name']));
            }
            Schema::table('hr_acting_appointments', fn (Blueprint $table) => $table->dropColumn('company_id'));
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Acting-appointment tenant company data cannot be restored without reintroducing company-scoped HR access.');
    }
};
