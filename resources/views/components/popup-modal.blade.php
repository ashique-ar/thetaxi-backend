{{--
    Popup Modal Component
    
    This component renders a marketing popup modal that can be displayed on the public website.
    It supports different display frequencies (always, once per session, once per day) and
    handles close button and CTA button interactions.
    
    Requirements: 2.1, 2.4, 2.5
--}}

@props([
    'popup' => null,
])

@if ($popup)
    <div id="popup-modal-{{ $popup['id'] ?? 'default' }}" class="popup-modal-overlay"
        data-popup-id="{{ $popup['id'] ?? '' }}" data-display-frequency="{{ $popup['display_frequency'] ?? 'always' }}"
        style="display: none;">
        <div class="popup-modal-container">
            <div class="popup-modal-content">
                {{-- Close Button --}}
                <button type="button" class="popup-close-btn" aria-label="Close popup">
                    <i class="bi bi-x-lg"></i>
                </button>

                {{-- Popup Image --}}
                @if (!empty($popup['image']))
                    <div class="popup-image-wrapper">
                        <img src="{{ s3_asset($popup['image']) }}" alt="{{ $popup['title'] ?? 'Promotional popup' }}"
                            class="popup-image" loading="lazy">
                    </div>
                @endif

                {{-- Popup Body --}}
                <div class="popup-body">
                    {{-- Title --}}
                    @if (!empty($popup['title']))
                        <h3 class="popup-title">{{ $popup['title'] }}</h3>
                    @endif

                    {{-- Content --}}
                    @if (!empty($popup['content']))
                        <div class="popup-content">
                            {!! $popup['content'] !!}
                        </div>
                    @endif

                    {{-- CTA Button --}}
                    @if (!empty($popup['cta_text']) && !empty($popup['cta_link']))
                        <div class="popup-cta-wrapper">
                            <a href="{{ $popup['cta_link'] }}" class="popup-cta-btn" data-popup-cta="true">
                                {{ $popup['cta_text'] }}
                                <svg width="10" height="10" viewBox="0 0 10 10"
                                    xmlns="http://www.w3.org/2000/svg">
                                    <path
                                        d="M1 9L9 1M9 1C7.22222 1.33333 3.33333 2 1 1M9 1C8.66667 2.66667 8 6.33333 9 9"
                                        stroke="currentColor" stroke-width="1.5" stroke-linecap="round" fill="none">
                                    </path>
                                </svg>
                            </a>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endif

<style>
    /* Popup Modal Overlay */
    .popup-modal-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.6);
        z-index: 99999;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 20px;
        opacity: 0;
        visibility: hidden;
        transition: opacity 0.3s ease, visibility 0.3s ease;
    }

    .popup-modal-overlay.active {
        opacity: 1;
        visibility: visible;
    }

    /* Popup Container */
    .popup-modal-container {
        background: #ffffff;
        border-radius: 16px;
        max-width: 500px;
        width: 100%;
        max-height: 90vh;
        overflow: hidden;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        transform: scale(0.9) translateY(20px);
        transition: transform 0.3s ease;
        position: relative;
    }

    .popup-modal-overlay.active .popup-modal-container {
        transform: scale(1) translateY(0);
    }

    /* Popup Content */
    .popup-modal-content {
        position: relative;
    }

    /* Close Button */
    .popup-close-btn {
        position: absolute;
        top: 12px;
        right: 12px;
        width: 36px;
        height: 36px;
        border: none;
        background: rgba(255, 255, 255, 0.9);
        border-radius: 50%;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 10;
        transition: all 0.3s ease;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
    }

    .popup-close-btn:hover {
        background: #ffffff;
        transform: rotate(90deg);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
    }

    .popup-close-btn i {
        font-size: 16px;
        color: #333;
    }

    /* Popup Image */
    .popup-image-wrapper {
        width: 100%;
        max-height: 250px;
        overflow: hidden;
    }

    .popup-image {
        width: 100%;
        height: auto;
        object-fit: cover;
        display: block;
    }

    /* Popup Body */
    .popup-body {
        padding: 24px;
    }

    /* Popup Title */
    .popup-title {
        font-size: 24px;
        font-weight: 700;
        color: #1a1a1a;
        margin: 0 0 16px 0;
        line-height: 1.3;
    }

    /* Popup Content */
    .popup-content {
        font-size: 15px;
        color: #555;
        line-height: 1.6;
        margin-bottom: 20px;
    }

    .popup-content p {
        margin: 0 0 12px 0;
    }

    .popup-content p:last-child {
        margin-bottom: 0;
    }

    .popup-content ul,
    .popup-content ol {
        margin: 0 0 12px 0;
        padding-left: 20px;
    }

    .popup-content li {
        margin-bottom: 6px;
    }

    /* CTA Button Wrapper */
    .popup-cta-wrapper {
        margin-top: 20px;
    }

    /* CTA Button */
    .popup-cta-btn {
        display: inline-flex;
        align-items: center;
        gap: 10px;
        background: var(--primary-color1, #BF2629);
        color: #ffffff;
        padding: 14px 28px;
        border-radius: 8px;
        font-size: 15px;
        font-weight: 600;
        text-decoration: none;
        transition: all 0.3s ease;
        border: none;
        cursor: pointer;
    }

    .popup-cta-btn:hover {
        background: #a02023;
        color: #ffffff;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(191, 38, 41, 0.3);
    }

    .popup-cta-btn svg {
        transition: transform 0.3s ease;
    }

    .popup-cta-btn:hover svg {
        transform: translate(3px, -3px);
    }

    /* Responsive Styles */
    @media (max-width: 576px) {
        .popup-modal-overlay {
            padding: 15px;
        }

        .popup-modal-container {
            max-width: 100%;
            border-radius: 12px;
        }

        .popup-image-wrapper {
            max-height: 180px;
        }

        .popup-body {
            padding: 20px;
        }

        .popup-title {
            font-size: 20px;
        }

        .popup-content {
            font-size: 14px;
        }

        .popup-cta-btn {
            width: 100%;
            justify-content: center;
            padding: 12px 24px;
        }

        .popup-close-btn {
            width: 32px;
            height: 32px;
            top: 10px;
            right: 10px;
        }

        .popup-close-btn i {
            font-size: 14px;
        }
    }

    /* Animation for popup entrance */
    @keyframes popupFadeIn {
        from {
            opacity: 0;
            transform: scale(0.9) translateY(20px);
        }

        to {
            opacity: 1;
            transform: scale(1) translateY(0);
        }
    }

    @keyframes popupFadeOut {
        from {
            opacity: 1;
            transform: scale(1) translateY(0);
        }

        to {
            opacity: 0;
            transform: scale(0.9) translateY(20px);
        }
    }

    .popup-modal-overlay.closing .popup-modal-container {
        animation: popupFadeOut 0.3s ease forwards;
    }
</style>
