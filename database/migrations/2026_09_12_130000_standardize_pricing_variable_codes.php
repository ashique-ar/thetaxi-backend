<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CODES = [
        'Minimum_Fare' => 'minimum_fare',
        'Minimum_KM' => 'minimum_km',
        'Free_Waiting' => 'free_waiting_minutes',
        'Additonal_Waiting_min' => 'additional_waiting_rate_per_minute',
    ];

    private const LABELS = [
        'Minimum_Fare' => 'Minimum Fare',
        'Minimum_KM' => 'Minimum Kilometres',
        'Free_Waiting' => 'Free Waiting Minutes',
        'Additonal_Waiting_min' => 'Additional Waiting Rate per Minute',
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            // Preserve definition IDs so all vehicle-group and corporate prices stay linked.
            foreach (self::CODES as $old => $new) {
                foreach (DB::table('vehicle_pricing_common_rate_definitions')->where('code', $old)->get() as $rate) {
                    if (DB::table('vehicle_pricing_common_rate_definitions')->where('code', $new)
                        ->where('service_type_id', $rate->service_type_id)->exists()) {
                        throw new RuntimeException("Pricing code {$new} already exists for service {$rate->service_type_id}; reconcile the rates before renaming.");
                    }
                }
                DB::table('vehicle_pricing_common_rate_definitions')->where('code', $old)->update([
                    'code' => $new, 'name' => self::LABELS[$old], 'updated_at' => now(),
                ]);
            }

            DB::table('vehicle_pricing_calculation_definitions')->orderBy('id')->chunk(100, function ($rows): void {
                foreach ($rows as $row) {
                    $variables = json_decode($row->variables ?? '[]', true, 512, JSON_THROW_ON_ERROR) ?? [];
                    foreach ($variables as &$variable) {
                        $old = $variable['name'] ?? '';
                        if (isset(self::CODES[$old])) {
                            $variable['name'] = self::CODES[$old];
                            $variable['description'] = self::LABELS[$old];
                        }
                    }
                    unset($variable);
                    $names = array_column($variables, 'name');
                    if (count($names) !== count(array_unique($names))) {
                        throw new RuntimeException("Pricing variable rename would duplicate a code in definition {$row->id}.");
                    }
                    $updates = [
                        'formula' => $this->renameFormula($row->formula),
                        'variables' => json_encode($variables, JSON_THROW_ON_ERROR),
                        'conditions' => $this->renameJson($row->conditions),
                    ];
                    if ($updates['formula'] !== $row->formula || $variables !== json_decode($row->variables, true)
                        || $updates['conditions'] !== $row->conditions) {
                        DB::table('vehicle_pricing_calculation_definitions')->where('id', $row->id)
                            ->update($updates + ['updated_at' => now()]);
                    }
                }
            });

            // These are editable pricing inputs, not immutable historical fare snapshots.
            if (Schema::hasTable('booking_variable_customizations')) {
                foreach (self::CODES as $old => $new) {
                    DB::table('booking_variable_customizations')->where('variable_name', $old)
                        ->update(['variable_name' => $new]);
                }
            }
            if (Schema::hasColumn('booking_items', 'customizations')) {
                DB::table('booking_items')->whereNotNull('customizations')->orderBy('id')->chunk(100, function ($rows): void {
                    foreach ($rows as $row) {
                        $renamed = $this->renameJson($row->customizations);
                        if ($renamed !== $row->customizations) {
                            DB::table('booking_items')->where('id', $row->id)->update(['customizations' => $renamed]);
                        }
                    }
                });
            }
        });
    }

    private function renameFormula(string $formula): string
    {
        return preg_replace_callback('/\b(' . implode('|', array_keys(self::CODES)) . ')\b/',
            static fn ($match) => self::CODES[$match[0]], $formula);
    }

    private function renameJson(?string $json): ?string
    {
        if ($json === null) {
            return null;
        }
        // Replace exact JSON strings only, preserving notes and longer identifiers.
        $replacements = [];
        foreach (self::CODES as $old => $new) {
            $replacements[json_encode($old)] = json_encode($new);
        }
        return strtr($json, $replacements);
    }

    public function down(): void
    {
        // Codes are canonical; do not reintroduce misspellings into edited definitions.
    }
};
