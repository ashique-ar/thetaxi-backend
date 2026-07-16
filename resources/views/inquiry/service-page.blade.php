@extends('layouts.app')

@section('title', ($servicePage->seo_title ?: $servicePage->name) . ' - ' . config('app.name'))

@push('meta')
@include('partials.seo', ['model' => $servicePage, 'sections' => $sections])
@endpush

@section('content')
@php
$hasFormBlock = collect($sections)->contains(fn($s) => ($s['type'] ?? '') === 'form_block');
@endphp

@foreach ($sections as $section)
@php
$type = $section['type'] ?? '';
@endphp

@switch($type)
@case('hero')
@include('inquiry.sections.hero', ['section' => $section, 'servicePage' => $servicePage, 'hasFormBlock' => $hasFormBlock])
@break

@case('form_block')
@include('inquiry.sections.form_block', ['section' => $section, 'servicePage' => $servicePage])
@break

@case('content_block')
@include('inquiry.sections.content_block', ['section' => $section])
@break

@case('features')
@include('inquiry.sections.features', ['section' => $section])
@break

@case('services')
@include('inquiry.sections.services', ['section' => $section])
@break

@case('benefits')
@include('inquiry.sections.benefits', ['section' => $section])
@break

@case('faq')
@include('inquiry.sections.faq', ['section' => $section])
@break

@case('contact_info')
@include('inquiry.sections.contact_info', ['section' => $section])
@break
@endswitch
@endforeach
@endsection

