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
    <!--  Style CSS  -->
    <link rel="stylesheet" href="{{ asset('assets/css/style.css?v=1.0.1') }}">
    
    <!-- Booking Form CSS -->
    <link rel="stylesheet" href="{{ asset('assets/css/booking-form.css?v=1.0.1') }}">

    <!-- Title -->
    <title>@yield('title', 'TheTaxi - Your Reliable Taxi Service')</title>
    <link rel="icon" href="{{ asset('assets/img/favicon.ico') }}" type="image/gif" sizes="20x20">

    @stack('styles')
    <style>
        :root {
            --primary-color1: #BF2629 !important;
            --black-color: #717171 !important;
        }
    </style>
</head>

<body class="tt-magic-cursor">

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

    @include('partials.header')

    @yield('content')

    @include('partials.footer')

    <script src="{{ asset('assets/js/jquery-3.7.1.min.js') }}"></script>
    <script src="{{ asset('assets/js/jquery-ui.js') }}"></script>
    <script src="{{ asset('assets/js/moment.min.js') }}"></script>
    <script src="{{ asset('assets/js/daterangepicker.min.js') }}"></script>

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
    <script src="{{ asset('assets/js/custom.js?v=1.0') }}"></script>
    <script src="{{ asset('assets/js/booking-form.js?v=1.0') }}"></script>

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
                    this.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Loading...';
                    
                    // Make AJAX request to switch currency
                    fetch('{{ route("currency.switch") }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
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
                        alert('An error occurred while switching currency. Please try again.');
                    });
                });
            });
        });
    </script>

    @stack('scripts')
</body>

</html>
