@php
    $data = $section['data'] ?? [];
    $showForm = false;

    if (!empty($data['form_id']) && !empty($servicePage->form)) {
        $showForm = $servicePage->form->id === $data['form_id'];
    } elseif (array_key_exists('show_form', $data) && !empty($servicePage->form)) {
        $showForm = (bool) $data['show_form'];
    } elseif (!empty($servicePage->form)) {
        $showForm = true;
    }
@endphp

<div class="inquiry-form-block">
    <div class="container">
        @if ($showForm)
            <div class="inquiry-form-card-wrap">
                <div class="inquiry-form-card">
                    <div class="filter-wrapper">
                        <div class="filter-input-wrap">
                            @include('inquiry.partials.form', ['form' => $servicePage->form, 'servicePage' => $servicePage, 'showIntro' => true])
                        </div>
                    </div>
                </div>
            </div>
        @endif

        <div class="inquiry-form-copy">
            <div class="section-title1 mb-3">
                @if (!empty($data['kicker']))
                    <span>{{ $data['kicker'] }}</span>
                @endif
                <h2>{{ $data['heading'] ?? 'Tell us what you need' }}</h2>
            </div>
            @if (!empty($data['description']))
                <p>{{ $data['description'] }}</p>
            @endif

            @if (!empty($data['steps']) && is_array($data['steps']))
                <div class="inquiry-steps">
                    @foreach ($data['steps'] as $index => $step)
                        <div class="inquiry-step">
                            <div class="inquiry-step-number">{{ $index + 1 }}</div>
                            <div class="inquiry-step-content">
                                <h5>{{ $step['title'] ?? 'Step' }}</h5>
                                @if (!empty($step['description']))
                                    <p>{{ $step['description'] }}</p>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            @if (!empty($data['note_title']) || !empty($data['note_body']))
                <div class="inquiry-form-note">
                    @if (!empty($data['note_title']))
                        <strong>{{ $data['note_title'] }}</strong>
                    @endif
                    @if (!empty($data['note_body']))
                        <p class="mb-0">{{ $data['note_body'] }}</p>
                    @endif
                </div>
            @endif
        </div>
    </div>
</div>
