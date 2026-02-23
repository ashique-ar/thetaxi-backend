@extends('layouts.app')

@s <div class="section-title text-center">
    <h2>{{ $settings['faq_section_title'] ?? 'General Questions' }}</h2>
    <p>{{ $settings['faq_section_description'] ?? "We're committed to offering more than just products—we provide exceptional experiences." }}
    </p>
</div>on('title', $settings['faq_page_title'] ?? 'FAQ - Frequently Asked Questions')

@section('content')

    <!-- Start Breadcrumb section -->
    <div class="breadcrumb-section"
        style="background-image:linear-gradient(rgba(0, 0, 0, 0.3), rgba(0, 0, 0, 0.3)), url({{ s3_asset($settings['faq_breadcrumb_image'] ?? 'assets/img/innerpages/breadcrumb-bg.jpg') }});">
        <div class="container">
            <div class="banner-content">
                <h1>{{ $settings['faq_hero_heading'] ?? 'Ask & Question' }}</h1>
                <ul class="breadcrumb-list">
                    <li><a href="{{ route('home') }}">Home</a></li>
                    <li>{{ $settings['faq_hero_subheading'] ?? 'FAQ' }}</li>
                </ul>
            </div>
        </div>
    </div>
    <!-- End Breadcrumb section -->

    <!-- faq Page Start-->
    <div class="faq-page pt-100 mb-100">
        <div class="container">
            <div class="row justify-content-center mb-50 wow animate fadeInDown" data-wow-delay="200ms"
                data-wow-duration="1500ms">
                <div class="col-xl-6 col-lg-8">
                    <div class="section-title text-center">
                        <h2>{{ $settings['faq_section_title'] ?? 'General Questions' }}</h2>
                        <p>{{ $settings['faq_section_description'] ?? "We're committed to offering more than just products—we provide exceptional experiences." }}
                        </p>
                    </div>
                </div>
            </div>

            @if ($settings['faq_show_search'] ?? true)
                <!-- FAQ Search and Filter Section -->
                <div class="row justify-content-center mb-40">
                    <div class="col-xl-8 col-lg-10">
                        <div class="faq-search-wrap">
                            <form method="GET" action="{{ route('faq.index') }}" class="faq-search-form">
                                <div class="row g-3 align-items-end">
                                    <div class="col-md-6">
                                        <div class="form-inner">
                                            <label>Search FAQs</label>
                                            <input type="text" name="search" value="{{ $search ?? '' }}"
                                                placeholder="{{ $settings['faq_search_placeholder'] ?? 'Search questions and answers...' }}">
                                        </div>
                                    </div>
                                    @if ($settings['faq_show_categories'] ?? true)
                                        <div class="col-md-4">
                                            <div class="form-inner">
                                                <label>Category</label>
                                                <select name="category" onchange="this.form.submit()">
                                                    <option value="">
                                                        {{ $settings['faq_all_categories_text'] ?? 'All Categories' }}
                                                    </option>
                                                    @foreach ($categories as $cat)
                                                        <option value="{{ $cat->id }}"
                                                            {{ ($categoryId ?? '') == $cat->id ? 'selected' : '' }}>
                                                            {{ $cat->name }}
                                                        </option>
                                                    @endforeach
                                                </select>
                                            </div>
                                        </div>
                                    @endif
                                    <div class="col-md-2">
                                        <button type="submit" class="primary-btn1">Search</button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            @endif

            <div class="row justify-content-center">
                <div class="col-xl-8 col-lg-10">
                    <div class="faq-wrap">
                        <div class="accordion accordion-flush" id="accordionFlushExample">
                            <div class="accordion-item wow animate fadeInDown" data-wow-delay="200ms"
                                data-wow-duration="1500ms">
                                <h5 class="accordion-header" id="flush-headingOne">
                                    <button class="accordion-button" type="button" data-bs-toggle="collapse"
                                        data-bs-target="#flush-collapseOne" aria-expanded="false"
                                        aria-controls="flush-collapseOne">What Services Does Your Travel Agency
                                        Provide?</button>
                                </h5>
                                <div id="flush-collapseOne" class="accordion-collapse collapse show"
                                    aria-labelledby="flush-headingOne" data-bs-parent="#accordionFlushExample">
                                    <div class="accordion-body">
                                        A travel agency typically provides a wide range of services to ensure a smooth and
                                        enjoyable travel experience. As like- <span>Hotel booking, Flight Booking, Visa &
                                            Customized Travel Pakcge etc.</span>
                                    </div>
                                </div>
                            </div>
                            <div class="accordion-item wow animate fadeInDown" data-wow-delay="400ms"
                                data-wow-duration="1500ms">
                                <h5 class="accordion-header" id="flush-headingTwo">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse"
                                        data-bs-target="#flush-collapseTwo" aria-expanded="false"
                                        aria-controls="flush-collapseTwo">Do You Offer Customized Travel Packages?</button>
                                </h5>
                                <div id="flush-collapseTwo" class="accordion-collapse collapse"
                                    aria-labelledby="flush-headingTwo" data-bs-parent="#accordionFlushExample">
                                    <div class="accordion-body">
                                        Absolutely! We offer fully customized travel packages based on your interests,
                                        budget, and schedule. Whether you're planning <span>a solo adventure, a family
                                            vacation, a romantic getaway, or a group tour</span>, our team will tailor every
                                        detail to create a personalized travel experience just for you.
                                    </div>
                                </div>
                            </div>
                            <div class="accordion-item wow animate fadeInDown" data-wow-delay="600ms"
                                data-wow-duration="1500ms">
                                <h5 class="accordion-header" id="flush-headingThree">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse"
                                        data-bs-target="#flush-collapseThree" aria-expanded="false"
                                        aria-controls="flush-collapseThree">How do I book a tour or vacation
                                        package?</button>
                                </h5>
                                <div id="flush-collapseThree" class="accordion-collapse collapse"
                                    aria-labelledby="flush-headingThree" data-bs-parent="#accordionFlushExample">
                                    <div class="accordion-body">
                                        Booking a tour or vacation package is easy! Simply browse our website to explore
                                        destinations and packages. Once you've found the perfect option, click “Book Now”
                                        and follow the steps to complete your reservation. If you need help, our travel
                                        experts are just a call or message away to assist you with the booking process.
                                    </div>
                                </div>
                            </div>
                            <div class="accordion-item wow animate fadeInDown" data-wow-delay="800ms"
                                data-wow-duration="1500ms">
                                <h5 class="accordion-header" id="flush-headingFour">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse"
                                        data-bs-target="#flush-collapseFour" aria-expanded="false"
                                        aria-controls="flush-collapseFour">Do You Provide Visa Assistance?</button>
                                </h5>
                                <div id="flush-collapseFour" class="accordion-collapse collapse"
                                    aria-labelledby="flush-headingFour" data-bs-parent="#accordionFlushExample">
                                    <div class="accordion-body">
                                        Yes, we do! Our team offers complete <span>visa assistance services</span> to help
                                        you navigate the application process smoothly. From providing guidance on required
                                        documents to scheduling appointments and submitting applications, we're here to
                                        support you every step of the way.
                                    </div>
                                </div>
                            </div>
                            <div class="accordion-item wow animate fadeInDown" data-wow-delay="800ms"
                                data-wow-duration="1500ms">
                                <h5 class="accordion-header" id="flush-headingFive">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse"
                                        data-bs-target="#flush-collapseFive" aria-expanded="false"
                                        aria-controls="flush-collapseFive">Do you provide travel insurance
                                        options?</button>
                                </h5>
                                <div id="flush-collapseFive" class="accordion-collapse collapse"
                                    aria-labelledby="flush-headingFive" data-bs-parent="#accordionFlushExample">
                                    <div class="accordion-body">
                                        Yes, we do! We offer travel insurance options to ensure peace of mind during your
                                        trip. Our insurance plans cover trip cancellations, medical emergencies, lost
                                        luggage, and more. You can choose to add travel insurance during the booking process
                                        or contact our team for personalized assistance.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!--faq Page End-->

    <!--faq Page Banner Start-->
    <div class="faq-page-banner mb-100"
        style="background-image: url({{ s3_asset($settings['faq_page_banner_image'] ?? 'assets/img/home7/home7-testimonial-bg.jpg') }});">
    </div>
    <!--faq Page Banner End-->

    <!-- faq Page Start-->
    <div class="faq-page mb-100">
        <div class="container">
            <div class="row justify-content-center mb-50 wow animate fadeInDown" data-wow-delay="200ms"
                data-wow-duration="1500ms">
                <div class="col-xl-6 col-lg-8">
                    <div class="section-title text-center">
                        <h2>Visa & Documentation</h2>
                        <p>We’re committed to offering more than just products—we provide exceptional experiences.</p>
                    </div>
                </div>
            </div>
            <div class="row justify-content-center">
                <div class="col-xl-8 col-lg-10">
                    <div class="faq-wrap">
                        <div class="accordion accordion-flush" id="accordionFlushExample2">
                            <div class="accordion-item wow animate fadeInDown" data-wow-delay="200ms"
                                data-wow-duration="1500ms">
                                <h5 class="accordion-header" id="flush-visa-headingOne">
                                    <button class="accordion-button" type="button" data-bs-toggle="collapse"
                                        data-bs-target="#flush-visa-collapseOne" aria-expanded="false"
                                        aria-controls="flush-visa-collapseOne">Do I need a visa for my
                                        destination?</button>
                                </h5>
                                <div id="flush-visa-collapseOne" class="accordion-collapse collapse show"
                                    aria-labelledby="flush-visa-headingOne" data-bs-parent="#accordionFlushExample2">
                                    <div class="accordion-body">
                                        Visa requirements depend on your <span>nationality, destination, purpose, and
                                            duration of travel</span>. Some countries offer <span>visa-free entry</span>,
                                        while others require <span>pre-approved visas</span> or provide visa-on-arrival.
                                    </div>
                                </div>
                            </div>
                            <div class="accordion-item wow animate fadeInDown" data-wow-delay="400ms"
                                data-wow-duration="1500ms">
                                <h5 class="accordion-header" id="flush-visa-headingTwo">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse"
                                        data-bs-target="#flush-visa-collapseTwo" aria-expanded="false"
                                        aria-controls="flush-visa-collapseTwo">What documents are required for visa
                                        processing?</button>
                                </h5>
                                <div id="flush-visa-collapseTwo" class="accordion-collapse collapse"
                                    aria-labelledby="flush-visa-headingTwo" data-bs-parent="#accordionFlushExample2">
                                    <div class="accordion-body">
                                        Required documents may vary by destination, but generally include a <span>valid
                                            passport, recent photographs, completed visa application form, travel itinerary,
                                            proof of accommodation, financial statements</span>, and sometimes a
                                        <span>letter of invitation or employment letter</span>. We recommend checking with
                                        the specific embassy or consulate for the most accurate and updated list.
                                    </div>
                                </div>
                            </div>
                            <div class="accordion-item wow animate fadeInDown" data-wow-delay="600ms"
                                data-wow-duration="1500ms">
                                <h5 class="accordion-header" id="flush-visa-headingThree">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse"
                                        data-bs-target="#flush-visa-collapseThree" aria-expanded="false"
                                        aria-controls="flush-visa-collapseThree">How long does visa approval take?</button>
                                </h5>
                                <div id="flush-visa-collapseThree" class="accordion-collapse collapse"
                                    aria-labelledby="flush-visa-headingThree" data-bs-parent="#accordionFlushExample2">
                                    <div class="accordion-body">
                                        Visa processing times can vary greatly depending on the <span>country, type of visa,
                                            your nationality, and current application volumes</span>. It may take anywhere
                                        from a <span>few days to several weeks</span>. We recommend applying well in advance
                                        and checking the official embassy or consulate website for estimated processing
                                        times.
                                    </div>
                                </div>
                            </div>
                            <div class="accordion-item wow animate fadeInDown" data-wow-delay="800ms"
                                data-wow-duration="1500ms">
                                <h5 class="accordion-header" id="flush-visa-headingFour">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse"
                                        data-bs-target="#flush-visa-collapseFour" aria-expanded="false"
                                        aria-controls="flush-visa-collapseFour">Can you assist with passport
                                        renewals?</button>
                                </h5>
                                <div id="flush-visa-collapseFour" class="accordion-collapse collapse"
                                    aria-labelledby="flush-visa-headingFour" data-bs-parent="#accordionFlushExample2">
                                    <div class="accordion-body">
                                        Yes, we can guide you through the <span>passport renewal process</span>. While we
                                        don't issue passports ourselves, we provide <span>information, documentation
                                            checklists, and support</span> to help ensure your application is complete and
                                        correctly submitted to the relevant authority.
                                    </div>
                                </div>
                            </div>
                            <div class="accordion-item wow animate fadeInDown" data-wow-delay="800ms"
                                data-wow-duration="1500ms">
                                <h5 class="accordion-header" id="flush-visa-headingFive">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse"
                                        data-bs-target="#flush-visa-collapseFive" aria-expanded="false"
                                        aria-controls="flush-visa-collapseFive">What happens if my visa is
                                        rejected?</button>
                                </h5>
                                <div id="flush-visa-collapseFive" class="accordion-collapse collapse"
                                    aria-labelledby="flush-visa-headingFive" data-bs-parent="#accordionFlushExample2">
                                    <div class="accordion-body">
                                        If your visa is rejected, we will help you <span>understand the reason for the
                                            denial</span> and guide you on the <span>next steps</span>. This may include
                                        correcting documentation, providing additional information, or reapplying. Our team
                                        is here to support you through the process.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!--faq Page End-->
@endsection
