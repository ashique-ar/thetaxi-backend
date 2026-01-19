<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class InquiryForm extends BaseModel
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'submit_label',
        'success_message',
        'settings',
        'is_active',
        'created_user_id',
        'updated_user_id',
    ];

    protected $casts = [
        'settings' => 'array',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function fields(): HasMany
    {
        return $this->hasMany(InquiryFormField::class)
            ->orderBy('sort_order');
    }

    /**
     * Build Laravel validation rules for active fields.
     *
     * @return array<string, array<int, string>>
     */
    public function buildValidationRules(): array
    {
        $rules = [];

        foreach ($this->fields as $field) {
            $ruleParts = $this->buildFieldRuleParts($field);

            if (!empty($ruleParts)) {
                $rules[$field->name] = $ruleParts;
            }
        }

        return $rules;
    }

    /**
     * Resolve a field name for contact data from settings.
     */
    public function resolveContactField(string $key, array $fallbacks): ?string
    {
        $configured = Arr::get($this->settings ?? [], $key);
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        foreach ($fallbacks as $fallback) {
            if ($this->fields->where('name', $fallback)->count() > 0) {
                return $fallback;
            }
        }

        return null;
    }

    /**
     * Build field rule parts from stored config.
     *
     * @return array<int, string>
     */
    protected function buildFieldRuleParts(InquiryFormField $field): array
    {
        $rules = [];
        $rawRules = $field->validation_rules;

        if ($rawRules) {
            $rules = array_values(array_filter(explode('|', $rawRules)));
        }

        $hasRequired = in_array('required', $rules, true) || collect($rules)->contains(function ($rule) {
            return Str::startsWith($rule, 'required');
        });
        $hasNullable = in_array('nullable', $rules, true);

        if ($field->is_required && !$hasRequired) {
            $rules[] = 'required';
        } elseif (!$field->is_required && !$hasNullable && !$hasRequired) {
            $rules[] = 'nullable';
        }

        if (in_array($field->type, ['select', 'radio'], true) && !empty($field->options)) {
            $values = collect($field->options)
                ->map(function ($option) {
                    if (is_array($option)) {
                        return $option['value'] ?? null;
                    }
                    return $option;
                })
                ->filter(fn ($value) => $value !== null && $value !== '')
                ->unique()
                ->values();

            if ($values->isNotEmpty()) {
                $rules[] = 'in:' . $values->implode(',');
            }
        }

        if (!$rawRules) {
            $rules = array_merge($rules, $this->defaultRulesForType($field->type));
        }

        return array_values(array_unique($rules));
    }

    /**
     * Default validation rules by input type.
     *
     * @return array<int, string>
     */
    protected function defaultRulesForType(string $type): array
    {
        return match ($type) {
            'email' => ['email', 'max:255'],
            'tel' => ['string', 'max:20'],
            'number' => ['numeric'],
            'textarea' => ['string', 'max:2000'],
            'select', 'radio' => ['string'],
            'date' => ['date'],
            'time' => ['date_format:H:i'],
            default => ['string', 'max:255'],
        };
    }
}