@push('styles')
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/intl-tel-input@17/build/css/intlTelInput.css">
<style>
    /* Inquiry form rendered directly by a hero stays in normal flow. */
    .inquiry-form-section {
        position: relative;
        margin-top: 32px;
        z-index: 10;
    }

    /* Update hero to have proper bottom padding */
    .home4-banner-section {
        padding-bottom: 0;
        margin-bottom: 0;
    }

    @media (max-width: 576px) {
        .home4-banner-section {
            padding-bottom: 0;
            margin-bottom: 0;
        }
    }

    @media (max-width: 1199px) {
        .home4-banner-section {
            padding-bottom: 0;
            margin-bottom: 0;
        }
    }

    .benefit-card {
        padding: 2rem 1rem;
        border-radius: 10px;
        box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
        transition: transform 0.3s ease, box-shadow 0.3s ease;
        background: white;
        height: 100%;
    }

    .benefit-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
    }

    .inquiry-full-width {
        grid-column: 1 / -1;
        margin-bottom: 1rem;
    }

    .inquiry-full-width textarea {
        width: 100%;
        padding: 15px;
        border: 1px solid #ddd;
        border-radius: 5px;
        font-family: inherit;
        font-size: 14px;
        resize: vertical;
        min-height: 120px;
    }

    .inquiry-full-width textarea:focus {
        outline: none;
        border-color: var(--primary-color1);
        box-shadow: 0 0 0 2px rgba(191, 38, 41, 0.1);
    }

    .inquiry-submit-btn {
        width: 100%;
        margin-top: 1rem;
        grid-column: 1 / -1;
    }

    .iti {
        width: 100%;
    }

    .iti__selected-flag {
        background: none;
    }

    .nice-select {
        position: relative;
        z-index: 10;
    }

    .nice-select .list {
        z-index: 100;
    }

    .inquiry-radio-group {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
        padding: 8px 0;
    }

    .inquiry-radio-option {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-size: 14px;
        color: #333;
    }

    .inquiry-form-block {
        position: relative;
        z-index: 12;
        margin-top: 40px;
        margin-bottom: 80px;
    }

    .inquiry-form-card {
        /* background: #fff;
        border-radius: 16px;
        padding: 32px; */
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
        /* margin-top: 0; */
    }

    .inquiry-form-card-wrap {
        /* width: min(100%, 760px);
        margin-left: auto;
        margin-bottom: -227.5px; */
        transform: translateY(-227.5px);
    }

    .inquiry-form-copy {
        /* width: min(100%, 680px); */
        margin-top: -180px;
    }

    .inquiry-form-card .filter-input-wrap .filter-input.show {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    }

    .inquiry-form-card .inquiry-field-wrap {
        min-width: 0;
    }

    .inquiry-form-card .single-search-box {
        align-content: center;
    }

    .inquiry-form-card .single-search-box > input,
    .inquiry-form-card .single-search-box > select,
    .inquiry-form-card .single-search-box > textarea,
    .inquiry-form-card .single-search-box > .nice-select,
    .inquiry-form-card .single-search-box > .custom-select-dropdown,
    .inquiry-form-card .single-search-box > .iti {
        flex: 1 1 calc(100% - 30px);
        width: auto;
    }

    .inquiry-form-card .inquiry-help-text,
    .inquiry-form-card .inquiry-field-wrap > .text-danger {
        padding: 0 12px;
    }

    .inquiry-form-card .inquiry-full-width .single-search-box textarea {
        border: 0;
        padding: 8px 0;
        box-shadow: none;
    }

    .inquiry-form-intro {
        text-align: left;
        margin-bottom: 24px;
        grid-column: 1 / -1;
    }

    .inquiry-form-intro h3 {
        margin-bottom: 8px;
        font-size: 24px;
        font-weight: 600;
    }

    .inquiry-form-intro p {
        margin-bottom: 0;
        color: #6c757d;
    }

    .inquiry-steps {
        display: grid;
        gap: 16px;
        margin-top: 24px;
    }

    .inquiry-step {
        display: flex;
        gap: 12px;
        align-items: flex-start;
    }

    .inquiry-step-number {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        background: var(--primary-color1);
        color: #fff;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-weight: 600;
        flex-shrink: 0;
    }

    .inquiry-step-content h5 {
        margin-bottom: 4px;
        font-size: 16px;
    }

    .inquiry-form-note {
        margin-top: 20px;
        padding: 16px;
        border-radius: 12px;
        background: #f8f9fb;
        border-left: 4px solid var(--primary-color1);
    }

    .inquiry-form-note strong {
        display: block;
        margin-bottom: 4px;
    }

    .inquiry-help-text {
        display: block;
        margin-top: 6px;
        font-size: 13px;
        color: #6c757d;
    }

    .inquiry-content-section {
        margin-bottom: 100px;
    }

    .inquiry-content-body p {
        margin-bottom: 12px;
    }

    .inquiry-content-body ul {
        padding-left: 18px;
        margin-bottom: 0;
    }

    .inquiry-content-body li {
        margin-bottom: 6px;
    }

    @media (min-width: 1400px) and (max-width: 1599px) {
        .inquiry-form-card-wrap {
            margin-bottom: -197.5px;
            transform: translateY(-197.5px);
        }
    }

    @media (min-width: 1200px) and (max-width: 1399px) {
        .inquiry-form-card-wrap {
            margin-bottom: -192.5px;
            transform: translateY(-192.5px);
        }
    }

    @media (min-width: 992px) and (max-width: 1199px) {
        .inquiry-form-card-wrap {
            margin-bottom: -190px;
            transform: translateY(-190px);
        }
    }

    @media (max-width: 991px) {
        .inquiry-form-block {
            margin-top: 32px;
            margin-bottom: 64px;
        }

        .inquiry-form-card {
            padding: 24px;
            margin-top: 0;
        }

        .inquiry-form-card-wrap {
            margin-left: 0;
            margin-bottom: 0;
            transform: none;
        }

        .inquiry-form-copy {
            margin-top: 40px;
        }
    }

    @media (max-width: 767px) {
        .home4-banner-section .banner-video-area,
        .home4-banner-section .banner-video-area img {
            min-height: 420px;
            height: 420px;
        }

        .inquiry-form-block {
            margin-top: 24px;
            margin-bottom: 48px;
        }

        .inquiry-form-card {
            padding: 18px;
            border-radius: 12px;
        }

        .inquiry-form-copy {
            margin-top: 32px;
        }

        .inquiry-form-card .filter-input-wrap .filter-input.show {
            grid-template-columns: minmax(0, 1fr) !important;
        }

        .inquiry-form-card .inquiry-help-text,
        .inquiry-form-card .inquiry-field-wrap > .text-danger {
            padding: 0 6px;
        }
    }

    @media (max-width: 576px) {
        .home4-banner-section .banner-video-area,
        .home4-banner-section .banner-video-area img {
            min-height: 360px;
            height: 360px;
        }

        .inquiry-form-card {
            padding: 14px;
        }
    }
