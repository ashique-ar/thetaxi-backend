<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class WebsiteKmPricingSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $websiteServiceTypeIds = DB::table('service_types')
                ->whereIn('context', ['public', 'portal'])
                ->whereNull('deleted_at')
                ->pluck('id');

            $distanceVariables = ['accepted_to_pickup_distance' => true];
            DB::table('vehicle_pricing_calculation_definitions')
                ->whereIn('service_type_id', $websiteServiceTypeIds)
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->chunk(100, function ($definitions) use (&$distanceVariables): void {
                    foreach ($definitions as $definition) {
                        $variables = json_decode($definition->variables ?? '[]', true, 512, JSON_THROW_ON_ERROR) ?? [];
                        $types = [];
                        foreach ($variables as $variable) {
                            if (is_array($variable) && !empty($variable['name'])) {
                                $types[$variable['name']] = strtolower((string) ($variable['type'] ?? ''));
                            }
                        }

                        $formula = (string) $definition->formula;
                        preg_match_all('/\{?([A-Za-z_][A-Za-z0-9_]*)\}?\s*\*\s*\{?([A-Za-z_][A-Za-z0-9_]*)\}?/', $formula, $products, PREG_SET_ORDER);
                        foreach ($products as $product) {
                            foreach ([[$product[1], $product[2]], [$product[2], $product[1]]] as [$distanceName, $rateName]) {
                                if (($types[$distanceName] ?? null) !== 'common_rate'
                                    || ($types[$rateName] ?? null) !== 'common_rate'
                                    || !preg_match('/(?:_km|_distance)$/i', $distanceName)
                                    || !preg_match('/(?:_rate|rate_per_km)$/i', $rateName)) {
                                    continue;
                                }
                                foreach ($variables as &$variable) {
                                    if (($variable['name'] ?? null) === $distanceName) {
                                        $variable['type'] = 'distance';
                                    }
                                }
                                unset($variable);
                                $types[$distanceName] = 'distance';
                            }
                        }

                        $formula = $this->replaceDistanceRateProducts($formula, $types);
                        $formula = preg_replace('/(?<![A-Za-z0-9_])total_distance(?![A-Za-z0-9_])/', 'journey_distance', $formula) ?? $formula;
                        $variables = $this->standardizeJourneyVariable($variables, $formula);
                        $conditions = $this->renameConditionDistanceFields(
                            json_decode($definition->conditions ?? '[]', true, 512, JSON_THROW_ON_ERROR) ?? []
                        );

                        $encodedVariables = json_encode($variables, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                        $encodedConditions = json_encode($conditions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                        if ($formula !== $definition->formula
                            || $encodedVariables !== $definition->variables
                            || $encodedConditions !== $definition->conditions) {
                            DB::table('vehicle_pricing_calculation_definitions')->where('id', $definition->id)->update([
                                'formula' => $formula,
                                'variables' => $encodedVariables,
                                'conditions' => $encodedConditions,
                                'updated_at' => now(),
                            ]);
                        }

                        preg_match_all('/\bkm_range_charge\(\s*\{?([A-Za-z_][A-Za-z0-9_]*)\}?\s*,\s*\{?[A-Za-z_][A-Za-z0-9_]*\}?\s*\)/i', $formula, $calls);
                        foreach ($calls[1] ?? [] as $distanceName) {
                            $distanceVariables[$distanceName] = true;
                        }
                    }
                });

            DB::table('km_range_pricing_rules')
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->chunk(100, function ($rules) use ($distanceVariables): void {
                    foreach ($rules as $rule) {
                        $contexts = json_decode($rule->applicable_contexts ?? '["public"]', true, 512, JSON_THROW_ON_ERROR) ?? [];
                        if (!array_intersect(['public', 'portal'], $contexts) || in_array('corporate', $contexts, true)) {
                            continue;
                        }

                        $types = json_decode($rule->distance_types ?? '[]', true, 512, JSON_THROW_ON_ERROR) ?? [];
                        $types = array_map(static fn ($name) => $name === 'total_distance' ? 'journey_distance' : $name, $types);
                        $types = array_values(array_unique(array_merge($types, array_keys($distanceVariables))));
                        $encodedTypes = json_encode($types, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                        if ($encodedTypes !== $rule->distance_types) {
                            DB::table('km_range_pricing_rules')->where('id', $rule->id)->update([
                                'distance_types' => $encodedTypes,
                                'updated_at' => now(),
                            ]);
                        }
                    }
                });
        });
    }

    private function replaceDistanceRateProducts(string $formula, array $types): string
    {
        return preg_replace_callback(
            '/(?<![A-Za-z0-9_])\{?([A-Za-z_][A-Za-z0-9_]*)\}?\s*\*\s*\{?([A-Za-z_][A-Za-z0-9_]*)\}?(?![A-Za-z0-9_])/',
            static function (array $match) use ($types): string {
                [$left, $right] = [$match[1], $match[2]];
                if (($types[$left] ?? null) === 'distance' && ($types[$right] ?? null) === 'common_rate') {
                    return "km_range_charge({$left}, {$right})";
                }
                if (($types[$right] ?? null) === 'distance' && ($types[$left] ?? null) === 'common_rate') {
                    return "km_range_charge({$right}, {$left})";
                }
                return $match[0];
            },
            $formula
        ) ?? $formula;
    }

    private function standardizeJourneyVariable(array $variables, string $formula): array
    {
        $journey = collect($variables)->first(fn ($variable) => is_array($variable) && ($variable['name'] ?? null) === 'journey_distance');
        $total = collect($variables)->first(fn ($variable) => is_array($variable) && ($variable['name'] ?? null) === 'total_distance');
        if (!is_array($journey) && is_array($total)) {
            $journey = $total;
            $journey['name'] = 'journey_distance';
            $journey['description'] = $journey['description'] ?? 'Passenger journey distance in KM';
        } elseif (is_array($journey) && is_array($total)) {
            $journey += $total;
            $journey['name'] = 'journey_distance';
        }

        $updated = [];
        $added = false;
        foreach ($variables as $variable) {
            if (is_array($variable) && in_array($variable['name'] ?? null, ['journey_distance', 'total_distance'], true)) {
                if (!$added && is_array($journey)) {
                    $updated[] = $journey;
                    $added = true;
                }
                continue;
            }
            $updated[] = $variable;
        }

        if (preg_match('/(?<![A-Za-z0-9_])journey_distance(?![A-Za-z0-9_])/', $formula) && !$added) {
            $updated[] = [
                'name' => 'journey_distance',
                'type' => 'distance',
                'description' => 'Passenger journey distance in KM',
                'is_required' => false,
                'default_value' => 0,
                'category' => 'distance',
            ];
        }
        return $updated;
    }

    private function renameConditionDistanceFields(mixed $conditions): mixed
    {
        if (!is_array($conditions)) {
            return $conditions;
        }
        foreach ($conditions as $key => $value) {
            if (in_array($key, ['field', 'value_field', 'variable', 'variable_name'], true) && $value === 'total_distance') {
                $conditions[$key] = 'journey_distance';
            } elseif (is_array($value)) {
                $conditions[$key] = $this->renameConditionDistanceFields($value);
            }
        }
        return $conditions;
    }
}
