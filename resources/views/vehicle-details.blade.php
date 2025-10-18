@extends('layouts.app')

@section('title', 'Search Results - TheTaxi')

@section('content')
    <!-- Start Breadcrumb section -->
    <div class="breadcrumb-section"
        style="background-image:linear-gradient(rgba(0, 0, 0, 0.3), rgba(0, 0, 0, 0.3)), url(assets/img/innerpages/breadcrumb-bg1.jpg);">
        <div class="container">
            <div class="banner-content">
                <h1>Product Details</h1>
                <ul class="breadcrumb-list">
                    <li><a href="index.html">Home</a></li>
                    <li>Product Details</li>
                </ul>
            </div>
        </div>
    </div>
    <!-- End Breadcrumb section -->

    <!-- Product Details Page Start-->
    <div class="product-details-page pt-100 mb-100">
        <div class="container">
            <div class="row gy-5 justify-content-between mb-70">
                <div class="col-xl-5 col-lg-6">
                    <div class="product-details-img">
                        <div class="tab-content" id="v-pills-tabContent">
                            <div class="tab-pane fade show active" id="v-pills-img1" role="tabpanel">
                                <div class="product-details-tab-img">
                                    <img src="{{ asset('assets/img/innerpages/product-details-img1.jpg') }}" alt="">
                                </div>
                            </div>
                            <div class="tab-pane fade" id="v-pills-img2" role="tabpanel">
                                <div class="product-details-tab-img">
                                    <img src="{{ asset('assets/img/innerpages/product-details-img2.jpg') }}" alt="">
                                </div>
                            </div>
                            <div class="tab-pane fade" id="v-pills-img3" role="tabpanel" aria-labelledby="v-pills-img3-tab">
                                <div class="product-details-tab-img">
                                    <img src="{{ asset('assets/img/innerpages/product-details-img3.jpg') }}" alt="">
                                </div>
                            </div>
                        </div>
                        <ul class="nav nav-pills" id="v-pills-tab" role="tablist" aria-orientation="vertical">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="v-pills-img1-tab" data-bs-toggle="pill"
                                    data-bs-target="#v-pills-img1" type="button" role="tab"
                                    aria-controls="v-pills-img1" aria-selected="true">
                                    <img src="{{ asset('assets/img/innerpages/product-details-img1.jpg') }}" alt="">
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="v-pills-img2-tab" data-bs-toggle="pill"
                                    data-bs-target="#v-pills-img2" type="button" role="tab"
                                    aria-controls="v-pills-img2" aria-selected="false">
                                    <img src="{{ asset('assets/img/innerpages/product-details-img2.jpg') }}" alt="">
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="v-pills-img3-tab" data-bs-toggle="pill"
                                    data-bs-target="#v-pills-img3" type="button" role="tab"
                                    aria-controls="v-pills-img3" aria-selected="false">
                                    <img src="{{ asset('assets/img/innerpages/product-details-img3.jpg') }}" alt="">
                                </button>
                            </li>
                        </ul>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="product-details-content">
                        <h2>SwiftMove Travel Wear</h2>
                        <p>It provides an opportunity for people to connect with friends or family members while receiving
                            treatments together.</p>
                        <ul>
                            <li>
                                <svg width="16" height="16" viewBox="0 0 16 16" xmlns="http://www.w3.org/2000/svg">
                                    <path fill-rule="evenodd" clip-rule="evenodd"
                                        d="M4.5197 9.35783L6.0477 11.5708C6.99602 10.2009 11.2112 3.50919 13.6349 0.400391C11.1248 5.14183 8.94274 10.0882 6.98018 15.0588C6.69858 15.7717 5.69441 15.7839 5.39873 15.0767C4.46385 12.8415 3.45873 10.6202 2.35938 8.46199C3.14977 8.30391 3.99265 8.56743 4.51953 9.35783H4.5197Z" />
                                </svg>
                                Thermal Properties
                            </li>
                            <li>
                                <svg width="16" height="16" viewBox="0 0 16 16" xmlns="http://www.w3.org/2000/svg">
                                    <path fill-rule="evenodd" clip-rule="evenodd"
                                        d="M4.5197 9.35783L6.0477 11.5708C6.99602 10.2009 11.2112 3.50919 13.6349 0.400391C11.1248 5.14183 8.94274 10.0882 6.98018 15.0588C6.69858 15.7717 5.69441 15.7839 5.39873 15.0767C4.46385 12.8415 3.45873 10.6202 2.35938 8.46199C3.14977 8.30391 3.99265 8.56743 4.51953 9.35783H4.5197Z" />
                                </svg>
                                Stretch Fabric
                            </li>
                            <li>
                                <svg width="16" height="16" viewBox="0 0 16 16" xmlns="http://www.w3.org/2000/svg">
                                    <path fill-rule="evenodd" clip-rule="evenodd"
                                        d="M4.5197 9.35783L6.0477 11.5708C6.99602 10.2009 11.2112 3.50919 13.6349 0.400391C11.1248 5.14183 8.94274 10.0882 6.98018 15.0588C6.69858 15.7717 5.69441 15.7839 5.39873 15.0767C4.46385 12.8415 3.45873 10.6202 2.35938 8.46199C3.14977 8.30391 3.99265 8.56743 4.51953 9.35783H4.5197Z" />
                                </svg>
                                Turtleneck or High Neckline
                            </li>
                        </ul>
                        <div class="price-tag">
                            <h5><del>$345.0</del> $300.0</h5>
                        </div>
                        <div class="product-quantity d-flex align-items-center justify-content-start">
                            <div class="quantity">
                                <a class="quantity__minus"><span><i class="bi bi-dash"></i></span></a>
                                <input name="quantity" type="text" class="quantity__input" value="01">
                                <a class="quantity__plus"><span><i class="bi bi-plus"></i></span></a>
                            </div>
                            <a href="cart.html" class="primary-btn1 transparent">
                                <span>
                                    Add to Cart
                                    <svg width="10" height="10" viewBox="0 0 10 10"
                                        xmlns="http://www.w3.org/2000/svg">
                                        <path
                                            d="M9.73535 1.14746C9.57033 1.97255 9.32924 3.26406 9.24902 4.66797C9.16817 6.08312 9.25559 7.5453 9.70214 8.73633C9.84754 9.12406 9.65129 9.55659 9.26367 9.70215C8.9001 9.83849 8.4969 9.67455 8.32812 9.33398L8.29785 9.26367L8.19921 8.98438C7.73487 7.5758 7.67054 5.98959 7.75097 4.58203C7.77875 4.09598 7.82525 3.62422 7.87988 3.17969L1.53027 9.53027C1.23738 9.82317 0.762615 9.82317 0.469722 9.53027C0.176829 9.23738 0.176829 8.76262 0.469722 8.46973L6.83593 2.10254C6.3319 2.16472 5.79596 2.21841 5.25 2.24902C3.8302 2.32862 2.2474 2.26906 0.958003 1.79102L0.704097 1.68945L0.635738 1.65527C0.303274 1.47099 0.157578 1.06102 0.310542 0.704102C0.463655 0.347333 0.860941 0.170391 1.22363 0.28418L1.29589 0.310547L1.48828 0.387695C2.47399 0.751207 3.79966 0.827571 5.16601 0.750977C6.60111 0.670504 7.97842 0.428235 8.86132 0.262695L9.95312 0.0585938L9.73535 1.14746Z">
                                        </path>
                                    </svg>
                                </span>
                                <span>
                                    Add to Cart
                                    <svg width="10" height="10" viewBox="0 0 10 10"
                                        xmlns="http://www.w3.org/2000/svg">
                                        <path
                                            d="M9.73535 1.14746C9.57033 1.97255 9.32924 3.26406 9.24902 4.66797C9.16817 6.08312 9.25559 7.5453 9.70214 8.73633C9.84754 9.12406 9.65129 9.55659 9.26367 9.70215C8.9001 9.83849 8.4969 9.67455 8.32812 9.33398L8.29785 9.26367L8.19921 8.98438C7.73487 7.5758 7.67054 5.98959 7.75097 4.58203C7.77875 4.09598 7.82525 3.62422 7.87988 3.17969L1.53027 9.53027C1.23738 9.82317 0.762615 9.82317 0.469722 9.53027C0.176829 9.23738 0.176829 8.76262 0.469722 8.46973L6.83593 2.10254C6.3319 2.16472 5.79596 2.21841 5.25 2.24902C3.8302 2.32862 2.2474 2.26906 0.958003 1.79102L0.704097 1.68945L0.635738 1.65527C0.303274 1.47099 0.157578 1.06102 0.310542 0.704102C0.463655 0.347333 0.860941 0.170391 1.22363 0.28418L1.29589 0.310547L1.48828 0.387695C2.47399 0.751207 3.79966 0.827571 5.16601 0.750977C6.60111 0.670504 7.97842 0.428235 8.86132 0.262695L9.95312 0.0585938L9.73535 1.14746Z">
                                        </path>
                                    </svg>
                                </span>
                            </a>
                            <a href="checkout.html" class="primary-btn1">
                                <span>
                                    Buy Now
                                    <svg width="10" height="10" viewBox="0 0 10 10"
                                        xmlns="http://www.w3.org/2000/svg">
                                        <path
                                            d="M9.73535 1.14746C9.57033 1.97255 9.32924 3.26406 9.24902 4.66797C9.16817 6.08312 9.25559 7.5453 9.70214 8.73633C9.84754 9.12406 9.65129 9.55659 9.26367 9.70215C8.9001 9.83849 8.4969 9.67455 8.32812 9.33398L8.29785 9.26367L8.19921 8.98438C7.73487 7.5758 7.67054 5.98959 7.75097 4.58203C7.77875 4.09598 7.82525 3.62422 7.87988 3.17969L1.53027 9.53027C1.23738 9.82317 0.762615 9.82317 0.469722 9.53027C0.176829 9.23738 0.176829 8.76262 0.469722 8.46973L6.83593 2.10254C6.3319 2.16472 5.79596 2.21841 5.25 2.24902C3.8302 2.32862 2.2474 2.26906 0.958003 1.79102L0.704097 1.68945L0.635738 1.65527C0.303274 1.47099 0.157578 1.06102 0.310542 0.704102C0.463655 0.347333 0.860941 0.170391 1.22363 0.28418L1.29589 0.310547L1.48828 0.387695C2.47399 0.751207 3.79966 0.827571 5.16601 0.750977C6.60111 0.670504 7.97842 0.428235 8.86132 0.262695L9.95312 0.0585938L9.73535 1.14746Z">
                                        </path>
                                    </svg>
                                </span>
                                <span>
                                    Buy Now
                                    <svg width="10" height="10" viewBox="0 0 10 10"
                                        xmlns="http://www.w3.org/2000/svg">
                                        <path
                                            d="M9.73535 1.14746C9.57033 1.97255 9.32924 3.26406 9.24902 4.66797C9.16817 6.08312 9.25559 7.5453 9.70214 8.73633C9.84754 9.12406 9.65129 9.55659 9.26367 9.70215C8.9001 9.83849 8.4969 9.67455 8.32812 9.33398L8.29785 9.26367L8.19921 8.98438C7.73487 7.5758 7.67054 5.98959 7.75097 4.58203C7.77875 4.09598 7.82525 3.62422 7.87988 3.17969L1.53027 9.53027C1.23738 9.82317 0.762615 9.82317 0.469722 9.53027C0.176829 9.23738 0.176829 8.76262 0.469722 8.46973L6.83593 2.10254C6.3319 2.16472 5.79596 2.21841 5.25 2.24902C3.8302 2.32862 2.2474 2.26906 0.958003 1.79102L0.704097 1.68945L0.635738 1.65527C0.303274 1.47099 0.157578 1.06102 0.310542 0.704102C0.463655 0.347333 0.860941 0.170391 1.22363 0.28418L1.29589 0.310547L1.48828 0.387695C2.47399 0.751207 3.79966 0.827571 5.16601 0.750977C6.60111 0.670504 7.97842 0.428235 8.86132 0.262695L9.95312 0.0585938L9.73535 1.14746Z">
                                        </path>
                                    </svg>
                                </span>
                            </a>
                        </div>
                        <ul class="aditional-info">
                            <li><span>SKU:</span> D32-5H23</li>
                            <li><span>Category:</span> <a href="shop.html">Perfume</a></li>
                            <li>
                                <span>Tags:</span>
                                <a href="product-details.html">Fashion,</a> <a href="product-details.html">Winter</a>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
            <div class="product-description-and-review-area">
                <div class="row">
                    <div class="col-lg-12">
                        <div class="nav nav2 nav-pills" id="v-pills-tab2" role="tablist" aria-orientation="vertical">
                            <button class="nav-link active" id="description-tab" data-bs-toggle="pill"
                                data-bs-target="#description" type="button" role="tab" aria-controls="description"
                                aria-selected="false">Product Details</button>
                            <button class="nav-link" id="review-tab" data-bs-toggle="pill" data-bs-target="#review"
                                type="button" role="tab" aria-controls="review" aria-selected="true">Customer
                                Reviews</button>
                        </div>
                        <div class="tab-content tab-content2" id="v-pills-tabContent2">
                            <div class="tab-pane fade active show" id="description" role="tabpanel"
                                aria-labelledby="description-tab">
                                <div class="description">
                                    <p>The Winter Sheath Dress is a stylish and practical choice for cold-weather fashion.
                                        Designed with warmth and sophistication in mind, it typically features insulating
                                        materials like wool, cashmere, or thick knits, offering a cozy feel without
                                        sacrificing the sleek, tailored silhouette characteristic of a classic sheath dress.
                                    </p>

                                    <p>Ideal for layering, the winter sheath dress can be paired effortlessly with tights,
                                        scarves, and coats, making it a versatile option for both work and evening outings.
                                        With thoughtful details like pockets, a hint of stretch for comfort, and options for
                                        belted waists, this dress is perfect for the cooler months, combining elegance and
                                        practicality in a timeless design.</p>
                                </div>
                            </div>
                            <div class="tab-pane fade" id="review" role="tabpanel" aria-labelledby="review-tab">
                                <div class="reviews-area">
                                    <div class="row g-lg-4 gy-5">
                                        <div class="col-lg-7">
                                            <div class="comment-and-form-area two">
                                                <div class="comment-area">
                                                    <h2 class="comment-title">Review (02)</h2>
                                                    <ul class="comment">
                                                        <li>
                                                            <div class="single-comment-area">
                                                                <div class="author-img">
                                                                    <img src="{{ asset('assets/img/innerpages/comment-author-01.jpg') }}"
                                                                        alt="">
                                                                </div>
                                                                <div class="comment-content">
                                                                    <div class="author-name-deg">
                                                                        <h6>Mr. Bowmik Haldar,</h6>
                                                                        <span>05 June, 2023</span>
                                                                    </div>
                                                                    <p>However, here are some well-regarded car
                                                                        dealerships known for their customer
                                                                        service, inventory, and overall
                                                                        reputation. It's always a good idea to
                                                                        research and read reviews specific...
                                                                    </p>
                                                                    <div class="replay-btn">
                                                                        <svg xmlns="http://www.w3.org/2000/svg"
                                                                            width="14" height="11"
                                                                            viewBox="0 0 14 11">
                                                                            <path
                                                                                d="M8.55126 1.11188C8.52766 1.10118 8.50182 1.09676 8.47612 1.09903C8.45042 1.1013 8.42569 1.11018 8.40419 1.12486C8.3827 1.13954 8.36513 1.15954 8.35311 1.18304C8.34109 1.20653 8.335 1.23276 8.33539 1.25932V2.52797C8.33539 2.67388 8.2791 2.81381 8.17889 2.91698C8.07868 3.02016 7.94277 3.07812 7.80106 3.07812C7.08826 3.07812 5.64984 3.08362 4.27447 3.98257C3.2229 4.66916 2.14783 5.9191 1.50129 8.24735C2.59132 7.16575 3.83632 6.57929 4.92635 6.2679C5.59636 6.07737 6.28492 5.96444 6.97926 5.93121C7.26347 5.91835 7.54815 5.92129 7.83205 5.94001H7.84594L7.85129 5.94111L7.80106 6.48906L7.85449 5.94111C7.98638 5.95476 8.10864 6.01839 8.19751 6.11966C8.28638 6.22092 8.33553 6.35258 8.33539 6.48906V7.75771C8.33539 7.87654 8.45294 7.95136 8.55126 7.90515L12.8088 4.67796C12.8233 4.66692 12.8383 4.65664 12.8537 4.64715C12.8769 4.63278 12.8962 4.61245 12.9095 4.58816C12.9229 4.56386 12.9299 4.53643 12.9299 4.50851C12.9299 4.4806 12.9229 4.45316 12.9095 4.42887C12.8962 4.40458 12.8769 4.38425 12.8537 4.36988C12.8382 4.36039 12.8233 4.35011 12.8088 4.33907L8.55126 1.11188ZM7.26673 7.02381C7.19406 7.02381 7.11391 7.02711 7.02842 7.03041C6.56462 7.05242 5.92342 7.12504 5.21169 7.32859C3.79464 7.7335 2.11684 8.65116 1.00115 10.7175C0.940817 10.8291 0.844683 10.9155 0.729224 10.9621C0.613765 11.0087 0.486168 11.0124 0.368304 10.9728C0.250441 10.9331 0.149648 10.8525 0.0831985 10.7447C0.0167484 10.6369 -0.011219 10.5086 0.0040884 10.3819C0.499949 6.29981 2.01959 4.15202 3.70167 3.05391C5.03215 2.18467 6.40218 2.01743 7.26673 1.98552V1.25932C7.26663 1.03273 7.32593 0.810317 7.43839 0.615545C7.55084 0.420773 7.71227 0.260866 7.90565 0.152696C8.09902 0.0445258 8.31717 -0.00789584 8.53707 0.000962485C8.75698 0.00982081 8.97048 0.0796305 9.15506 0.203025L13.4233 3.43792C13.5998 3.55133 13.7453 3.7091 13.8462 3.8964C13.9471 4.08369 14 4.29434 14 4.50851C14 4.72269 13.9471 4.93333 13.8462 5.12063C13.7453 5.30792 13.5998 5.4657 13.4233 5.57911L9.15506 8.814C8.97048 8.9374 8.75698 9.00721 8.53707 9.01607C8.31717 9.02492 8.09902 8.9725 7.90565 8.86433C7.71227 8.75616 7.55084 8.59626 7.43839 8.40148C7.32593 8.20671 7.26663 7.9843 7.26673 7.75771V7.02381Z">
                                                                            </path>
                                                                        </svg>
                                                                        Reply (01)
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            <ul class="comment-replay">
                                                                <li>
                                                                    <div class="single-comment-area">
                                                                        <div class="author-img">
                                                                            <img src="{{ asset('assets/img/innerpages/comment-author-02.jpg') }}"
                                                                                alt="">
                                                                        </div>
                                                                        <div class="comment-content">
                                                                            <div class="author-name-deg">
                                                                                <h6>Jacoline Juie,</h6>
                                                                                <span>05 June, 2023</span>
                                                                            </div>
                                                                            <p>However, here are some
                                                                                well-regarded car dealerships
                                                                                known for their customer
                                                                                service, inventory, and overall
                                                                                reputation. It's always a good
                                                                                idea to research and read
                                                                                reviews specific...</p>
                                                                            <div class="replay-btn">
                                                                                <svg xmlns="http://www.w3.org/2000/svg"
                                                                                    width="14" height="11"
                                                                                    viewBox="0 0 14 11">
                                                                                    <path
                                                                                        d="M8.55126 1.11188C8.52766 1.10118 8.50182 1.09676 8.47612 1.09903C8.45042 1.1013 8.42569 1.11018 8.40419 1.12486C8.3827 1.13954 8.36513 1.15954 8.35311 1.18304C8.34109 1.20653 8.335 1.23276 8.33539 1.25932V2.52797C8.33539 2.67388 8.2791 2.81381 8.17889 2.91698C8.07868 3.02016 7.94277 3.07812 7.80106 3.07812C7.08826 3.07812 5.64984 3.08362 4.27447 3.98257C3.2229 4.66916 2.14783 5.9191 1.50129 8.24735C2.59132 7.16575 3.83632 6.57929 4.92635 6.2679C5.59636 6.07737 6.28492 5.96444 6.97926 5.93121C7.26347 5.91835 7.54815 5.92129 7.83205 5.94001H7.84594L7.85129 5.94111L7.80106 6.48906L7.85449 5.94111C7.98638 5.95476 8.10864 6.01839 8.19751 6.11966C8.28638 6.22092 8.33553 6.35258 8.33539 6.48906V7.75771C8.33539 7.87654 8.45294 7.95136 8.55126 7.90515L12.8088 4.67796C12.8233 4.66692 12.8383 4.65664 12.8537 4.64715C12.8769 4.63278 12.8962 4.61245 12.9095 4.58816C12.9229 4.56386 12.9299 4.53643 12.9299 4.50851C12.9299 4.4806 12.9229 4.45316 12.9095 4.42887C12.8962 4.40458 12.8769 4.38425 12.8537 4.36988C12.8382 4.36039 12.8233 4.35011 12.8088 4.33907L8.55126 1.11188ZM7.26673 7.02381C7.19406 7.02381 7.11391 7.02711 7.02842 7.03041C6.56462 7.05242 5.92342 7.12504 5.21169 7.32859C3.79464 7.7335 2.11684 8.65116 1.00115 10.7175C0.940817 10.8291 0.844683 10.9155 0.729224 10.9621C0.613765 11.0087 0.486168 11.0124 0.368304 10.9728C0.250441 10.9331 0.149648 10.8525 0.0831985 10.7447C0.0167484 10.6369 -0.011219 10.5086 0.0040884 10.3819C0.499949 6.29981 2.01959 4.15202 3.70167 3.05391C5.03215 2.18467 6.40218 2.01743 7.26673 1.98552V1.25932C7.26663 1.03273 7.32593 0.810317 7.43839 0.615545C7.55084 0.420773 7.71227 0.260866 7.90565 0.152696C8.09902 0.0445258 8.31717 -0.00789584 8.53707 0.000962485C8.75698 0.00982081 8.97048 0.0796305 9.15506 0.203025L13.4233 3.43792C13.5998 3.55133 13.7453 3.7091 13.8462 3.8964C13.9471 4.08369 14 4.29434 14 4.50851C14 4.72269 13.9471 4.93333 13.8462 5.12063C13.7453 5.30792 13.5998 5.4657 13.4233 5.57911L9.15506 8.814C8.97048 8.9374 8.75698 9.00721 8.53707 9.01607C8.31717 9.02492 8.09902 8.9725 7.90565 8.86433C7.71227 8.75616 7.55084 8.59626 7.43839 8.40148C7.32593 8.20671 7.26663 7.9843 7.26673 7.75771V7.02381Z">
                                                                                    </path>
                                                                                </svg>
                                                                                Reply
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                </li>
                                                            </ul>
                                                        </li>
                                                    </ul>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-lg-5">
                                            <div class="review-form">
                                                <div class="number-of-review">
                                                    <h4>Write A Review</h4>
                                                </div>
                                                <form>
                                                    <div class="row">
                                                        <div class="col-lg-12">
                                                            <div class="form-inner2 mb-40">
                                                                <div class="review-rate-area">
                                                                    <p>Your Rating</p>
                                                                    <div class="rate">
                                                                        <input type="radio" id="star5"
                                                                            name="rate" value="5">
                                                                        <label for="star5" title="text">5
                                                                            stars</label>
                                                                        <input type="radio" id="star4"
                                                                            name="rate" value="4">
                                                                        <label for="star4" title="text">4
                                                                            stars</label>
                                                                        <input type="radio" id="star3"
                                                                            name="rate" value="3">
                                                                        <label for="star3" title="text">3
                                                                            stars</label>
                                                                        <input type="radio" id="star2"
                                                                            name="rate" value="2">
                                                                        <label for="star2" title="text">2
                                                                            stars</label>
                                                                        <input type="radio" id="star1"
                                                                            name="rate" value="1">
                                                                        <label for="star1" title="text">1
                                                                            star</label>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="col-lg-12">
                                                            <div class="form-inner mb-20">
                                                                <input type="text" placeholder="Name*" required="">
                                                            </div>
                                                        </div>
                                                        <div class="col-lg-12">
                                                            <div class="form-inner mb-20">
                                                                <input type="email" placeholder="Email*"
                                                                    required="">
                                                            </div>
                                                        </div>
                                                        <div class="col-lg-12">
                                                            <div class="form-inner mb-50">
                                                                <textarea placeholder="Message..."></textarea>
                                                            </div>
                                                        </div>
                                                        <div class="col-lg-12">
                                                            <button class="primary-btn1" type="submit">
                                                                <span>
                                                                    Submit Now
                                                                    <svg width="10" height="10"
                                                                        viewBox="0 0 10 10"
                                                                        xmlns="http://www.w3.org/2000/svg">
                                                                        <path
                                                                            d="M9.73535 1.14746C9.57033 1.97255 9.32924 3.26406 9.24902 4.66797C9.16817 6.08312 9.25559 7.5453 9.70214 8.73633C9.84754 9.12406 9.65129 9.55659 9.26367 9.70215C8.9001 9.83849 8.4969 9.67455 8.32812 9.33398L8.29785 9.26367L8.19921 8.98438C7.73487 7.5758 7.67054 5.98959 7.75097 4.58203C7.77875 4.09598 7.82525 3.62422 7.87988 3.17969L1.53027 9.53027C1.23738 9.82317 0.762615 9.82317 0.469722 9.53027C0.176829 9.23738 0.176829 8.76262 0.469722 8.46973L6.83593 2.10254C6.3319 2.16472 5.79596 2.21841 5.25 2.24902C3.8302 2.32862 2.2474 2.26906 0.958003 1.79102L0.704097 1.68945L0.635738 1.65527C0.303274 1.47099 0.157578 1.06102 0.310542 0.704102C0.463655 0.347333 0.860941 0.170391 1.22363 0.28418L1.29589 0.310547L1.48828 0.387695C2.47399 0.751207 3.79966 0.827571 5.16601 0.750977C6.60111 0.670504 7.97842 0.428235 8.86132 0.262695L9.95312 0.0585938L9.73535 1.14746Z">
                                                                        </path>
                                                                    </svg>
                                                                </span>
                                                                <span>
                                                                    Submit Now
                                                                    <svg width="10" height="10"
                                                                        viewBox="0 0 10 10"
                                                                        xmlns="http://www.w3.org/2000/svg">
                                                                        <path
                                                                            d="M9.73535 1.14746C9.57033 1.97255 9.32924 3.26406 9.24902 4.66797C9.16817 6.08312 9.25559 7.5453 9.70214 8.73633C9.84754 9.12406 9.65129 9.55659 9.26367 9.70215C8.9001 9.83849 8.4969 9.67455 8.32812 9.33398L8.29785 9.26367L8.19921 8.98438C7.73487 7.5758 7.67054 5.98959 7.75097 4.58203C7.77875 4.09598 7.82525 3.62422 7.87988 3.17969L1.53027 9.53027C1.23738 9.82317 0.762615 9.82317 0.469722 9.53027C0.176829 9.23738 0.176829 8.76262 0.469722 8.46973L6.83593 2.10254C6.3319 2.16472 5.79596 2.21841 5.25 2.24902C3.8302 2.32862 2.2474 2.26906 0.958003 1.79102L0.704097 1.68945L0.635738 1.65527C0.303274 1.47099 0.157578 1.06102 0.310542 0.704102C0.463655 0.347333 0.860941 0.170391 1.22363 0.28418L1.29589 0.310547L1.48828 0.387695C2.47399 0.751207 3.79966 0.827571 5.16601 0.750977C6.60111 0.670504 7.97842 0.428235 8.86132 0.262695L9.95312 0.0585938L9.73535 1.14746Z">
                                                                        </path>
                                                                    </svg>
                                                                </span>
                                                            </button>
                                                        </div>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!--Product Details Page End-->

    <!-- Related Product Page Start -->
    <div class="related-product-section mb-130">
        <div class="container">
            <div class="row">
                <div class="col-lg-12">
                    <div class="section-title">
                        <h3>Related Product</h3>
                    </div>
                </div>
            </div>
            <div class="related-product-slider-area">
                <div class="row">
                    <div class="col-lg-12">
                        <div class="swiper related-product-slider">
                            <div class="swiper-wrapper">
                                <div class="swiper-slide">
                                    <div class="product-card">
                                        <div class="product-card-img-wrap">
                                            <a href="product-details.html" class="product-card-img">
                                                <img src="{{ asset('assets/img/innerpages/product-img2.jpg') }}"
                                                    alt="">
                                            </a>
                                            <a href="cart.html" class="cart-btn">
                                                <svg width="16" height="16" viewBox="0 0 16 16"
                                                    xmlns="http://www.w3.org/2000/svg">
                                                    <path
                                                        d="M11.9016 15H4.10156C2.45156 15 1.10156 13.65 1.10156 12V11.9L1.40156 3.9C1.45156 2.25 2.80156 1 4.40156 1H11.6016C13.2016 1 14.5516 2.25 14.6016 3.9L14.9016 11.9C14.9516 12.7 14.6516 13.45 14.1016 14.05C13.5516 14.65 12.8016 15 12.0016 15H11.9016ZM4.40156 2C3.30156 2 2.45156 2.85 2.40156 3.9L2.10156 12C2.10156 13.1 3.00156 14 4.10156 14H12.0016C12.5516 14 13.0516 13.75 13.4016 13.35C13.7516 12.95 13.9516 12.45 13.9516 11.9L13.6516 3.9C13.6016 2.8 12.7516 2 11.6516 2H4.40156Z" />
                                                    <path
                                                        d="M8 7C6.05 7 4.5 5.45 4.5 3.5C4.5 3.2 4.7 3 5 3C5.3 3 5.5 3.2 5.5 3.5C5.5 4.9 6.6 6 8 6C9.4 6 10.5 4.9 10.5 3.5C10.5 3.2 10.7 3 11 3C11.3 3 11.5 3.2 11.5 3.5C11.5 5.45 9.95 7 8 7Z" />
                                                </svg>
                                                Add to Cart
                                            </a>
                                        </div>
                                        <div class="product-card-content">
                                            <h6><a href="product-details.html">Casual Outfit Set</a></h6>
                                            <span>$356.0</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="swiper-slide">
                                    <div class="product-card">
                                        <div class="product-card-img-wrap">
                                            <a href="product-details.html" class="product-card-img">
                                                <img src="{{ asset('assets/img/innerpages/product-img5.jpg') }}"
                                                    alt="">
                                            </a>
                                            <a href="cart.html" class="cart-btn">
                                                <svg width="16" height="16" viewBox="0 0 16 16"
                                                    xmlns="http://www.w3.org/2000/svg">
                                                    <path
                                                        d="M11.9016 15H4.10156C2.45156 15 1.10156 13.65 1.10156 12V11.9L1.40156 3.9C1.45156 2.25 2.80156 1 4.40156 1H11.6016C13.2016 1 14.5516 2.25 14.6016 3.9L14.9016 11.9C14.9516 12.7 14.6516 13.45 14.1016 14.05C13.5516 14.65 12.8016 15 12.0016 15H11.9016ZM4.40156 2C3.30156 2 2.45156 2.85 2.40156 3.9L2.10156 12C2.10156 13.1 3.00156 14 4.10156 14H12.0016C12.5516 14 13.0516 13.75 13.4016 13.35C13.7516 12.95 13.9516 12.45 13.9516 11.9L13.6516 3.9C13.6016 2.8 12.7516 2 11.6516 2H4.40156Z" />
                                                    <path
                                                        d="M8 7C6.05 7 4.5 5.45 4.5 3.5C4.5 3.2 4.7 3 5 3C5.3 3 5.5 3.2 5.5 3.5C5.5 4.9 6.6 6 8 6C9.4 6 10.5 4.9 10.5 3.5C10.5 3.2 10.7 3 11 3C11.3 3 11.5 3.2 11.5 3.5C11.5 5.45 9.95 7 8 7Z" />
                                                </svg>
                                                Add to Cart
                                            </a>
                                        </div>
                                        <div class="product-card-content">
                                            <h6><a href="product-details.html">Chic Winter Coat</a></h6>
                                            <span>$160.00 <del>$220.00</del></span>
                                        </div>
                                    </div>
                                </div>
                                <div class="swiper-slide">
                                    <div class="product-card">
                                        <div class="product-card-img-wrap">
                                            <a href="product-details.html" class="product-card-img">
                                                <img src="{{ asset('assets/img/innerpages/product-img6.jpg') }}"
                                                    alt="">
                                            </a>
                                            <a href="cart.html" class="cart-btn">
                                                <svg width="16" height="16" viewBox="0 0 16 16"
                                                    xmlns="http://www.w3.org/2000/svg">
                                                    <path
                                                        d="M11.9016 15H4.10156C2.45156 15 1.10156 13.65 1.10156 12V11.9L1.40156 3.9C1.45156 2.25 2.80156 1 4.40156 1H11.6016C13.2016 1 14.5516 2.25 14.6016 3.9L14.9016 11.9C14.9516 12.7 14.6516 13.45 14.1016 14.05C13.5516 14.65 12.8016 15 12.0016 15H11.9016ZM4.40156 2C3.30156 2 2.45156 2.85 2.40156 3.9L2.10156 12C2.10156 13.1 3.00156 14 4.10156 14H12.0016C12.5516 14 13.0516 13.75 13.4016 13.35C13.7516 12.95 13.9516 12.45 13.9516 11.9L13.6516 3.9C13.6016 2.8 12.7516 2 11.6516 2H4.40156Z" />
                                                    <path
                                                        d="M8 7C6.05 7 4.5 5.45 4.5 3.5C4.5 3.2 4.7 3 5 3C5.3 3 5.5 3.2 5.5 3.5C5.5 4.9 6.6 6 8 6C9.4 6 10.5 4.9 10.5 3.5C10.5 3.2 10.7 3 11 3C11.3 3 11.5 3.2 11.5 3.5C11.5 5.45 9.95 7 8 7Z" />
                                                </svg>
                                                Add to Cart
                                            </a>
                                        </div>
                                        <div class="product-card-content">
                                            <h6><a href="product-details.html">Trendy Sneakers</a></h6>
                                            <span>$120.0</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="swiper-slide">
                                    <div class="product-card">
                                        <div class="product-card-img-wrap">
                                            <a href="product-details.html" class="product-card-img">
                                                <img src="{{ asset('assets/img/innerpages/product-img7.jpg') }}"
                                                    alt="">
                                            </a>
                                            <a href="cart.html" class="cart-btn">
                                                <svg width="16" height="16" viewBox="0 0 16 16"
                                                    xmlns="http://www.w3.org/2000/svg">
                                                    <path
                                                        d="M11.9016 15H4.10156C2.45156 15 1.10156 13.65 1.10156 12V11.9L1.40156 3.9C1.45156 2.25 2.80156 1 4.40156 1H11.6016C13.2016 1 14.5516 2.25 14.6016 3.9L14.9016 11.9C14.9516 12.7 14.6516 13.45 14.1016 14.05C13.5516 14.65 12.8016 15 12.0016 15H11.9016ZM4.40156 2C3.30156 2 2.45156 2.85 2.40156 3.9L2.10156 12C2.10156 13.1 3.00156 14 4.10156 14H12.0016C12.5516 14 13.0516 13.75 13.4016 13.35C13.7516 12.95 13.9516 12.45 13.9516 11.9L13.6516 3.9C13.6016 2.8 12.7516 2 11.6516 2H4.40156Z" />
                                                    <path
                                                        d="M8 7C6.05 7 4.5 5.45 4.5 3.5C4.5 3.2 4.7 3 5 3C5.3 3 5.5 3.2 5.5 3.5C5.5 4.9 6.6 6 8 6C9.4 6 10.5 4.9 10.5 3.5C10.5 3.2 10.7 3 11 3C11.3 3 11.5 3.2 11.5 3.5C11.5 5.45 9.95 7 8 7Z" />
                                                </svg>
                                                Add to Cart
                                            </a>
                                        </div>
                                        <div class="product-card-content">
                                            <h6><a href="product-details.html">Home Fragrance</a></h6>
                                            <span>$45.0</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="swiper-slide">
                                    <div class="product-card">
                                        <div class="product-card-img-wrap">
                                            <a href="product-details.html" class="product-card-img">
                                                <img src="{{ asset('assets/img/innerpages/product-img8.jpg') }}"
                                                    alt="">
                                            </a>
                                            <a href="cart.html" class="cart-btn">
                                                <svg width="16" height="16" viewBox="0 0 16 16"
                                                    xmlns="http://www.w3.org/2000/svg">
                                                    <path
                                                        d="M11.9016 15H4.10156C2.45156 15 1.10156 13.65 1.10156 12V11.9L1.40156 3.9C1.45156 2.25 2.80156 1 4.40156 1H11.6016C13.2016 1 14.5516 2.25 14.6016 3.9L14.9016 11.9C14.9516 12.7 14.6516 13.45 14.1016 14.05C13.5516 14.65 12.8016 15 12.0016 15H11.9016ZM4.40156 2C3.30156 2 2.45156 2.85 2.40156 3.9L2.10156 12C2.10156 13.1 3.00156 14 4.10156 14H12.0016C12.5516 14 13.0516 13.75 13.4016 13.35C13.7516 12.95 13.9516 12.45 13.9516 11.9L13.6516 3.9C13.6016 2.8 12.7516 2 11.6516 2H4.40156Z" />
                                                    <path
                                                        d="M8 7C6.05 7 4.5 5.45 4.5 3.5C4.5 3.2 4.7 3 5 3C5.3 3 5.5 3.2 5.5 3.5C5.5 4.9 6.6 6 8 6C9.4 6 10.5 4.9 10.5 3.5C10.5 3.2 10.7 3 11 3C11.3 3 11.5 3.2 11.5 3.5C11.5 5.45 9.95 7 8 7Z" />
                                                </svg>
                                                Add to Cart
                                            </a>
                                        </div>
                                        <div class="product-card-content">
                                            <h6><a href="product-details.html">Natural Ingredients</a></h6>
                                            <span>$238.0</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="swiper-slide">
                                    <div class="product-card">
                                        <div class="product-card-img-wrap">
                                            <a href="product-details.html" class="product-card-img">
                                                <img src="{{ asset('assets/img/innerpages/product-img9.jpg') }}"
                                                    alt="">
                                            </a>
                                            <a href="cart.html" class="cart-btn">
                                                <svg width="16" height="16" viewBox="0 0 16 16"
                                                    xmlns="http://www.w3.org/2000/svg">
                                                    <path
                                                        d="M11.9016 15H4.10156C2.45156 15 1.10156 13.65 1.10156 12V11.9L1.40156 3.9C1.45156 2.25 2.80156 1 4.40156 1H11.6016C13.2016 1 14.5516 2.25 14.6016 3.9L14.9016 11.9C14.9516 12.7 14.6516 13.45 14.1016 14.05C13.5516 14.65 12.8016 15 12.0016 15H11.9016ZM4.40156 2C3.30156 2 2.45156 2.85 2.40156 3.9L2.10156 12C2.10156 13.1 3.00156 14 4.10156 14H12.0016C12.5516 14 13.0516 13.75 13.4016 13.35C13.7516 12.95 13.9516 12.45 13.9516 11.9L13.6516 3.9C13.6016 2.8 12.7516 2 11.6516 2H4.40156Z" />
                                                    <path
                                                        d="M8 7C6.05 7 4.5 5.45 4.5 3.5C4.5 3.2 4.7 3 5 3C5.3 3 5.5 3.2 5.5 3.5C5.5 4.9 6.6 6 8 6C9.4 6 10.5 4.9 10.5 3.5C10.5 3.2 10.7 3 11 3C11.3 3 11.5 3.2 11.5 3.5C11.5 5.45 9.95 7 8 7Z" />
                                                </svg>
                                                Add to Cart
                                            </a>
                                        </div>
                                        <div class="product-card-content">
                                            <h6><a href="product-details.html">Luxury Beauty Item</a></h6>
                                            <span>$190.0</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="slider-btn-grp two">
                    <div class="slider-btn related-product-slider-prev">
                        <svg width="14" height="14" viewBox="0 0 14 14" xmlns="http://www.w3.org/2000/svg">
                            <g>
                                <path
                                    d="M11.002 13.0005C10.002 10.5005 5.00195 8.00049 2.00195 7.00049C5.00195 6.00049 9.50195 4.50049 11.002 1.00049"
                                    stroke-width="1.5" stroke-linecap="round"></path>
                            </g>
                        </svg>
                    </div>
                    <div class="slider-btn related-product-slider-next">
                        <svg width="14" height="14" viewBox="0 0 14 14" xmlns="http://www.w3.org/2000/svg">
                            <g>
                                <path
                                    d="M2.99805 13.0005C3.99805 10.5005 8.99805 8.00049 11.998 7.00049C8.99805 6.00049 4.49805 4.50049 2.99805 1.00049"
                                    stroke-width="1.5" stroke-linecap="round"></path>
                            </g>
                        </svg>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- Related Product Page End -->
@endsection
