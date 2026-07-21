@extends('layouts.app')

@section('title', $settings['faq_page_title'] ?? 'FAQ - Frequently Asked Questions')

@section('content')
    <div class="breadcrumb-section"
        style="background-image:linear-gradient(rgba(0, 0, 0, 0.3), rgba(0, 0, 0, 0.3)), url({{ s3_asset($settings['faq_breadcrumb_image'] ?? 'assets/img/innerpages/breadcrumb-bg.jpg') }});">
        <div class="container">
            <div class="banner-content">
                <h1>{{ $settings['faq_hero_heading'] ?? 'Frequently Asked Questions' }}</h1>
                <ul class="breadcrumb-list">
                    <li><a href="{{ route('home') }}">Home</a></li>
                    <li>{{ $settings['faq_hero_subheading'] ?? 'FAQ' }}</li>
                </ul>
            </div>
        </div>
    </div>

    <div class="faq-page pt-100 mb-100">
        <div class="container">
            <div class="row justify-content-center mb-50 wow animate fadeInDown" data-wow-delay="200ms"
                data-wow-duration="1500ms">
                <div class="col-xl-7 col-lg-9">
                    <div class="section-title text-center">
                        <h2>{{ $category->name ?? ($settings['faq_section_title'] ?? 'General Questions') }}</h2>
                        @if ((isset($category) && !empty($category->description)) || !empty($settings['faq_section_description']))
                            <p>{{ isset($category) && !empty($category->description) ? $category->description : $settings['faq_section_description'] }}</p>
                        @endif
                    </div>
                </div>
            </div>

            @if ($settings['faq_show_search'] ?? true)
                <div class="row justify-content-center mb-40">
                    <div class="col-xl-8 col-lg-10">
                        <div class="faq-search-wrap">
                            <form method="GET" action="{{ route('faq') }}" class="faq-search-form">
                                <div class="row g-3 align-items-end">
                                    <div class="col-md-6">
                                        <div class="form-inner">
                                            <label for="faq-search">Search FAQs</label>
                                            <input id="faq-search" type="search" name="search"
                                                value="{{ $search ?? '' }}"
                                                placeholder="{{ $settings['faq_search_placeholder'] ?? 'Search questions and answers...' }}">
                                        </div>
                                    </div>
                                    @if ($settings['faq_show_categories'] ?? true)
                                        <div class="col-md-4">
                                            <div class="form-inner">
                                                <label for="faq-category">Category</label>
                                                <select id="faq-category" name="category" onchange="this.form.submit()">
                                                    <option value="">
                                                        {{ $settings['faq_all_categories_text'] ?? 'All Categories' }}
                                                    </option>
                                                    @foreach ($categories as $cat)
                                                        <option value="{{ $cat->id }}"
                                                            @selected((string) ($categoryId ?? '') === (string) $cat->id)>
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

            @if ($featuredFaqs->isNotEmpty())
                <section class="row justify-content-center mb-50" aria-labelledby="featured-faq-title">
                    <div class="col-xl-8 col-lg-10">
                        <div class="section-title text-center mb-30">
                            <h2 id="featured-faq-title">{{ $settings['faq_featured_title'] ?? 'Featured Questions' }}</h2>
                            @if (!empty($settings['faq_featured_subtitle']))
                                <p>{{ $settings['faq_featured_subtitle'] }}</p>
                            @endif
                        </div>
                        @include('partials.faq-accordion', [
                            'faqItems' => $featuredFaqs,
                            'accordionId' => 'featuredFaqAccordion',
                            'autoExpand' => $settings['faq_auto_expand'] ?? true,
                        ])
                    </div>
                </section>
            @endif

            <section class="row justify-content-center" aria-label="Frequently asked questions">
                <div class="col-xl-8 col-lg-10">
                    @if ($faqs->isNotEmpty())
                        @include('partials.faq-accordion', [
                            'faqItems' => $faqs,
                            'accordionId' => 'faqAccordion',
                            'autoExpand' => $settings['faq_auto_expand'] ?? true,
                        ])
                    @else
                        <div class="faq-empty-state text-center" role="status">
                            <p>{{ $settings['faq_no_results_text'] ?? 'No frequently asked questions found.' }}</p>
                        </div>
                    @endif

                    @if ($faqs->hasPages())
                        <div class="faq-pagination mt-40">
                            {{ $faqs->links() }}
                        </div>
                    @endif
                </div>
            </section>
        </div>
    </div>

    @if (!empty($settings['faq_page_banner_image']))
        <div class="faq-page-banner mb-100"
            style="background-image: url({{ s3_asset($settings['faq_page_banner_image']) }});">
        </div>
    @endif
@endsection
