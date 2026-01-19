@extends('layouts.app')

@section('title', ($servicePage->seo_title ?: $servicePage->name) . ' - ' . config('app.name'))

@section('content')
    @foreach ($sections as $section)
        @php
            $type = $section['type'] ?? '';
        @endphp

        @switch($type)
            @case('hero')
                @include('inquiry.sections.hero', ['section' => $section, 'servicePage' => $servicePage])
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
        @endswitch
    @endforeach
@endsection

@push('styles')
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/intl-tel-input@17/build/css/intlTelInput.css">
    <style>
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

                if (!condition || !condition.field) return;

                const target = form.querySelector(`[name="${condition.field}"]`);
                if (!target) return;

                const currentValue = target.value;
                const shouldShow = condition.operator === 'equals'
                    ? currentValue === condition.value
                    : currentValue !== condition.value;

                wrapper.style.display = shouldShow ? '' : 'none';

                const input = wrapper.querySelector('input, select, textarea');
                if (input) {
                    const requiredFlag = input.getAttribute('data-required') === 'true';
                    input.required = shouldShow && requiredFlag;
                }
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
                    preferredCountries: (form.getAttribute('data-phone-preferred-countries') || 'lk,us,gb,au')
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
            }
        });
    </script>
@endpush
