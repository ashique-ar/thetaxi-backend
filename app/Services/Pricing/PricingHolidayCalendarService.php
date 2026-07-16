<?php

namespace App\Services\Pricing;

use App\Services\WebsiteSettingsService;
use Carbon\CarbonInterface;

class PricingHolidayCalendarService
{
    public const EXACT_DATE_SETTING = 'pricing_holiday_dates';
    public const RECURRING_DATE_SETTING = 'pricing_recurring_holidays';

    public function __construct(private WebsiteSettingsService $settings)
    {
    }

    /**
     * @return array{
     *   is_holiday: bool,
     *   date: string,
     *   matched_rule: string|null,
     *   matched_value: string|null,
     *   calendar_healthy: bool,
     *   issues: array<int, array{setting:string,value:string,message:string}>
     * }
     */
    public function evaluate(CarbonInterface $date): array
    {
        $calendar = $this->calendar();
        $exactDate = $date->format('Y-m-d');
        $recurringDate = $date->format('m-d');

        $matchedRule = null;
        $matchedValue = null;
        if (in_array($exactDate, $calendar['exact_dates'], true)) {
            $matchedRule = 'exact_date';
            $matchedValue = $exactDate;
        } elseif (in_array($recurringDate, $calendar['recurring_dates'], true)) {
            $matchedRule = 'recurring_date';
            $matchedValue = $recurringDate;
        }

        return [
            'is_holiday' => $matchedRule !== null,
            'date' => $exactDate,
            'matched_rule' => $matchedRule,
            'matched_value' => $matchedValue,
            'calendar_healthy' => $calendar['issues'] === [],
            'issues' => $calendar['issues'],
        ];
    }

    public function isHoliday(CarbonInterface $date): bool
    {
        return $this->evaluate($date)['is_holiday'];
    }

    /**
     * @return array{
     *   exact_dates: array<int, string>,
     *   recurring_dates: array<int, string>,
     *   issues: array<int, array{setting:string,value:string,message:string}>
     * }
     */
    public function calendar(): array
    {
        $exact = $this->parseSetting(
            self::EXACT_DATE_SETTING,
            $this->read(self::EXACT_DATE_SETTING, ''),
            '/^\d{4}-\d{2}-\d{2}$/',
            'Use YYYY-MM-DD.'
        );
        $recurring = $this->parseSetting(
            self::RECURRING_DATE_SETTING,
            $this->read(self::RECURRING_DATE_SETTING, '01-01,12-25'),
            '/^\d{2}-\d{2}$/',
            'Use MM-DD.'
        );

        return [
            'exact_dates' => $exact['values'],
            'recurring_dates' => $recurring['values'],
            'issues' => array_merge($exact['issues'], $recurring['issues']),
        ];
    }

    private function read(string $key, string $default): mixed
    {
        try {
            return $this->settings->get($key, $default) ?? $default;
        } catch (\Throwable) {
            // Pure/unit calculation tests may intentionally run without the
            // settings tables. Preserve deterministic defaults in that case.
            return $default;
        }
    }

    /**
     * @return array{
     *   values: array<int, string>,
     *   issues: array<int, array{setting:string,value:string,message:string}>
     * }
     */
    private function parseSetting(
        string $setting,
        mixed $raw,
        string $pattern,
        string $formatMessage
    ): array {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $raw = $decoded;
            } else {
                $raw = preg_split('/[\r\n,;]+/', $raw) ?: [];
            }
        }

        $entries = is_array($raw) ? $raw : [];
        $values = [];
        $issues = [];
        foreach ($entries as $entry) {
            $value = trim((string) $entry);
            if ($value === '') {
                continue;
            }

            if (!preg_match($pattern, $value) || !$this->isRealDateFragment($value)) {
                $issues[] = [
                    'setting' => $setting,
                    'value' => $value,
                    'message' => "Invalid holiday date '{$value}'. {$formatMessage}",
                ];
                continue;
            }

            $values[] = $value;
        }

        return [
            'values' => array_values(array_unique($values)),
            'issues' => $issues,
        ];
    }

    private function isRealDateFragment(string $value): bool
    {
        $parts = array_map('intval', explode('-', $value));
        if (count($parts) === 3) {
            return checkdate($parts[1], $parts[2], $parts[0]);
        }
        if (count($parts) === 2) {
            // Leap day is valid as a recurring rule.
            return checkdate($parts[0], $parts[1], 2024);
        }

        return false;
    }
}
