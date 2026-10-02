<?php

namespace App\Services\Hr\Support;

use App\Models\User;
use DateTimeImmutable;
use Illuminate\Support\Facades\Crypt;

/**
 * Owner-agnostic normalize/validate/encode helpers shared by every HR
 * custom-field value writer (Staff, organization unit, position, employment
 * spell). Extracted from StaffCustomFieldValueService so every subject type
 * enforces the identical bounded type/option/range and encryption contract.
 */
trait CustomFieldValueValidation
{
    private function normalizeAndValidate(mixed $value, object $definition): mixed
    {
        if ($value === null) {
            abort_if((bool) $definition->required, 422, 'This required custom field cannot be cleared.');
            return null;
        }
        $rules = $this->decodeRules($definition->validation_rules);
        $normalized = match ($definition->data_type) {
            'text', 'long_text' => $this->stringValue($value, $definition->data_type === 'text' ? 1000 : 10000),
            'integer' => $this->integerValue($value),
            'decimal' => $this->decimalValue($value),
            'boolean' => $this->booleanValue($value),
            'date' => $this->dateValue($value),
            'datetime' => $this->dateTimeValue($value),
            'select' => $this->selectValue($value, $rules),
            'multi_select' => $this->multiSelectValue($value, $rules),
            'email' => $this->emailValue($value),
            'phone' => $this->phoneValue($value),
            'url' => $this->urlValue($value),
            default => abort(422, 'Unsupported custom-field data type.'),
        };
        if ((bool) $definition->required) abort_if((is_string($normalized) && $normalized === '') || (is_array($normalized) && $normalized === []), 422, 'This required custom field cannot be empty.');
        if (is_string($normalized) && isset($rules['min_length'])) abort_if(mb_strlen($normalized) < (int) $rules['min_length'], 422, 'Custom-field value is shorter than its configured minimum.');
        if (is_string($normalized) && isset($rules['max_length'])) abort_if(mb_strlen($normalized) > (int) $rules['max_length'], 422, 'Custom-field value exceeds its configured maximum.');
        if (in_array($definition->data_type, ['integer', 'decimal'], true)) $this->assertNumericBounds((string) $normalized, $rules);
        return $normalized;
    }

