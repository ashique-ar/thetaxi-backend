{{-- Theme-02 Footer - Tailwind CSS - Dark modern footer --}}

<!-- Footer Section Start -->
<footer class="t2-footer footer-section two">
    <div class="container">
        {{-- Newsletter Section --}}
        @if(($settings['theme02_footer_newsletter_enabled'] ?? false) || ($settings['footer_newsletter_enabled'] ?? false))
        <div class="newsletter-section mb-5">
            <div class="row align-items-center">
                <div class="col-lg-6">
                    <div class="newsletter-content">
                        <h4>{{ $settings['theme02_newsletter_heading'] ?? $settings['footer_newsletter_heading'] ?? 'Subscribe to Our Newsletter' }}</h4>
                        <p>{{ $settings['theme02_newsletter_subheading'] ?? $settings['footer_newsletter_subheading'] ?? 'Get the latest updates and offers.' }}</p>
                    </div>
                </div>
                <div class="col-lg-6">
                    <form class="newsletter-form" action="{{ $settings['footer_newsletter_action'] ?? '#' }}" method="POST">
                        @csrf
                        <div class="input-group">
                            <input type="email" name="email" class="form-control" placeholder="{{ $settings['footer_newsletter_placeholder'] ?? 'Enter your email' }}" required>
                            <button type="submit" class="btn btn-primary">{{ $settings['footer_newsletter_button'] ?? 'Subscribe' }}</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        @endif

        <div class="footer-menu-wrap">
            <div class="row gy-md-4 gy-5">
                {{-- Logo and Company Info --}}
                <div class="col-lg-3 col-md-4 col-sm-6">
                    <div class="footer-logo-and-addition-info">
                        <a href="{{ route('home') }}" class="footer-logo">
                            <img src="{{ s3_asset($settings['logo_footer'] ?? $settings['logo_header'] ?? 'assets/img/header-logo.png') }}"
                                 alt="{{ $settings['logo_footer_alt'] ?? $settings['site_name'] ?? $settings['brand_name'] ?? 'Company' }}">
                        </a>
                        <div class="address-area">
                            <span>{{ $settings['footer_company_tagline'] ?? ($settings['site_tagline'] ?? $settings['brand_tagline'] ?? 'Professional Services') }}</span>
                            <a href="#">{{ $settings['company_address'] ?? '123 Transport Avenue, Suite 100, Your City, State 12345, Country' }}</a>
                        </div>
                        <ul class="social-list">
                            @if($settings['social_facebook'] ?? null)<li><a href="{{ $settings['social_facebook'] }}"><i class="bx bxl-facebook"></i></a></li>@endif
                            @if($settings['social_linkedin'] ?? null)<li><a href="{{ $settings['social_linkedin'] }}"><i class="bx bxl-linkedin"></i></a></li>@endif
                            @if($settings['social_youtube'] ?? null)<li><a href="{{ $settings['social_youtube'] }}"><i class="bx bxl-youtube"></i></a></li>@endif
                            @if($settings['social_instagram'] ?? null)<li><a href="{{ $settings['social_instagram'] }}"><i class="bx bxl-instagram-alt"></i></a></li>@endif
                            @if($settings['social_twitter'] ?? null)<li><a href="{{ $settings['social_twitter'] }}"><i class="bx bxl-twitter"></i></a></li>@endif
                            @if($settings['social_tiktok'] ?? null)<li><a href="{{ $settings['social_tiktok'] }}"><i class="bx bxl-tiktok"></i></a></li>@endif
                            @if(!($settings['social_facebook'] ?? null) && !($settings['social_linkedin'] ?? null) && !($settings['social_youtube'] ?? null) && !($settings['social_instagram'] ?? null))
                            <li><a href="https://www.facebook.com/"><i class="bx bxl-facebook"></i></a></li>
                            <li><a href="https://www.linkedin.com/"><i class="bx bxl-linkedin"></i></a></li>
                            <li><a href="https://www.youtube.com/"><i class="bx bxl-youtube"></i></a></li>
                            <li><a href="https://www.instagram.com/"><i class="bx bxl-instagram-alt"></i></a></li>
                            @endif
                        </ul>
                    </div>
                </div>

                {{-- Services Column --}}
                <div class="col-lg-3 col-md-4 col-sm-6 d-flex justify-content-md-end">
                    <div class="footer-widget">
                        <div class="widget-title">
                            <h5>{{ $settings['footer_services_title'] ?? 'Our Services' }}</h5>
                        </div>
                        @php
                            $servicesLinks = [];
                            $i = 1;
                            while(isset($settings["footer_services_link_{$i}_text"]) && $settings["footer_services_link_{$i}_text"]) {
                                $servicesLinks[] = ['text' => $settings["footer_services_link_{$i}_text"], 'url' => $settings["footer_services_link_{$i}_url"] ?? '#'];
                                $i++;
                            }
                        @endphp
                        @if(!empty($servicesLinks))
                            <ul class="widget-list">
                                @foreach($servicesLinks as $link)
                                    <li><a href="{{ $link['url'] }}">{{ $link['text'] }}</a></li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>

                {{-- Routes Column --}}
                <div class="col-lg-3 col-md-4 col-sm-6 d-flex justify-content-md-end">
                    <div class="footer-widget">
                        <div class="widget-title">
                            <h5>{{ $settings['footer_routes_title'] ?? 'Popular Routes' }}</h5>
                        </div>
                        @php
                            $routesLinks = [];
                            $i = 1;
                            while(isset($settings["footer_routes_link_{$i}_text"]) && $settings["footer_routes_link_{$i}_text"]) {
                                $routesLinks[] = ['text' => $settings["footer_routes_link_{$i}_text"], 'url' => $settings["footer_routes_link_{$i}_url"] ?? '#'];
                                $i++;
                            }
                        @endphp
                        @if(!empty($routesLinks))
                            <ul class="widget-list">
                                @foreach($routesLinks as $link)
                                    <li><a href="{{ $link['url'] }}">{{ $link['text'] }}</a></li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>

                {{-- Contact Column --}}
                <div class="col-lg-3 col-sm-6 d-flex justify-content-lg-end">
                    <div class="footer-widget">
                        <div class="widget-title">
                            <h5>{{ $settings['footer_contact_title'] ?? $settings['footer_support_title'] ?? 'Contact Info' }}</h5>
                        </div>
                        <ul class="contact-list">
                            <li class="single-contact">
                                <div class="icon">
                                    <img src="{{ asset('assets/img/home3/icon/whatsapp-icon.svg') }}" alt="" onerror="this.src='{{ asset('assets/img/home1/icon/whatsapp-icon2.svg') }}'">
                                </div>
                                <div class="content">
                                    <span>{{ $settings['footer_whatsapp_label'] ?? 'WhatsApp' }}</span>
                                    <a href="https://wa.me/{{ preg_replace('/[^0-9+]/', '', $settings['company_whatsapp'] ?? $settings['company_phone'] ?? '') }}">{{ $settings['company_whatsapp'] ?? $settings['company_phone'] ?? 'Call us' }}</a>
                                </div>
                            </li>
                            <li class="single-contact">
                                <div class="icon">
                                    <img src="{{ asset('assets/img/home3/icon/mail-icon.svg') }}" alt="" onerror="this.src='{{ asset('assets/img/home1/icon/mail-icon2.svg') }}'">
                                </div>
                                <div class="content">
                                    <span>{{ $settings['footer_email_label'] ?? 'Mail Us' }}</span>
                                    <a href="mailto:{{ $settings['company_email'] ?? '' }}">{{ $settings['company_email'] ?? 'Email us' }}</a>
                                </div>
                            </li>
                            <li class="single-contact">
                                <div class="icon">
                                    <img src="{{ asset('assets/img/home1/icon/call-icon.svg') }}" alt="">
                                </div>
                                <div class="content">
                                    <span>{{ $settings['footer_phone_label'] ?? $settings['footer_inquiry_heading'] ?? 'More Inquiry' }}</span>
                                    <a href="tel:{{ preg_replace('/[^0-9+]/', '', $settings['company_phone'] ?? '') }}">{{ $settings['company_phone'] ?? 'Call us' }}</a>
                                </div>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Footer Bottom --}}
    <div class="footer-bottom">
        <div class="container">
            <div class="copyright-and-payment-method-area">
                <p>{{ $settings['footer_copyright_text'] ?? 'Copyright ' . date('Y')  }} <a href="{{ route('home') }}">{{ $settings['site_name'] ?? $settings['brand_name'] ?? 'Company' }}</a> | All Rights Reserved.</p>
                @if($settings['footer_payment_methods_enabled'] ?? false)
                <div class="payment-method-area">
                    <span>{{ $settings['footer_payment_methods_label'] ?? 'Accepted Payment Methods :' }}</span>
                    <ul>
                        @if($settings['footer_payment_mastercard'] ?? true)<li><img src="{{ asset('assets/img/home1/icon/mastar-card-icon.svg') }}" alt="Mastercard"></li>@endif
                        @if($settings['footer_payment_visa'] ?? true)<li><img src="{{ asset('assets/img/home1/icon/visa-icon.svg') }}" alt="Visa"></li>@endif
                        @if($settings['footer_payment_paypal'] ?? false)<li><img src="{{ asset('assets/img/home1/icon/paypal-icon.svg') }}" alt="PayPal"></li>@endif
                        @if($settings['footer_payment_gpay'] ?? false)<li><img src="{{ asset('assets/img/home1/icon/gpay-icon.svg') }}" alt="Google Pay"></li>@endif
                    </ul>
                </div>
                @endif
            </div>
        </div>
    </div>
</footer>
<!-- Footer Section End -->


