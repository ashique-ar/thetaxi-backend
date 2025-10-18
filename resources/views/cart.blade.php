@extends('layouts.app')

@section('title', 'Your Cart - TheTaxi')

@section('content')
    <!-- Start Breadcrumb section -->
    <div class="breadcrumb-section"
        style="background-image:linear-gradient(rgba(0, 0, 0, 0.3), rgba(0, 0, 0, 0.3)), url(assets/img/innerpages/breadcrumb-bg1.jpg);">
        <div class="container">
            <div class="banner-content">
                <h1>Cart Page</h1>
                <ul class="breadcrumb-list">
                    <li><a href="index.html">Home</a></li>
                    <li>Cart</li>
                </ul>
            </div>
        </div>
    </div>
    <!-- End Breadcrumb section -->

    <!-- Cart Page Start-->
    <div class="cart-page pt-100 mb-100">
        <div class="container">
            <div class="row g-lg-4 gy-5">
                <div class="col-xl-8 col-lg-7">
                    <div class="cart-shopping-wrapper">
                        <div class="cart-widget-title">
                            <h4>My Shopping</h4>
                        </div>
                        <table class="cart-table">
                            <thead>
                                <tr>
                                    <th>Product Info</th>
                                    <th>Price</th>
                                    <th>Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td data-label="Product Info">
                                        <div class="product-info-wrapper">
                                            <div class="product-info-img">
                                                <img src="{{ asset('assets/img/innerpages/cart-img1.png') }}"
                                                    alt="">
                                            </div>
                                            <div class="product-info-content">
                                                <h6>SwiftMove Travel Wear</h6>
                                                <p><span>SKU: </span>D32-5H23</p>
                                                <ul>
                                                    <li>remove</li>
                                                    <li>
                                                        <div class="qty-btn">quantity</div>
                                                        <div class="quantity-area">
                                                            <div class="quantity">
                                                                <a class="quantity__minus"><span><i
                                                                            class="bi bi-dash"></i></span></a>
                                                                <input name="quantity" type="text"
                                                                    class="quantity__input" value="01">
                                                                <a class="quantity__plus"><span><i
                                                                            class="bi bi-plus"></i></span></a>
                                                            </div>
                                                        </div>
                                                    </li>
                                                </ul>
                                            </div>
                                        </div>
                                    </td>
                                    <td data-label="Price"><span>$148.00</span></td>
                                    <td data-label="Total">$148.00</td>
                                </tr>
                                <tr>
                                    <td data-label="Product Info">
                                        <div class="product-info-wrapper">
                                            <div class="product-info-img">
                                                <img src="{{ asset('assets/img/innerpages/cart-img2.png') }}"
                                                    alt="">
                                            </div>
                                            <div class="product-info-content">
                                                <h6>GlobeTrek Outfit</h6>
                                                <p><span>SKU: </span>D32-5H23</p>
                                                <ul>
                                                    <li>remove</li>
                                                    <li>
                                                        <div class="qty-btn">quantity</div>
                                                        <div class="quantity-area">
                                                            <div class="quantity">
                                                                <a class="quantity__minus"><span><i
                                                                            class="bi bi-dash"></i></span></a>
                                                                <input name="quantity" type="text"
                                                                    class="quantity__input" value="01">
                                                                <a class="quantity__plus"><span><i
                                                                            class="bi bi-plus"></i></span></a>
                                                            </div>
                                                        </div>
                                                    </li>
                                                </ul>
                                            </div>
                                        </div>
                                    </td>
                                    <td data-label="Price"><span>$200.00</span></td>
                                    <td data-label="Total">$200.00</td>
                                </tr>
                            </tbody>
                        </table>
                        <a href="shop.html" class="details-button">
                            Continue Shoping
                            <svg width="10" height="10" viewBox="0 0 10 10" xmlns="http://www.w3.org/2000/svg">
                                <path d="M1 9L9 1M9 1C7.22222 1.33333 3.33333 2 1 1M9 1C8.66667 2.66667 8 6.33333 9 9"
                                    stroke-width="1.5" stroke-linecap="round" />
                            </svg>
                        </a>
                    </div>
                </div>
                <div class="col-xl-4 col-lg-5 ">
                    <div class="cart-order-sum-area">
                        <div class="cart-widget-title">
                            <h4>Order Summary</h4>
                        </div>
                        <div class="order-summary-wrap">
                            <ul class="order-summary-list">
                                <li>
                                    <strong>Sub Total</strong>
                                    <strong>$348.00</strong>
                                </li>
                                <li>
                                    Shipping
                                    <div class="order-info">
                                        <p>Shipping Free*</p>
                                        <span> Pickup fee $10.00</span>
                                    </div>
                                </li>
                                <li>
                                    <div class="coupon-area">
                                        <span>Coupon Code</span>
                                        <form>
                                            <div class="form-inner">
                                                <input type="text" placeholder="Your code">
                                                <button type="submit" class="apply-btn">Apply</button>
                                            </div>
                                        </form>
                                    </div>
                                </li>
                                <li>
                                    <strong>Total</strong>
                                    <strong>$214.00</strong>
                                </li>
                            </ul>
                            <a href="checkout.html" class="primary-btn1 mt-40">
                                <span>
                                    Processed Checkout
                                    <svg width="10" height="10" viewBox="0 0 10 10"
                                        xmlns="http://www.w3.org/2000/svg">
                                        <path
                                            d="M9.73535 1.14746C9.57033 1.97255 9.32924 3.26406 9.24902 4.66797C9.16817 6.08312 9.25559 7.5453 9.70214 8.73633C9.84754 9.12406 9.65129 9.55659 9.26367 9.70215C8.9001 9.83849 8.4969 9.67455 8.32812 9.33398L8.29785 9.26367L8.19921 8.98438C7.73487 7.5758 7.67054 5.98959 7.75097 4.58203C7.77875 4.09598 7.82525 3.62422 7.87988 3.17969L1.53027 9.53027C1.23738 9.82317 0.762615 9.82317 0.469722 9.53027C0.176829 9.23738 0.176829 8.76262 0.469722 8.46973L6.83593 2.10254C6.3319 2.16472 5.79596 2.21841 5.25 2.24902C3.8302 2.32862 2.2474 2.26906 0.958003 1.79102L0.704097 1.68945L0.635738 1.65527C0.303274 1.47099 0.157578 1.06102 0.310542 0.704102C0.463655 0.347333 0.860941 0.170391 1.22363 0.28418L1.29589 0.310547L1.48828 0.387695C2.47399 0.751207 3.79966 0.827571 5.16601 0.750977C6.60111 0.670504 7.97842 0.428235 8.86132 0.262695L9.95312 0.0585938L9.73535 1.14746Z">
                                        </path>
                                    </svg>
                                </span>
                                <span>
                                    Processed Checkout
                                    <svg width="10" height="10" viewBox="0 0 10 10"
                                        xmlns="http://www.w3.org/2000/svg">
                                        <path
                                            d="M9.73535 1.14746C9.57033 1.97255 9.32924 3.26406 9.24902 4.66797C9.16817 6.08312 9.25559 7.5453 9.70214 8.73633C9.84754 9.12406 9.65129 9.55659 9.26367 9.70215C8.9001 9.83849 8.4969 9.67455 8.32812 9.33398L8.29785 9.26367L8.19921 8.98438C7.73487 7.5758 7.67054 5.98959 7.75097 4.58203C7.77875 4.09598 7.82525 3.62422 7.87988 3.17969L1.53027 9.53027C1.23738 9.82317 0.762615 9.82317 0.469722 9.53027C0.176829 9.23738 0.176829 8.76262 0.469722 8.46973L6.83593 2.10254C6.3319 2.16472 5.79596 2.21841 5.25 2.24902C3.8302 2.32862 2.2474 2.26906 0.958003 1.79102L0.704097 1.68945L0.635738 1.65527C0.303274 1.47099 0.157578 1.06102 0.310542 0.704102C0.463655 0.347333 0.860941 0.170391 1.22363 0.28418L1.29589 0.310547L1.48828 0.387695C2.47399 0.751207 3.79966 0.827571 5.16601 0.750977C6.60111 0.670504 7.97842 0.428235 8.86132 0.262695L9.95312 0.0585938L9.73535 1.14746Z">
                                        </path>
                                    </svg>
                                </span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!--Cart Page End-->
@endsection