</style>
@endpush

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/intl-tel-input@17/build/js/intlTelInput.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.querySelector('[data-inquiry-form]');

        if (!form) {
            return;
        }

        form.addEventListener('submit', function(e) {
            const submitBtn = form.querySelector('button[type="submit"]');
            const btnText = submitBtn ? submitBtn.querySelector('span') : null;
            const originalText = btnText ? btnText.textContent : null;

            if (!submitBtn || submitBtn.disabled) {
                e.preventDefault();
                return;
            }

            submitBtn.disabled = true;
            submitBtn.style.opacity = '0.7';
            if (btnText) {
                btnText.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
            }

            setTimeout(() => {
                submitBtn.disabled = false;
                submitBtn.style.opacity = '1';
                if (btnText && originalText) {
                    btnText.textContent = originalText;
                }
            }, 30000);
        });

        form.querySelectorAll('textarea').forEach((textarea) => {
            textarea.addEventListener('input', function() {
                this.style.height = 'auto';
                this.style.height = this.scrollHeight + 'px';
            });
        });

        const conditionalFields = Array.from(form.querySelectorAll('[data-conditional]'));
        const updateConditionalVisibility = (wrapper) => {
            const raw = wrapper.getAttribute('data-conditional');
            if (!raw) return;

            let condition = null;
            try {
                condition = JSON.parse(raw);
            } catch (error) {
                return;
            }

            if (Array.isArray(condition)) {
                condition = condition[0] || null;
            }

            if (!condition || !condition.field) return;

            const targets = Array.from(form.querySelectorAll(`[name="${condition.field}"]`));
            if (!targets.length) return;

            const currentValues = targets
                .map((target) => {
                    if (target.type === 'checkbox' || target.type === 'radio') {
                        return target.checked ? target.value : null;
                    }
                    return target.value;
                })
                .filter((value) => value !== null && value !== '');

            const normalizedCurrent = (currentValues.length ? currentValues : ['']).map(String);
            const conditionValues = Array.isArray(condition.value) ? condition.value : [condition.value];
            const normalizedCondition = conditionValues.map((value) => value === null || value ===
                undefined ? '' : String(value));
            const matches = normalizedCurrent.some((value) => normalizedCondition.includes(value));
            const shouldShow = condition.operator === 'not_equals' ? !matches : matches;

            if (shouldShow) {
                wrapper.style.removeProperty('display');
            } else {
                wrapper.style.setProperty('display', 'none', 'important');
            }

            const inputs = Array.from(wrapper.querySelectorAll('input, select, textarea'));
            inputs.forEach((input) => {
                const requiredFlag = input.getAttribute('data-required') === 'true';
                input.required = shouldShow && requiredFlag;
                if (!shouldShow) {
                    if (input.type === 'checkbox' || input.type === 'radio') {
                        input.checked = false;
                    } else if (input.tagName === 'SELECT') {
                        input.selectedIndex = 0;
                    } else {
                        input.value = '';
                    }
                }
            });
        };

        conditionalFields.forEach((wrapper) => {
            updateConditionalVisibility(wrapper);
        });

        form.addEventListener('change', (event) => {
            conditionalFields.forEach((wrapper) => updateConditionalVisibility(wrapper));
        });

        form.querySelectorAll('input[type="tel"]').forEach((input) => {
            const iti = window.intlTelInput(input, {
                initialCountry: form.getAttribute('data-phone-initial-country') || 'lk',
                preferredCountries: (form.getAttribute('data-phone-preferred-countries') ||
                        'lk,us,gb,au')
                    .split(',')
                    .map((country) => country.trim())
                    .filter(Boolean),
                separateDialCode: true,
                utilsScript: 'https://cdn.jsdelivr.net/npm/intl-tel-input@17/build/js/utils.js'
            });

            form.addEventListener('submit', function() {
                if (iti && iti.isValidNumber()) {
                    input.value = iti.getNumber();
                }
            });
        });

        if (typeof $ !== 'undefined' && $.fn.niceSelect) {
            $('select.form-select').niceSelect();
            $(document).on('change', 'select.form-select', function() {
                conditionalFields.forEach((wrapper) => updateConditionalVisibility(wrapper));
            });
        }
    });
</script>

{{-- FAQ JSON-LD generation (for SEO) --}}
@php
$faqSection = collect($sections)->first(fn($s) => ($s['type'] ?? '') === 'faq');
if ($faqSection && !empty($faqSection['data']['items'])) {
$faqItems = array_map(function ($item) {
return [
'@type' => 'Question',
'name' => $item['question'] ?? '',
'acceptedAnswer' => [
'@type' => 'Answer',
'text' => $item['answer'] ?? '',
],
];
}, $faqSection['data']['items']);

$faqJsonLd = [
'@context' => 'https://schema.org',
'@type' => 'FAQPage',
'mainEntity' => $faqItems,
];
}
@endphp

@if (!empty($faqJsonLd))
<script type="application/ld+json">
    {!! json_encode($faqJsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) !!}
</script>
@endif
@endpush
