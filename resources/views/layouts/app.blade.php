<!doctype html>
<html lang="en">

<head>
    <!-- Required meta tags -->
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <!-- Bootstrap CSS -->
    <link href="{{ asset('assets/css/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/css/jquery-ui.css') }}" rel="stylesheet">
    <!-- Bootstrap Icon CSS -->
    <link href="{{ asset('assets/css/bootstrap-icons.css') }}" rel="stylesheet">
    <!-- CSS -->
    <link href="{{ asset('assets/css/animate.min.css') }}" rel="stylesheet">
    <!-- FancyBox CSS -->
    <link href="{{ asset('assets/css/jquery.fancybox.min.css') }}" rel="stylesheet">

    <link href="{{ asset('assets/css/nice-select.css') }}" rel="stylesheet">

    <!-- Swiper slider CSS -->
    <link rel="stylesheet" href="{{ asset('assets/css/swiper-bundle.min.css') }}">
    <!-- Slick slider CSS -->
    <link rel="stylesheet" href="{{ asset('assets/css/slick.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/slick-theme.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/daterangepicker.css') }}">
    <!-- BoxIcon  CSS -->
    <link href="{{ asset('assets/css/boxicons.min.css') }}" rel="stylesheet">
    <!-- AOS Animation CSS -->
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <!--  Style CSS  -->
    <link rel="stylesheet" href="{{ assetVersion('assets/css/style.css') }}">

    <!-- Theme-specific CSS (loaded conditionally) -->
    @if (is_theme('theme-02'))
        <script src="https://cdn.tailwindcss.com"></script>
        <link rel="stylesheet" href="{{ assetVersion('assets/css/theme-02-tw.css') }}">
    @else
        <link rel="stylesheet" href="{{ assetVersion('assets/css/theme-01.css') }}">
    @endif

    <!-- Booking Form CSS -->
    <link rel="stylesheet" href="{{ assetVersion('assets/css/booking-form.css') }}">
    <link rel="stylesheet" href="{{ assetVersion('assets/css/package-buttons.css') }}">

    <!-- Popup Modal CSS -->
    <link rel="stylesheet" href="{{ assetVersion('assets/css/popup-modal.css') }}">

    @php
        $pageTitle = trim($__env->yieldContent('title'));
        $siteName = $settings['site_name'] ?? $settings['brand_name'] ?? 'Company';
        $titleTemplate = $settings['seo_title_template'] ?? '';
        $seoExactTitle = trim($__env->yieldContent('seo_exact_title')) === 'true';
        // Prefer explicit page title when available
        $computedTitle = $pageTitle !== '' ? $pageTitle : $siteName;
        // Support the same editable placeholders used by the shared SEO partial.
        if (!$seoExactTitle && $titleTemplate) {
            $computedTitle = strtr($titleTemplate, [
                '{page_title}' => $pageTitle !== '' ? $pageTitle : $siteName,
                '{site_name}' => $siteName,
                '{company_name}' => $settings['company_name'] ?? $siteName,
                '{tagline}' => $settings['site_tagline'] ?? ($settings['brand_tagline'] ?? ''),
                '{year}' => date('Y'),
            ]);
        }

        // Override with custom page title if set
        if (!$seoExactTitle && !empty($settings['seo_title_custom'])) {
            $computedTitle = strtr($settings['seo_title_custom'], [
                '{page_title}' => $pageTitle !== '' ? $pageTitle : $siteName,
                '{site_name}' => $siteName,
                '{company_name}' => $settings['company_name'] ?? $siteName,
                '{tagline}' => $settings['site_tagline'] ?? ($settings['brand_tagline'] ?? ''),
                '{year}' => date('Y'),
            ]);
        }
        $metaDescription = trim($settings['seo_meta_description'] ?? '');
        $metaKeywords = trim($settings['seo_keywords'] ?? '');
        $ogImage = $settings['seo_og_image'] ?? '';
        $ogImageUrl = '';
        if ($ogImage) {
            $ogImagePath = is_array($ogImage)
                ? $ogImage['path'] ?? ($ogImage['url'] ?? ($ogImage[0] ?? null))
                : (is_object($ogImage)
                    ? $ogImage->path ?? ($ogImage->url ?? null)
                    : $ogImage);

            if (is_string($ogImagePath) && $ogImagePath !== '') {
                $ogImageUrl = filter_var($ogImagePath, FILTER_VALIDATE_URL) ? $ogImagePath : s3_asset($ogImagePath);
            }
        }
        $twitterCard = $settings['seo_twitter_card'] ?? 'summary';
        $metaStack = trim($__env->yieldPushContent('meta'));
    @endphp

    <!-- Title -->
    <title>{{ $computedTitle }}</title>
    @if (trim($metaStack) === '')
        @include('partials.seo', ['pageTitle' => $pageTitle])
    @else
        {!! $metaStack !!}
    @endif
    @php($favicon = $settings['brand_favicon'] ?? $settings['favicon'] ?? null)
    <link rel="icon"
        href="{{ $favicon ? s3_asset($favicon) : asset('assets/img/favicon.ico') }}"
        type="image/x-icon">

    @if (!empty($settings['google_tag_manager_id']))
        <!-- Google Tag Manager -->
        <script>
            (function(w, d, s, l, i) {
                w[l] = w[l] || [];
                w[l].push({
                    'gtm.start': new Date().getTime(),
                    event: 'gtm.js'
                });
                var f = d.getElementsByTagName(s)[0],
                    j = d.createElement(s),
                    dl = l != 'dataLayer' ? '&l=' + l : '';
                j.async = true;
                j.src = 'https://www.googletagmanager.com/gtm.js?id=' + i + dl;
                f.parentNode.insertBefore(j, f);
            })(window, document, 'script', 'dataLayer', '{{ $settings['google_tag_manager_id'] }}');
        </script>
    @endif

    @if (!empty($settings['google_analytics_id']))
        <!-- Google Analytics -->
        <script async src="https://www.googletagmanager.com/gtag/js?id={{ $settings['google_analytics_id'] }}"></script>
        <script>
            window.dataLayer = window.dataLayer || [];

            function gtag() {
                dataLayer.push(arguments);
            }
            gtag('js', new Date());
            gtag('config', "{{ $settings['google_analytics_id'] }}");
        </script>
    @endif

    @if (!empty($settings['facebook_pixel_id']))
        <!-- Facebook Pixel -->
        <script>
            ! function(f, b, e, v, n, t, s) {
                if (f.fbq) return;
                n = f.fbq = function() {
                    n.callMethod ? n.callMethod.apply(n, arguments) : n.queue.push(arguments);
                };
                if (!f._fbq) f._fbq = n;
                n.push = n;
                n.loaded = true;
                n.version = '2.0';
                n.queue = [];
                t = b.createElement(e);
                t.async = true;
                t.src = v;
                s = b.getElementsByTagName(e)[0];
                s.parentNode.insertBefore(t, s);
            }(window, document, 'script', 'https://connect.facebook.net/en_US/fbevents.js');
            fbq('init', '{{ $settings['facebook_pixel_id'] }}');
            fbq('track', 'PageView');
        </script>
    @endif

    @if (!empty($settings['google_ads_id']))
        <!-- Google Ads -->
        <script async src="https://www.googletagmanager.com/gtag/js?id={{ $settings['google_ads_id'] }}"></script>
        <script>
            window.dataLayer = window.dataLayer || [];

            function gtag() {
                dataLayer.push(arguments);
            }
            gtag('js', new Date());
            gtag('config', '{{ $settings['google_ads_id'] }}');
        </script>
    @endif

    @stack('styles')
    <style>
        :root {
            --primary-color1: {{ $settings['primary_color'] ?? '#BF2629' }} !important;
            --black-color: {{ $settings['secondary_color'] ?? '#717171' }} !important;
            --tertiary-color: {{ $settings['tertiary_color'] ?? '#FFFFFF' }} !important;
        }

        /* Cart Icon Styles */
        .cart-icon-container {
            padding: 0 10px;
        }

        .cart-icon-link {
            color: #333;
            text-decoration: none;
            transition: color 0.3s ease;
            display: flex;
            align-items: center;
            position: relative;
        }

        .cart-icon-link:hover {
            color: var(--primary-color1);
        }

        .cart-badge {
            top: -8px;
            right: -8px;
            background: var(--primary-color1);
            color: white;
            border-radius: 50%;
            width: 18px;
            height: 18px;
            font-size: 11px;
            font-weight: bold;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid white;
            animation: cartPulse 0.3s ease;
        }

        @keyframes cartPulse {
            0% {
                transform: scale(0.8);
            }

            50% {
                transform: scale(1.2);
            }

            100% {
                transform: scale(1);
            }
        }

        .cart-badge.updated {
            animation: cartPulse 0.5s ease;
        }

        /* Mobile Cart Styles */
        .mobile-cart-area .cart-icon-link {
            color: #333;
            padding: 10px 15px;
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.1);
            transition: all 0.3s ease;
        }

        .mobile-cart-area .cart-icon-link:hover {
            background: rgba(255, 255, 255, 0.2);
            color: var(--primary-color1);
        }

        .mobile-cart-area .cart-badge {
            top: 2px;
            right: 10px;
        }

        @media (max-width: 767px) {
            .cart-icon-container {
                order: 1;
            }
        }
    </style>
