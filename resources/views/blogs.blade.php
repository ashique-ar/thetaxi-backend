@extends('layouts.app')

@section('title', 'Your Cart - TheTaxi')

@section('content')
    <!-- Start Breadcrumb section -->
    <div class="breadcrumb-section"
        style="background-image:linear-gradient(rgba(0, 0, 0, 0.3), rgba(0, 0, 0, 0.3)), url(assets/img/innerpages/breadcrumb-bg.jpg);">
        <div class="container">
            <div class="banner-content">
                <h1>Travel Inspiration-02</h1>
                <ul class="breadcrumb-list">
                    <li><a href="index.html">Home</a></li>
                    <li>Travel Inspiration-02</li>
                </ul>
            </div>
        </div>
    </div>
    <!-- End Breadcrumb section -->

    <!-- Travel Inspiration Page Start-->
    <div class="travel-inspiration-page pt-100 mb-100">
        <div class="container">
            <div class="row gy-md-5 gy-4 mb-60">
                <div class="col-lg-4 col-md-6 wow animate fadeInDown" data-wow-delay="200ms" data-wow-duration="1500ms">
                    <div class="blog-card2 two">
                        <div class="blog-img-wrap">
                            <a href="travel-inspiration-details.html" class="blog-img">
                                <img src="assets/img/home3/blog-img1.jpg" alt="">
                            </a>
                            <a href="travel-inspiration-01.html" class="location">
                                <svg width="14" height="14" viewBox="0 0 14 14" xmlns="http://www.w3.org/2000/svg">
                                    <path
                                        d="M6.83615 0C3.77766 0 1.28891 2.48879 1.28891 5.54892C1.28891 7.93837 4.6241 11.8351 6.05811 13.3994C6.25669 13.6175 6.54154 13.7411 6.83615 13.7411C7.13076 13.7411 7.41561 13.6175 7.6142 13.3994C9.04821 11.8351 12.3834 7.93833 12.3834 5.54892C12.3834 2.48879 9.89464 0 6.83615 0ZM7.31469 13.1243C7.18936 13.2594 7.02008 13.3342 6.83615 13.3342C6.65222 13.3342 6.48295 13.2594 6.35761 13.1243C4.95614 11.5959 1.69584 7.79515 1.69584 5.54896C1.69584 2.7134 4.00067 0.406933 6.83615 0.406933C9.67164 0.406933 11.9765 2.7134 11.9765 5.54896C11.9765 7.79515 8.71617 11.5959 7.31469 13.1243Z" />
                                    <path
                                        d="M6.83618 8.54529C8.4624 8.54529 9.7807 7.22698 9.7807 5.60077C9.7807 3.97456 8.4624 2.65625 6.83618 2.65625C5.20997 2.65625 3.89166 3.97456 3.89166 5.60077C3.89166 7.22698 5.20997 8.54529 6.83618 8.54529Z" />
                                </svg>
                                Tokyeo, Japan
                            </a>
                        </div>
                        <div class="blog-content">
                            <a href="travel-inspiration-01.html" class="blog-date">24 February, 2025</a>
                            <h4><a href="travel-inspiration-details.html">Nature, Culture & Thrill: Travel Stories That
                                    Inspire.</a></h4>
                            <p>Nature, Culture & Thrill: Travel Stories Inspire is a collection of experiences from
                                explorers.</p>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4 col-md-6 wow animate fadeInDown" data-wow-delay="400ms" data-wow-duration="1500ms">
                    <div class="blog-card2 two">
                        <div class="blog-img-wrap">
                            <a href="travel-inspiration-details.html" class="blog-img">
                                <img src="assets/img/home3/blog-img2.jpg" alt="">
                            </a>
                            <a href="travel-inspiration-01.html" class="location">
                                <svg width="14" height="14" viewBox="0 0 14 14" xmlns="http://www.w3.org/2000/svg">
                                    <path
                                        d="M6.83615 0C3.77766 0 1.28891 2.48879 1.28891 5.54892C1.28891 7.93837 4.6241 11.8351 6.05811 13.3994C6.25669 13.6175 6.54154 13.7411 6.83615 13.7411C7.13076 13.7411 7.41561 13.6175 7.6142 13.3994C9.04821 11.8351 12.3834 7.93833 12.3834 5.54892C12.3834 2.48879 9.89464 0 6.83615 0ZM7.31469 13.1243C7.18936 13.2594 7.02008 13.3342 6.83615 13.3342C6.65222 13.3342 6.48295 13.2594 6.35761 13.1243C4.95614 11.5959 1.69584 7.79515 1.69584 5.54896C1.69584 2.7134 4.00067 0.406933 6.83615 0.406933C9.67164 0.406933 11.9765 2.7134 11.9765 5.54896C11.9765 7.79515 8.71617 11.5959 7.31469 13.1243Z" />
                                    <path
                                        d="M6.83618 8.54529C8.4624 8.54529 9.7807 7.22698 9.7807 5.60077C9.7807 3.97456 8.4624 2.65625 6.83618 2.65625C5.20997 2.65625 3.89166 3.97456 3.89166 5.60077C3.89166 7.22698 5.20997 8.54529 6.83618 8.54529Z" />
                                </svg>
                                Maldives
                            </a>
                        </div>
                        <div class="blog-content">
                            <a href="travel-inspiration-01.html" class="blog-date">28 February, 2025</a>
                            <h4><a href="travel-inspiration-details.html">Dream. Explore. Discover. Where Will You Go
                                    Next?</a></h4>
                            <p>Dream. Explore. Discover. is more than just a travel motto; it’s an invitation to step out.
                            </p>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4 col-md-6 wow animate fadeInDown" data-wow-delay="600ms" data-wow-duration="1500ms">
                    <div class="blog-card2 two">
                        <div class="blog-img-wrap">
                            <a href="travel-inspiration-details.html" class="blog-img">
                                <img src="assets/img/home3/blog-img3.jpg" alt="">
                            </a>
                            <a href="travel-inspiration-01.html" class="location">
                                <svg width="14" height="14" viewBox="0 0 14 14" xmlns="http://www.w3.org/2000/svg">
                                    <path
                                        d="M6.83615 0C3.77766 0 1.28891 2.48879 1.28891 5.54892C1.28891 7.93837 4.6241 11.8351 6.05811 13.3994C6.25669 13.6175 6.54154 13.7411 6.83615 13.7411C7.13076 13.7411 7.41561 13.6175 7.6142 13.3994C9.04821 11.8351 12.3834 7.93833 12.3834 5.54892C12.3834 2.48879 9.89464 0 6.83615 0ZM7.31469 13.1243C7.18936 13.2594 7.02008 13.3342 6.83615 13.3342C6.65222 13.3342 6.48295 13.2594 6.35761 13.1243C4.95614 11.5959 1.69584 7.79515 1.69584 5.54896C1.69584 2.7134 4.00067 0.406933 6.83615 0.406933C9.67164 0.406933 11.9765 2.7134 11.9765 5.54896C11.9765 7.79515 8.71617 11.5959 7.31469 13.1243Z" />
                                    <path
                                        d="M6.83618 8.54529C8.4624 8.54529 9.7807 7.22698 9.7807 5.60077C9.7807 3.97456 8.4624 2.65625 6.83618 2.65625C5.20997 2.65625 3.89166 3.97456 3.89166 5.60077C3.89166 7.22698 5.20997 8.54529 6.83618 8.54529Z" />
                                </svg>
                                Vietnam
                            </a>
                        </div>
                        <div class="blog-content">
                            <a href="travel-inspiration-01.html" class="blog-date">05 March, 2025</a>
                            <h4><a href="travel-inspiration-details.html">Top 10 Beaches to Visit This Summer Season.</a>
                            </h4>
                            <p>Sun, sand, and crystal-clear waters—summer is the perfect time to escape to the world’s.</p>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4 col-md-6 wow animate fadeInDown" data-wow-delay="800ms" data-wow-duration="1500ms">
                    <div class="blog-card2 two">
                        <div class="blog-img-wrap">
                            <a href="travel-inspiration-details.html" class="blog-img">
                                <img src="assets/img/innerpages/blog-img3.jpg" alt="">
                            </a>
                            <a href="travel-inspiration-01.html" class="location">
                                <svg width="14" height="14" viewBox="0 0 14 14" xmlns="http://www.w3.org/2000/svg">
                                    <path
                                        d="M6.83615 0C3.77766 0 1.28891 2.48879 1.28891 5.54892C1.28891 7.93837 4.6241 11.8351 6.05811 13.3994C6.25669 13.6175 6.54154 13.7411 6.83615 13.7411C7.13076 13.7411 7.41561 13.6175 7.6142 13.3994C9.04821 11.8351 12.3834 7.93833 12.3834 5.54892C12.3834 2.48879 9.89464 0 6.83615 0ZM7.31469 13.1243C7.18936 13.2594 7.02008 13.3342 6.83615 13.3342C6.65222 13.3342 6.48295 13.2594 6.35761 13.1243C4.95614 11.5959 1.69584 7.79515 1.69584 5.54896C1.69584 2.7134 4.00067 0.406933 6.83615 0.406933C9.67164 0.406933 11.9765 2.7134 11.9765 5.54896C11.9765 7.79515 8.71617 11.5959 7.31469 13.1243Z" />
                                    <path
                                        d="M6.83618 8.54529C8.4624 8.54529 9.7807 7.22698 9.7807 5.60077C9.7807 3.97456 8.4624 2.65625 6.83618 2.65625C5.20997 2.65625 3.89166 3.97456 3.89166 5.60077C3.89166 7.22698 5.20997 8.54529 6.83618 8.54529Z" />
                                </svg>
                                Bali, Indonesia
                            </a>
                        </div>
                        <div class="blog-content">
                            <a href="travel-inspiration-01.html" class="blog-date">01 April, 2025</a>
                            <h4><a href="travel-inspiration-details.html">Escape to the World’s Most Breathtaking
                                    Islands.</a></h4>
                            <p>Escape to the World’s Most Breathtaking Islands and immerse yourself in paradise.</p>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4 col-md-6 wow animate fadeInDown" data-wow-delay="800ms" data-wow-duration="1500ms">
                    <div class="blog-card2 two">
                        <div class="blog-img-wrap">
                            <a href="travel-inspiration-details.html" class="blog-img">
                                <img src="assets/img/innerpages/blog-img4.jpg" alt="">
                            </a>
                            <a href="travel-inspiration-01.html" class="location">
                                <svg width="14" height="14" viewBox="0 0 14 14" xmlns="http://www.w3.org/2000/svg">
                                    <path
                                        d="M6.83615 0C3.77766 0 1.28891 2.48879 1.28891 5.54892C1.28891 7.93837 4.6241 11.8351 6.05811 13.3994C6.25669 13.6175 6.54154 13.7411 6.83615 13.7411C7.13076 13.7411 7.41561 13.6175 7.6142 13.3994C9.04821 11.8351 12.3834 7.93833 12.3834 5.54892C12.3834 2.48879 9.89464 0 6.83615 0ZM7.31469 13.1243C7.18936 13.2594 7.02008 13.3342 6.83615 13.3342C6.65222 13.3342 6.48295 13.2594 6.35761 13.1243C4.95614 11.5959 1.69584 7.79515 1.69584 5.54896C1.69584 2.7134 4.00067 0.406933 6.83615 0.406933C9.67164 0.406933 11.9765 2.7134 11.9765 5.54896C11.9765 7.79515 8.71617 11.5959 7.31469 13.1243Z" />
                                    <path
                                        d="M6.83618 8.54529C8.4624 8.54529 9.7807 7.22698 9.7807 5.60077C9.7807 3.97456 8.4624 2.65625 6.83618 2.65625C5.20997 2.65625 3.89166 3.97456 3.89166 5.60077C3.89166 7.22698 5.20997 8.54529 6.83618 8.54529Z" />
                                </svg>
                                Vietnam
                            </a>
                        </div>
                        <div class="blog-content">
                            <a href="travel-inspiration-01.html" class="blog-date">24 February, 2025</a>
                            <h4><a href="travel-inspiration-details.html">Dream. Explore. Discover. Where Will You Go
                                    Next?</a></h4>
                            <p>A beach getaway is perfect for those seeking relaxation, adventure, or a romantic retreat.
                            </p>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4 col-md-6 wow animate fadeInDown" data-wow-delay="800ms" data-wow-duration="1500ms">
                    <div class="blog-card2 two">
                        <div class="blog-img-wrap">
                            <a href="travel-inspiration-details.html" class="blog-img">
                                <img src="assets/img/innerpages/blog-img5.jpg" alt="">
                            </a>
                            <a href="travel-inspiration-01.html" class="location">
                                <svg width="14" height="14" viewBox="0 0 14 14"
                                    xmlns="http://www.w3.org/2000/svg">
                                    <path
                                        d="M6.83615 0C3.77766 0 1.28891 2.48879 1.28891 5.54892C1.28891 7.93837 4.6241 11.8351 6.05811 13.3994C6.25669 13.6175 6.54154 13.7411 6.83615 13.7411C7.13076 13.7411 7.41561 13.6175 7.6142 13.3994C9.04821 11.8351 12.3834 7.93833 12.3834 5.54892C12.3834 2.48879 9.89464 0 6.83615 0ZM7.31469 13.1243C7.18936 13.2594 7.02008 13.3342 6.83615 13.3342C6.65222 13.3342 6.48295 13.2594 6.35761 13.1243C4.95614 11.5959 1.69584 7.79515 1.69584 5.54896C1.69584 2.7134 4.00067 0.406933 6.83615 0.406933C9.67164 0.406933 11.9765 2.7134 11.9765 5.54896C11.9765 7.79515 8.71617 11.5959 7.31469 13.1243Z" />
                                    <path
                                        d="M6.83618 8.54529C8.4624 8.54529 9.7807 7.22698 9.7807 5.60077C9.7807 3.97456 8.4624 2.65625 6.83618 2.65625C5.20997 2.65625 3.89166 3.97456 3.89166 5.60077C3.89166 7.22698 5.20997 8.54529 6.83618 8.54529Z" />
                                </svg>
                                Norway
                            </a>
                        </div>
                        <div class="blog-content">
                            <a href="travel-inspiration-01.html" class="blog-date">24 April, 2025</a>
                            <h4><a href="travel-inspiration-details.html">Hiking, Trekking & Thrill-Seeking.</a></h4>
                            <p>Famous for its pure white silica sand & crystal-clear waters. Perfect for snorkeling &
                                sailing!</p>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4 col-md-6 wow animate fadeInDown" data-wow-delay="800ms" data-wow-duration="1500ms">
                    <div class="blog-card2 two">
                        <div class="blog-img-wrap">
                            <a href="travel-inspiration-details.html" class="blog-img">
                                <img src="assets/img/innerpages/blog-img1.jpg" alt="">
                            </a>
                            <a href="travel-inspiration-01.html" class="location">
                                <svg width="14" height="14" viewBox="0 0 14 14"
                                    xmlns="http://www.w3.org/2000/svg">
                                    <path
                                        d="M6.83615 0C3.77766 0 1.28891 2.48879 1.28891 5.54892C1.28891 7.93837 4.6241 11.8351 6.05811 13.3994C6.25669 13.6175 6.54154 13.7411 6.83615 13.7411C7.13076 13.7411 7.41561 13.6175 7.6142 13.3994C9.04821 11.8351 12.3834 7.93833 12.3834 5.54892C12.3834 2.48879 9.89464 0 6.83615 0ZM7.31469 13.1243C7.18936 13.2594 7.02008 13.3342 6.83615 13.3342C6.65222 13.3342 6.48295 13.2594 6.35761 13.1243C4.95614 11.5959 1.69584 7.79515 1.69584 5.54896C1.69584 2.7134 4.00067 0.406933 6.83615 0.406933C9.67164 0.406933 11.9765 2.7134 11.9765 5.54896C11.9765 7.79515 8.71617 11.5959 7.31469 13.1243Z" />
                                    <path
                                        d="M6.83618 8.54529C8.4624 8.54529 9.7807 7.22698 9.7807 5.60077C9.7807 3.97456 8.4624 2.65625 6.83618 2.65625C5.20997 2.65625 3.89166 3.97456 3.89166 5.60077C3.89166 7.22698 5.20997 8.54529 6.83618 8.54529Z" />
                                </svg>
                                Switzerland
                            </a>
                        </div>
                        <div class="blog-content">
                            <a href="travel-inspiration-01.html" class="blog-date">24 March, 2025</a>
                            <h4><a href="travel-inspiration-details.html">The Best Mountain View for Nature Lovers.</a>
                            </h4>
                            <p>Nature, Culture & Thrill: Travel Stories Inspire is a collection of experiences from
                                explorers.</p>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4 col-md-6 wow animate fadeInDown" data-wow-delay="800ms" data-wow-duration="1500ms">
                    <div class="blog-card2 two">
                        <div class="blog-img-wrap">
                            <a href="travel-inspiration-details.html" class="blog-img">
                                <img src="assets/img/innerpages/blog-img2.jpg" alt="">
                            </a>
                            <a href="travel-inspiration-01.html" class="location">
                                <svg width="14" height="14" viewBox="0 0 14 14"
                                    xmlns="http://www.w3.org/2000/svg">
                                    <path
                                        d="M6.83615 0C3.77766 0 1.28891 2.48879 1.28891 5.54892C1.28891 7.93837 4.6241 11.8351 6.05811 13.3994C6.25669 13.6175 6.54154 13.7411 6.83615 13.7411C7.13076 13.7411 7.41561 13.6175 7.6142 13.3994C9.04821 11.8351 12.3834 7.93833 12.3834 5.54892C12.3834 2.48879 9.89464 0 6.83615 0ZM7.31469 13.1243C7.18936 13.2594 7.02008 13.3342 6.83615 13.3342C6.65222 13.3342 6.48295 13.2594 6.35761 13.1243C4.95614 11.5959 1.69584 7.79515 1.69584 5.54896C1.69584 2.7134 4.00067 0.406933 6.83615 0.406933C9.67164 0.406933 11.9765 2.7134 11.9765 5.54896C11.9765 7.79515 8.71617 11.5959 7.31469 13.1243Z" />
                                    <path
                                        d="M6.83618 8.54529C8.4624 8.54529 9.7807 7.22698 9.7807 5.60077C9.7807 3.97456 8.4624 2.65625 6.83618 2.65625C5.20997 2.65625 3.89166 3.97456 3.89166 5.60077C3.89166 7.22698 5.20997 8.54529 6.83618 8.54529Z" />
                                </svg>
                                Brazil
                            </a>
                        </div>
                        <div class="blog-content">
                            <a href="travel-inspiration-01.html" class="blog-date">14 March, 2025</a>
                            <h4><a href="travel-inspiration-details.html">Wildlife Safari Adventures You Can’t Miss.</a>
                            </h4>
                            <p>Dream. Explore. Discover. is more than just a travel motto; it’s an invitation to step out.
                            </p>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4 col-md-6 wow animate fadeInDown" data-wow-delay="800ms" data-wow-duration="1500ms">
                    <div class="blog-card2 two">
                        <div class="blog-img-wrap">
                            <a href="travel-inspiration-details.html" class="blog-img">
                                <img src="assets/img/innerpages/blog-img6.jpg" alt="">
                            </a>
                            <a href="travel-inspiration-01.html" class="location">
                                <svg width="14" height="14" viewBox="0 0 14 14"
                                    xmlns="http://www.w3.org/2000/svg">
                                    <path
                                        d="M6.83615 0C3.77766 0 1.28891 2.48879 1.28891 5.54892C1.28891 7.93837 4.6241 11.8351 6.05811 13.3994C6.25669 13.6175 6.54154 13.7411 6.83615 13.7411C7.13076 13.7411 7.41561 13.6175 7.6142 13.3994C9.04821 11.8351 12.3834 7.93833 12.3834 5.54892C12.3834 2.48879 9.89464 0 6.83615 0ZM7.31469 13.1243C7.18936 13.2594 7.02008 13.3342 6.83615 13.3342C6.65222 13.3342 6.48295 13.2594 6.35761 13.1243C4.95614 11.5959 1.69584 7.79515 1.69584 5.54896C1.69584 2.7134 4.00067 0.406933 6.83615 0.406933C9.67164 0.406933 11.9765 2.7134 11.9765 5.54896C11.9765 7.79515 8.71617 11.5959 7.31469 13.1243Z" />
                                    <path
                                        d="M6.83618 8.54529C8.4624 8.54529 9.7807 7.22698 9.7807 5.60077C9.7807 3.97456 8.4624 2.65625 6.83618 2.65625C5.20997 2.65625 3.89166 3.97456 3.89166 5.60077C3.89166 7.22698 5.20997 8.54529 6.83618 8.54529Z" />
                                </svg>
                                Rome, Italy
                            </a>
                        </div>
                        <div class="blog-content">
                            <a href="travel-inspiration-01.html" class="blog-date">18 April, 2025</a>
                            <h4><a href="travel-inspiration-details.html">Explore Culture, Art, & Timeless Landmarks.</a>
                            </h4>
                            <p>Sun, sand, and crystal-clear waters—summer is the perfect time to escape to the world’s.</p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="pagination-area wow animate fadeInUp" data-wow-delay="200ms" data-wow-duration="1500ms">
                <div class="paginations-button">
                    <a href="#">
                        <svg width="10" height="10" viewBox="0 0 10 10" xmlns="http://www.w3.org/2000/svg">
                            <g>
                                <path
                                    d="M7.86133 9.28516C7.14704 7.49944 3.57561 5.71373 1.43276 4.99944C3.57561 4.28516 6.7899 3.21373 7.86133 0.713728"
                                    stroke-width="1.5" stroke-linecap="round" />
                            </g>
                        </svg>
                        Prev
                    </a>
                </div>
                <ul class="paginations">
                    <li class="page-item active">
                        <a href="#">01</a>
                    </li>
                    <li class="page-item">
                        <a href="#">02</a>
                    </li>
                    <li class="page-item">
                        <a href="#">03</a>
                    </li>
                    <li class="page-item">
                        <a href="#">04</a>
                    </li>
                </ul>
                <div class="paginations-button">
                    <a href="#">
                        Next
                        <svg width="10" height="10" viewBox="0 0 10 10" xmlns="http://www.w3.org/2000/svg">
                            <g>
                                <path
                                    d="M1.42969 9.28613C2.14397 7.50042 5.7154 5.7147 7.85826 5.00042C5.7154 4.28613 2.50112 3.21471 1.42969 0.714705"
                                    stroke-width="1.5" stroke-linecap="round" />
                            </g>
                        </svg>
                    </a>
                </div>
            </div>
        </div>
    </div>
    <!--Travel Inspiration Page End-->

@endsection
