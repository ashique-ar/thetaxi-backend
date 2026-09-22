<?php

namespace App\Http\Requests\Website\CmsContent;

use App\Models\InquiryForm;
use App\Models\Website\CmsContent;
use App\Models\Website\CmsContentType;
use Illuminate\Validation\Validator;

trait ValidatesServiceInquiryConfiguration
{
    protected function serviceInquiryRules(bool $updating = false): array
    {
        $prefix = $updating ? ['sometimes'] : [];

        return [
            'inquiry_form_id' => [...$prefix, 'nullable', 'uuid', 'exists:inquiry_forms,id'],
            'custom_fields.inquiry_cta' => [...$prefix, 'nullable', 'array'],
            'custom_fields.inquiry_cta.enabled' => [...$prefix, 'boolean'],
            'custom_fields.inquiry_cta.label' => [...$prefix, 'nullable', 'string', 'max:80'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $existing = $this->route('cms_content');
            $existing = $existing instanceof CmsContent ? $existing : null;
            $typeId = $this->input('cms_content_type_id', $existing?->cms_content_type_id);
            $isService = CmsContentType::withInactive()->whereKey($typeId)->where('slug', 'services')->exists();
            $hasInquiryPayload = $this->exists('inquiry_form_id') || $this->has('custom_fields.inquiry_cta');

            if (! $isService && $hasInquiryPayload) {
                $validator->errors()->add(
                    'inquiry_form_id',
                    'Inquiry forms and managed inquiry CTAs are only available for services content.'
                );

                return;
            }

            if (! $isService) {
                return;
            }

            $customFields = array_replace_recursive(
                $existing?->custom_fields ?? [],
                $this->input('custom_fields', []) ?: []
            );
            $cta = $customFields['inquiry_cta'] ?? [];
            $status = $this->input('status', $existing?->status ?? 'draft');
            $formId = $this->input('inquiry_form_id', $existing?->inquiry_form_id);

            if (($cta['enabled'] ?? false) && trim((string) ($cta['label'] ?? '')) === '') {
                $validator->errors()->add('custom_fields.inquiry_cta.label', 'The inquiry CTA label is required when the CTA is enabled.');
            }

            if ($status === 'published' && ($cta['enabled'] ?? false)) {
                $activeFormExists = $formId && InquiryForm::withInactive()
                    ->whereKey($formId)
                    ->where('is_active', true)
                    ->exists();

                if (! $activeFormExists) {
                    $validator->errors()->add(
                        'inquiry_form_id',
                        'A published service with an enabled inquiry CTA must use an active inquiry form.'
                    );
                }
            }
        }];
    }
}