</head>

<body class="tt-magic-cursor theme-{{ get_active_theme() }}">
    @if (!empty($settings['google_tag_manager_id']))
        <!-- Google Tag Manager (noscript) -->
        <noscript>
            <iframe src="https://www.googletagmanager.com/ns.html?id={{ $settings['google_tag_manager_id'] }}"
                height="0" width="0" style="display:none;visibility:hidden"></iframe>
        </noscript>
    @endif

    <div id="magic-cursor">
        <div id="ball"></div>
    </div>

    <!-- Back To Top -->
    <div class="progress-wrap">
        <svg class="progress-circle svg-content" width="100%" height="100%" viewBox="-1 -1 102 102">
            <path d="M50,1 a49,49 0 0,1 0,98 a49,49 0 0,1 0,-98" />
        </svg>
        <svg class="arrow" width="22" height="25" viewBox="0 0 24 23" xmlns="http://www.w3.org/2000/svg">
            <path
                d="M0.556131 11.4439L11.8139 0.186067L13.9214 2.29352L13.9422 20.6852L9.70638 20.7061L9.76793 8.22168L3.6064 14.4941L0.556131 11.4439Z" />
            <path d="M23.1276 11.4999L16.0288 4.40105L15.9991 10.4203L20.1031 14.5243L23.1276 11.4999Z" />
        </svg>
    </div>

    @include(theme_partial('header'))

    @yield('content')

    @include(theme_partial('footer'))

    <script src="{{ asset('assets/js/jquery-3.7.1.min.js') }}"></script>
    <script src="{{ asset('assets/js/jquery-ui.js') }}"></script>
    <script src="{{ asset('assets/js/moment.min.js') }}"></script>
    <script src="{{ asset('assets/js/daterangepicker.min.js') }}"></script>
    
    <!-- Litepicker for better date picking with month/year selectors -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/litepicker/dist/css/litepicker.css">
    <script src="https://cdn.jsdelivr.net/npm/litepicker/dist/litepicker.js"></script>
    <link rel="stylesheet" href="{{ assetVersion('assets/css/flatpickr-custom.css') }}">

    <script src="{{ asset('assets/js/bootstrap.min.js') }}"></script>
    <script src="{{ asset('assets/js/popper.min.js') }}"></script>

    <script src="{{ asset('assets/js/swiper-bundle.min.js') }}"></script>
    <script src="{{ asset('assets/js/slick.js') }}"></script>

    <script src="{{ asset('assets/js/waypoints.min.js') }}"></script>

    <script src="{{ asset('assets/js/jquery.counterup.min.js') }}"></script>

    <script src="{{ asset('assets/js/wow.min.js') }}"></script>
    <!-- Nice Select JS -->
    <script src="{{ asset('assets/js/jquery.nice-select.min.js') }}"></script>
    <script src="{{ asset('assets/js/gsap.min.js') }}"></script>
    <script src="{{ asset('assets/js/ScrollTrigger.min.js') }}"></script>
    <script src="{{ asset('assets/js/jquery.fancybox.min.js') }}"></script>

    <script src="{{ asset('assets/js/select-dropdown.js') }}"></script>
    <script src="{{ assetVersion('assets/js/custom.js') }}"></script>
    <script src="{{ assetVersion('assets/js/booking-form.js') }}"></script>
    <script src="{{ assetVersion('assets/js/package-selector.js') }}"></script>

    <!-- Currency Switching JavaScript -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Handle currency switching
            document.querySelectorAll('.currency-option').forEach(function(link) {
                link.addEventListener('click', function(e) {
                    e.preventDefault();

                    const currencyCode = this.getAttribute('data-currency');
                    if (!currencyCode) return;

                    // Show loading state
                    const originalText = this.innerHTML;
                    this.innerHTML =
                        '<span class="spinner-border spinner-border-sm me-2"></span>Loading...';

                    // Make AJAX request to switch currency
                    fetch('{{ route('currency.switch') }}', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                                'X-CSRF-TOKEN': document.querySelector(
                                    'meta[name="csrf-token"]').getAttribute('content'),
                                'Accept': 'application/json'
                            },
                            body: 'currency=' + encodeURIComponent(currencyCode)
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                // Reload page to show new prices
                                window.location.reload();
                            } else {
                                console.error('Currency switch failed:', data.message);
                                this.innerHTML = originalText;
                                alert('Failed to switch currency. Please try again.');
                            }
                        })
                        .catch(error => {
                            console.error('Error switching currency:', error);
                            this.innerHTML = originalText;
                            alert(
                                'An error occurred while switching currency. Please try again.'
                            );
                        });
                });
            });

            // Cart Icon Update Functionality
            updateCartIcon();

            // Listen for cart update events instead of polling
            window.addEventListener('cartUpdated', function() {
                updateCartIcon();
            });

            // Update cart on page focus (in case cart was updated in another tab)
            window.addEventListener('focus', function() {
                updateCartIcon();
            });
        });

        function updateCartIcon() {
            const cartBadge = document.getElementById('cartBadge');
            if (!cartBadge) return;

            // Make AJAX request to get cart count
            fetch('{{ route('cart.get') }}', {
                    method: 'GET',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                    }
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const itemCount = data.count || 0;
                        updateCartBadge(itemCount);
                    }
                })
                .catch(error => {
                    console.warn('Failed to update cart icon:', error);
                });
        }

        function updateCartBadge(count) {
            const cartBadge = document.getElementById('cartBadge');
            const mobileCartBadge = document.getElementById('mobileCartBadge');

            // Update desktop cart badge
            if (cartBadge) {
                const currentCount = parseInt(cartBadge.textContent) || 0;

                if (count > 0) {
                    cartBadge.textContent = count;
                    cartBadge.style.display = 'flex';

                    // Add pulse animation if count changed
                    if (count !== currentCount) {
                        cartBadge.classList.add('updated');
                        setTimeout(() => {
                            cartBadge.classList.remove('updated');
                        }, 500);
                    }
                } else {
                    cartBadge.style.display = 'none';
                }
            }

            // Update mobile cart badge
            if (mobileCartBadge) {
                const currentMobileCount = parseInt(mobileCartBadge.textContent) || 0;

                if (count > 0) {
                    mobileCartBadge.textContent = count;
                    mobileCartBadge.style.display = 'flex';

                    // Add pulse animation if count changed
                    if (count !== currentMobileCount) {
                        mobileCartBadge.classList.add('updated');
                        setTimeout(() => {
                            mobileCartBadge.classList.remove('updated');
                        }, 500);
                    }
                } else {
                    mobileCartBadge.style.display = 'none';
                }
            }
        }

        // Global function to trigger cart icon update (kept for backward compatibility)
        window.refreshCartIcon = updateCartIcon;

        // Global notification function
        window.showSuccessNotification = function(message, duration = 3000) {
            // Create notification if it doesn't exist
            let notification = document.getElementById('cart-notification');
            if (!notification) {
                notification = document.createElement('div');
                notification.id = 'cart-notification';
                notification.style.cssText = `
                    position: fixed;
                    top: 20px;
                    right: 20px;
                    background: #28a745;
                    color: white;
                    padding: 15px 20px;
                    border-radius: 5px;
                    z-index: 9999;
                    box-shadow: 0 2px 10px rgba(0,0,0,0.2);
                    transform: translateX(400px);
                    transition: transform 0.3s ease;
                `;
                document.body.appendChild(notification);
            }

            notification.textContent = message;
            notification.style.transform = 'translateX(0)';

            setTimeout(() => {
                notification.style.transform = 'translateX(400px)';
            }, duration);
        };
    </script>

    <!-- AOS Animation JS -->
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    <script>
        // Initialize AOS
        AOS.init({
            duration: 1000,
            once: true,
            offset: 100
        });
    </script>

    <!-- Popup Display Engine JS -->
    <script src="{{ assetVersion('assets/js/popup-display.js') }}"></script>
    <script type="text/javascript">
        (function(c, l, a, r, i, t, y) {
            c[a] = c[a] || function() {
                (c[a].q = c[a].q || []).push(arguments)
            };
            t = l.createElement(r);
            t.async = 1;
            t.src = "https://www.clarity.ms/tag/" + i;
            y = l.getElementsByTagName(r)[0];
            y.parentNode.insertBefore(t, y);
        })(window, document, "clarity", "script", "urik1btltn");
    </script>
    @stack('scripts')

    @php($floatingWhatsappNumber = preg_replace('/[^0-9]/', '', $settings['company_whatsapp'] ?? $settings['footer_whatsapp_number'] ?? $settings['company_phone'] ?? ''))
    @if (!empty($floatingWhatsappNumber))
    <!-- WhatsApp Floating Button -->
    <a href="https://wa.me/{{ $floatingWhatsappNumber }}" target="_blank" rel="noopener noreferrer" class="whatsapp-float" aria-label="Chat on WhatsApp">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="28" height="28">
            <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
        </svg>
    </a>
    <style>
        .whatsapp-float {
            position: fixed;
            bottom: 30px;
            right: 30px;
            z-index: 9999;
            background: #25D366;
            color: #fff;
            width: 56px;
            height: 56px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 16px rgba(37,211,102,0.45);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            text-decoration: none;
        }
        .whatsapp-float:hover {
            transform: scale(1.1);
            box-shadow: 0 6px 20px rgba(37,211,102,0.6);
            color: #fff;
        }
        @media (max-width: 767px) {
            .whatsapp-float {
                bottom: 20px;
                right: 20px;
                width: 48px;
                height: 48px;
            }
        }
    </style>
    @endif
</body>

</html>
