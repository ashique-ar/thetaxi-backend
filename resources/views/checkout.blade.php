@extends('layouts.app')

@section('title', 'Checkout - TheTaxi')

@section('content')
    <!-- Start Breadcrumb section -->
    <div class="breadcrumb-section"
        style="background-image:linear-gradient(rgba(0, 0, 0, 0.3), rgba(0, 0, 0, 0.3)), url(assets/img/innerpages/breadcrumb-bg1.jpg);">
        <div class="container">
            <div class="banner-content">
                <h1>Checkout Page</h1>
                <ul class="breadcrumb-list">
                    <li><a href="index.html">Home</a></li>
                    <li>Checkout</li>
                </ul>
            </div>
        </div>
    </div>
    <!-- End Breadcrumb section -->

    <!-- Checkout Page Start-->
    <div class="checkout-page pt-100 mb-100">
        <div class="container">
            <div class="row g-lg-4 gy-5">
                <div class="col-lg-7">
                    <div class="checkout-form-wrapper">
                        <div class="checkout-form-title">
                            <h4>Billing Information</h4>
                        </div>
                        <div class="checkout-form">
                            <form>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-inner two mb-25">
                                            <label>Full Name*</label>
                                            <input type="text" placeholder="Daniel Scoot">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-inner two mb-25">
                                            <label>Phone Number*</label>
                                            <input type="text" placeholder="(212)+ 455 645 678">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-inner two mb-25">
                                            <label>Email Address <span>(Optional)</span></label>
                                            <input type="email" placeholder="info@gmail.com">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-inner two mb-25">
                                            <label>Your Location</label>
                                            <input type="text" placeholder="Type Location">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-inner two mb-25">
                                            <label>Street Address*</label>
                                            <input type="text" placeholder="Street address">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-inner two mb-25">
                                            <label>Postal Code*</label>
                                            <input type="text" placeholder="Postal code">
                                        </div>
                                    </div>
                                    <div class="col-md-12">
                                        <div class="form-inner two mb-25">
                                            <label>Short Notes*</label>
                                            <textarea placeholder="Write Something..."></textarea>
                                        </div>
                                    </div>
                                    <div class="col-md-12">
                                        <div class="form-inner2">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" value=""
                                                    id="contactCheck11">
                                                <label class="form-check-label" for="contactCheck11">
                                                    Save my information for next time when I purchased
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="checkout-form-wrapper">
                        <div class="checkout-form-title">
                            <h4>Order Summary</h4>
                        </div>
                        <div class="order-sum-area">
                            <form>
                                <div class="cart-menu">
                                    <div class="cart-body">
                                        <ul>
                                            <li class="single-item">
                                                <div class="item-area">
                                                    <div class="main-item">
                                                        <div class="item-img">
                                                            <img src="{{ asset('assets/img/innerpages/cart-img1.png') }}"
                                                                alt="">
                                                        </div>
                                                        <div class="content-and-quantity">
                                                            <div class="content">
                                                                <span>2 x $190.00</span>
                                                                <h6><a href="product-details.html">
                                                                        Casual Outfit Set</a></h6>
                                                            </div>
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
                                                        </div>
                                                    </div>
                                                    <button type="reset" class="close-btn"><i
                                                            class="bi bi-x"></i></button>
                                                </div>
                                            </li>
                                            <li class="single-item">
                                                <div class="item-area">
                                                    <div class="main-item">
                                                        <div class="item-img">
                                                            <img src="{{}}assets/img/innerpages/cart-img2.png')}}"
                                                                alt="">
                                                        </div>
                                                        <div class="content-and-quantity">
                                                            <div class="content">
                                                                <span>2 x $150</span>
                                                                <h6><a href="#">
                                                                        Luxury Beauty Item</a></h6>
                                                            </div>
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
                                                        </div>
                                                    </div>
                                                    <button type="reset" class="close-btn"><i
                                                            class="bi bi-x"></i></button>
                                                </div>
                                            </li>
                                        </ul>
                                    </div>
                                    <div class="cart-footer">
                                        <div class="pricing-area mb-40">
                                            <ul>
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
                                                    <strong>Total</strong>
                                                    <strong>$214.00</strong>
                                                </li>
                                            </ul>
                                        </div>
                                        <div class="choose-payment-method">
                                            <h6>Select Payment Method</h6>
                                            <div class="payment-option">
                                                <ul>
                                                    <li class="paypal active">
                                                        <img src="{{}}assets/img/innerpages/icon/payPal.svg"
                                                            alt="">
                                                        <div class="checked">
                                                            <i class="bi bi-check"></i>
                                                        </div>
                                                    </li>
                                                    <li class="stripe">
                                                        <img src="{{}}assets/img/innerpages/icon/stripe.svg"
                                                            alt="">
                                                        <div class="checked">
                                                            <i class="bi bi-check"></i>
                                                        </div>
                                                    </li>
                                                    <li class="offline">
                                                        <img src="{{}}assets/img/innerpages/icon/offline.svg"
                                                            alt="">
                                                        <div class="checked">
                                                            <i class="bi bi-check"></i>
                                                        </div>
                                                    </li>
                                                </ul>
                                            </div>
                                            <div class="pt-25" id="StripePayment" style="display: none;">
                                                <div class="row g-4">
                                                    <div class="col-md-12">
                                                        <div class="form-inner two">
                                                            <label>Card Number</label>
                                                            <input type="text" placeholder="1234 1234 1234 1234">
                                                        </div>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <div class="form-inner two">
                                                            <label>Expiry</label>
                                                            <input type="text" placeholder="MM/YY">
                                                        </div>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <div class="form-inner two">
                                                            <label>CVC</label>
                                                            <input type="text" placeholder="CVC">
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <button type="submit" class="primary-btn1">
                                            <span>
                                                Place Your Order
                                                <svg width="10" height="10" viewBox="0 0 10 10"
                                                    xmlns="http://www.w3.org/2000/svg">
                                                    <path
                                                        d="M9.73535 1.14746C9.57033 1.97255 9.32924 3.26406 9.24902 4.66797C9.16817 6.08312 9.25559 7.5453 9.70214 8.73633C9.84754 9.12406 9.65129 9.55659 9.26367 9.70215C8.9001 9.83849 8.4969 9.67455 8.32812 9.33398L8.29785 9.26367L8.19921 8.98438C7.73487 7.5758 7.67054 5.98959 7.75097 4.58203C7.77875 4.09598 7.82525 3.62422 7.87988 3.17969L1.53027 9.53027C1.23738 9.82317 0.762615 9.82317 0.469722 9.53027C0.176829 9.23738 0.176829 8.76262 0.469722 8.46973L6.83593 2.10254C6.3319 2.16472 5.79596 2.21841 5.25 2.24902C3.8302 2.32862 2.2474 2.26906 0.958003 1.79102L0.704097 1.68945L0.635738 1.65527C0.303274 1.47099 0.157578 1.06102 0.310542 0.704102C0.463655 0.347333 0.860941 0.170391 1.22363 0.28418L1.29589 0.310547L1.48828 0.387695C2.47399 0.751207 3.79966 0.827571 5.16601 0.750977C6.60111 0.670504 7.97842 0.428235 8.86132 0.262695L9.95312 0.0585938L9.73535 1.14746Z">
                                                    </path>
                                                </svg>
                                            </span>
                                            <span>
                                                Place Your Order
                                                <svg width="10" height="10" viewBox="0 0 10 10"
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
    <!--Checkout Page End-->
@endsection
