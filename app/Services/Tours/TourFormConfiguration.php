<?php

namespace App\Services\Tours;

use App\Models\Service\ServiceType;
use Illuminate\Validation\ValidationException;

class TourFormConfiguration
{
    public static function fields(ServiceType $service): array
    {
        $fields = $service->form_config['fields'] ?? $service->form_config ?? [];

        return collect($fields)->filter(fn ($field, $key) => is_array($field)
            && in_array($field['type'] ?? null, ['text', 'textarea', 'number', 'date', 'time', 'select', 'radio', 'checkbox'], true)
            && !empty($field['label']))->map(fn ($field, $key) => [
                'name' => (string) $key,
                'type' => $field['type'],
                'label' => ($field['custom_label'] ?? '') ?: $field['label'],
                'required' => (bool) ($field['required'] ?? false),
                'placeholder' => $field['placeholder'] ?? null,
                'hint' => $field['hint'] ?? null,
                'validation' => $field['validation'] ?? [],
                'options' => collect($field['options'] ?? [])->map(fn ($option) => [
                    'value' => (string) ($option['value'] ?? ''),
                    'label' => (string) ($option['label'] ?? ''),
                ])->filter(fn ($option) => $option['value'] !== '' && $option['label'] !== '')->values()->all(),
            ])->values()->all();
    }

    public static function validate(ServiceType $service, mixed $answers): array
    {
        $answers = is_array($answers) ? $answers : [];
        $fields = collect(self::fields($service))->keyBy('name');
        $validated = [];

        foreach ($fields as $name => $field) {
            $value = $answers[$name] ?? null;
            if ($field['type'] === 'checkbox') {
                $value = filter_var($value, FILTER_VALIDATE_BOOL);
            } elseif ($field['type'] === 'number' && $value !== null && $value !== '') {
                if (!is_scalar($value) || !is_numeric($value)) {
                    throw ValidationException::withMessages(["custom_answers.{$name}" => "{$field['label']} must be a number."]);
                }
                $value = (float) $value;
                $min = $field['validation']['min'] ?? null;
                $max = $field['validation']['max'] ?? null;
                if (($min !== null && $value < $min) || ($max !== null && $value > $max)) {
                    throw ValidationException::withMessages(["custom_answers.{$name}" => "{$field['label']} is outside the allowed range."]);
                }
            } elseif ($field['type'] === 'date' && $value !== null && $value !== '') {
                if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || !checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
                    throw ValidationException::withMessages(["custom_answers.{$name}" => "{$field['label']} must be a valid date."]);
                }
            } elseif ($field['type'] === 'time' && $value !== null && $value !== '') {
                if (!is_string($value) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value)) {
                    throw ValidationException::withMessages(["custom_answers.{$name}" => "{$field['label']} must be a valid 24-hour time."]);
                }
            } elseif ($value !== null) {
                if (!is_scalar($value) || mb_strlen((string) $value) > 2000) {
                    throw ValidationException::withMessages(["custom_answers.{$name}" => "{$field['label']} is invalid or too long."]);
                }
                $value = trim((string) $value);
            }

            if ($field['required'] && ($value === null || $value === '' || ($field['type'] === 'checkbox' && !$value))) {
                throw ValidationException::withMessages(["custom_answers.{$name}" => "{$field['label']} is required."]);
            }
            if (in_array($field['type'], ['select', 'radio'], true) && $value !== null && $value !== ''
                && !collect($field['options'])->contains('value', (string) $value)) {
                throw ValidationException::withMessages(["custom_answers.{$name}" => "Choose a valid {$field['label']} option."]);
            }
            if ($value !== null && $value !== '') {
                $validated[$name] = $value;
            }
        }

        if (array_diff(array_keys($answers), $fields->keys()->all())) {
            throw ValidationException::withMessages(['custom_answers' => 'This form has changed. Reload the tour form before saving.']);
        }

        return $validated;
    }
}
