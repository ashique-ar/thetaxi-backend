<?php

namespace App\Models;

use App\Models\Website\CmsContent;
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

    public function cmsServices(): HasMany
    {
        return $this->hasMany(CmsContent::class, 'inquiry_form_id')->withoutGlobalScope('active');
    }

    /**
     * Build Laravel validation rules for active fields.
     *
     * @return array<string, array<int, string>>
     */
    public function buildValidationRules(array $input = []): array
    {
        $rules = [];

        foreach ($this->fields as $field) {
            $ruleParts = $this->isFieldVisible($field, $input)
                ? $this->buildFieldRuleParts($field)
                : ['exclude'];

            if (! empty($ruleParts)) {
                $rules[$field->name] = $ruleParts;
                if ($field->type === 'checkbox' && $ruleParts !== ['exclude'] && ! empty($field->options)) {
                    $values = collect($field->options)->map(fn ($option) => is_array($option) ? ($option['value'] ?? null) : $option)
                        ->filter(fn ($value) => $value !== null && $value !== '');
                    if ($values->isNotEmpty()) {
                        $rules[$field->name.'.*'] = ['in:'.$values->implode(',')];
                    }
                }
            }
        }

        return $rules;
    }

    private function isFieldVisible(InquiryFormField $field, array $input): bool
    {
        $condition = $field->conditional_logic;
        if (is_array($condition) && array_is_list($condition)) {
            $condition = $condition[0] ?? null;
        }
        if (! is_array($condition) || empty($condition['field'])) {
            return true;
        }

        $current = $input[$condition['field']] ?? null;
        $currentValues = is_array($current) ? $current : [$current];
        $expected = $condition['value'] ?? null;
        $expectedValues = is_array($expected) ? $expected : [$expected];
        $matches = count(array_intersect(array_map('strval', $currentValues), array_map('strval', $expectedValues))) > 0;

        return ($condition['operator'] ?? 'equals') === 'not_equals' ? ! $matches : $matches;
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

    public static function resolveSubmissionWorkflow(array $settings, ?string $fallback = null): string
    {
        return Arr::get($settings, 'submission_workflow') ?: ($fallback ?: 'general');
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
        $hasConditionalRequired = ! in_array('required', $rules, true) && collect($rules)->contains(function ($rule) {
            return Str::startsWith($rule, 'required_');
        });
        $hasNullable = in_array('nullable', $rules, true);

        if ($field->is_required && ! $hasRequired) {
            $rules[] = 'required';
        } elseif (! $field->is_required && $hasConditionalRequired && ! $hasNullable) {
            $rules[] = 'nullable';
        } elseif (! $field->is_required && ! $hasNullable && ! $hasRequired) {
            $rules[] = 'nullable';
        }

        if (in_array($field->type, ['select', 'radio'], true) && ! empty($field->options)) {
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
                $rules[] = 'in:'.$values->implode(',');
            }
        }

        if (! $rawRules) {
            $rules = array_merge($rules, $this->defaultRulesForType($field->type));
        }

        if ($field->type === 'file') {
            $rules = array_values(array_filter($rules, fn ($rule) => ! in_array($rule, ['string', 'max:255'], true)));
            $rules[] = 'file';
            $rules[] = 'mimes:pdf,jpg,jpeg,png,doc,docx';
            $rules[] = 'max:5120';
        }

        if ($field->type === 'checkbox') {
            $rules = array_values(array_filter($rules, fn ($rule) => $rule !== 'string' && $rule !== 'max:255'));
            $rules[] = 'array';
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
            'file' => ['file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:5120'],
            'checkbox' => ['array'],
            default => ['string', 'max:255'],
        };
    }
}