    private function stringValue(mixed $value, int $max): string { abort_unless(is_string($value) && mb_strlen(trim($value)) <= $max, 422, 'Custom-field value must be a bounded string.'); return trim($value); }
    private function integerValue(mixed $value): int { if (is_int($value)) return $value; abort_unless(is_string($value) && strlen($value) <= 100 && preg_match('/^-?(0|[1-9][0-9]*)$/D', $value), 422, 'Custom-field value must be a bounded integer.'); $parsed = filter_var($value, FILTER_VALIDATE_INT); abort_unless($parsed !== false, 422, 'Custom-field integer is outside the supported exact range.'); return $parsed; }
    private function decimalValue(mixed $value): string { abort_unless(is_string($value) && strlen($value) <= 100 && preg_match('/^-?(0|[1-9][0-9]*)(\.[0-9]{1,8})?$/D', $value), 422, 'Decimal custom fields require a bounded exact decimal string with at most eight fraction digits.'); return $value; }
    private function booleanValue(mixed $value): bool { abort_unless(is_bool($value), 422, 'Custom-field value must be boolean.'); return $value; }
    private function dateValue(mixed $value): string { abort_unless(is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) && DateTimeImmutable::createFromFormat('!Y-m-d', $value)?->format('Y-m-d') === $value, 422, 'Custom-field value must be a real ISO date.'); return $value; }
    private function dateTimeValue(mixed $value): string { abort_unless(is_string($value) && strlen($value) <= 100 && preg_match('/(?:Z|[+-]\d{2}:\d{2})$/D', $value), 422, 'Datetime custom fields require an explicit timezone offset.'); try { return (new DateTimeImmutable($value))->format(DATE_ATOM); } catch (\Throwable) { abort(422, 'Custom-field value must be a valid datetime.'); } }
    private function selectValue(mixed $value, array $rules): string { abort_unless(is_string($value) && in_array($value, $rules['options'] ?? [], true), 422, 'Custom-field value is not an approved option.'); return $value; }
    private function multiSelectValue(mixed $value, array $rules): array { abort_unless(is_array($value) && array_is_list($value) && count($value) === count(array_unique($value, SORT_REGULAR)), 422, 'Multi-select values must be a unique list.'); foreach ($value as $item) abort_unless(is_string($item) && in_array($item, $rules['options'] ?? [], true), 422, 'A multi-select value is not an approved option.'); sort($value, SORT_STRING); return $value; }
    private function emailValue(mixed $value): string { abort_unless(is_string($value) && strlen($value) <= 320 && filter_var($value, FILTER_VALIDATE_EMAIL), 422, 'Custom-field value must be a valid email address.'); return mb_strtolower($value); }
    private function phoneValue(mixed $value): string { abort_unless(is_string($value) && preg_match('/^\+?[0-9][0-9 ()-]{5,30}$/D', $value), 422, 'Custom-field value must be a bounded phone number.'); return trim($value); }
    private function urlValue(mixed $value): string { abort_unless(is_string($value) && strlen($value) <= 2048 && filter_var($value, FILTER_VALIDATE_URL) && in_array(parse_url($value, PHP_URL_SCHEME), ['http', 'https'], true), 422, 'Custom-field value must be an HTTP or HTTPS URL.'); return $value; }

    private function assertNumericBounds(string $value, array $rules): void
    {
        if (isset($rules['minimum'])) abort_if($this->compareDecimals($value, (string) $rules['minimum']) < 0, 422, 'Custom-field value is below its configured minimum.');
        if (isset($rules['maximum'])) abort_if($this->compareDecimals($value, (string) $rules['maximum']) > 0, 422, 'Custom-field value exceeds its configured maximum.');
    }

    private function compareDecimals(string $left, string $right): int
    {
        $normalize = function (string $value): array {
            $negative = str_starts_with($value, '-');
            $unsigned = $negative ? substr($value, 1) : $value;
            [$integer, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');
            $integer = ltrim($integer, '0') ?: '0';
            $fraction = rtrim($fraction, '0');
            if ($integer === '0' && $fraction === '') $negative = false;
            return [$negative, $integer, $fraction];
        };
        [$leftNegative, $leftInteger, $leftFraction] = $normalize($left);
        [$rightNegative, $rightInteger, $rightFraction] = $normalize($right);
        if ($leftNegative !== $rightNegative) return $leftNegative ? -1 : 1;
        $magnitude = strlen($leftInteger) <=> strlen($rightInteger);
        if ($magnitude === 0) $magnitude = strcmp($leftInteger, $rightInteger) <=> 0;
        if ($magnitude === 0) {
            $length = max(strlen($leftFraction), strlen($rightFraction));
            $magnitude = strcmp(str_pad($leftFraction, $length, '0'), str_pad($rightFraction, $length, '0')) <=> 0;
        }
        return $leftNegative ? -$magnitude : $magnitude;
    }

    private function canView(string $classification, User $actor): bool
    {
        return match ($classification) {
            'hr_private' => $actor->can('hr.custom-fields.sensitive.view'),
            'legal' => $actor->can('hr.custom-fields.legal.view'),
            default => $actor->can('hr.custom-fields.values.view'),
        };
    }

    private function decodeRules(mixed $rules): array { return is_array($rules) ? $rules : ($rules ? json_decode((string) $rules, true, 512, JSON_THROW_ON_ERROR) : []); }
    private function decrypt(?string $encrypted): mixed { return $encrypted === null ? null : json_decode(Crypt::decryptString($encrypted), true, 512, JSON_THROW_ON_ERROR); }
    private function canonicalValue(mixed $value): string { return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR); }
    private function valueChecksum(mixed $value): string { return hash('sha256', $this->canonicalValue($value)); }
    private function checksum(array $payload): string { ksort($payload); return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)); }
}
