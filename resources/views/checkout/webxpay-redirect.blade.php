<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Redirecting to Payment Gateway...</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
            padding: 20px;
        }
        .container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            padding: 40px;
            text-align: center;
            max-width: 400px;
            width: 100%;
        }
        .spinner {
            border: 4px solid rgba(102, 126, 234, 0.2);
            border-top: 4px solid #667eea;
            border-radius: 50%;
            width: 60px;
            height: 60px;
            animation: spin 1s linear infinite;
            margin: 0 auto 24px;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        h1 {
            color: #333;
            font-size: 24px;
            font-weight: 600;
            margin: 0 0 12px;
        }
        p {
            color: #666;
            font-size: 14px;
            line-height: 1.6;
            margin: 0 0 24px;
        }
        .warning {
            background: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: 6px;
            padding: 12px;
            font-size: 13px;
            color: #856404;
            margin-top: 24px;
        }
        .order-id {
            font-family: 'Courier New', monospace;
            font-weight: bold;
            color: #667eea;
        }

        body.theme-theme-03 {
            position: relative;
            overflow: hidden;
            background: #eee7dc;
            color: #2f2924;
            font-family: Georgia, "Times New Roman", serif;
        }
        body.theme-theme-03::before {
            position: fixed;
            inset: 24px;
            border: 1px solid #cbbfb0;
            content: "";
            pointer-events: none;
        }
        body.theme-theme-03 .container {
            border: 1px solid #b8aa99;
            border-top: 7px solid #382f28;
            border-radius: 0;
            background: #fffaf2;
            box-shadow: 16px 16px 0 rgb(56 47 40 / 13%);
        }
        body.theme-theme-03 .spinner {
            border-color: rgb(140 63 50 / 18%);
            border-top-color: #8c3f32;
            border-radius: 0;
        }
        body.theme-theme-03 h1 { color: #2f2924; font-family: Georgia, "Times New Roman", serif; font-weight: 500; }
        body.theme-theme-03 p { color: #665c52; }
        body.theme-theme-03 .order-id { color: #8c3f32; }
        body.theme-theme-03 .warning { border-color: #cbbfb0; border-radius: 0; background: #f4ede2; color: #5a493b; }
        body.theme-theme-03 .continue-button { border-radius: 0 !important; background: #382f28 !important; }

        body.theme-theme-04 {
            position: relative;
            overflow: hidden;
            background: #f4f4f5;
            color: #17191d;
            font-family: Inter, "Segoe UI", Arial, sans-serif;
        }
        body.theme-theme-04::before,
        body.theme-theme-04::after {
            position: fixed;
            border: 1px solid #e3e4e7;
            border-radius: 50%;
            content: "";
        }
        body.theme-theme-04::before { top: -170px; right: -110px; width: 390px; height: 390px; }
        body.theme-theme-04::after { bottom: -130px; left: -90px; width: 280px; height: 280px; }
        body.theme-theme-04 .container {
            position: relative;
            z-index: 1;
            max-width: 460px;
            padding: 52px 46px;
            border: 1px solid #e3e4e7;
            border-top: 4px solid #d71920;
            border-radius: 10px;
            background: #fff;
            box-shadow: 0 18px 55px rgb(20 24 31 / 10%);
        }
        body.theme-theme-04 .spinner {
            width: 52px;
            height: 52px;
            border-color: #f6cacc;
            border-top-color: #d71920;
        }
        body.theme-theme-04 h1 {
            color: #17191d;
            font-family: Georgia, "Times New Roman", serif;
            font-size: 30px;
            font-weight: 500;
        }
        body.theme-theme-04 p { color: #656970; }
        body.theme-theme-04 .order-id { color: #b40f16; }
        body.theme-theme-04 .warning {
            border: 1px solid #e3e4e7;
            border-left: 4px solid #d71920;
            border-radius: 7px;
            background: #f7f7f8;
            color: #4c5057;
        }
        body.theme-theme-04 .continue-button { background: #d71920 !important; border-radius: 7px !important; }

        @media (prefers-reduced-motion: reduce) {
            body.theme-theme-03 .spinner,
            body.theme-theme-04 .spinner { animation-duration: 1.6s; }
        }
    </style>
</head>
<body class="theme-{{ get_active_theme() }}">
    <div class="container">
        <div class="spinner"></div>
        <h1>Redirecting to Payment Gateway</h1>
        <p>Please wait while we securely connect you to WebXPay...</p>
        <p>Order ID: <span class="order-id">{{ $order_id }}</span></p>
        
        <form id="webxpay-form" action="{{ $payment_url }}" method="POST">
            {{-- Customer details --}}
            @foreach($customer_data as $key => $value)
                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
            @endforeach
            
            {{-- Encrypted payment data (RSA encrypted: order_id|amount) --}}
            <input type="hidden" name="payment" value="{{ $encrypted_payment }}">
            
            {{-- Secret key for WebXPay verification --}}
            <input type="hidden" name="secret_key" value="{{ $secret_key }}">
            
            {{-- Custom fields (base64 encoded: booking_id|payment_type|booking_number|customer_id) --}}
            <input type="hidden" name="custom_fields" value="{{ $custom_fields }}">
            
            {{-- Encryption method --}}
            <input type="hidden" name="enc_method" value="{{ $enc_method }}">

            @if (!empty($return_url))
                <input type="hidden" name="return_url" value="{{ $return_url }}">
            @endif

            @if (!empty($cancel_url))
                <input type="hidden" name="cancel_url" value="{{ $cancel_url }}">
            @endif

            @if (!empty($notify_url))
                <input type="hidden" name="notify_url" value="{{ $notify_url }}">
            @endif
            
            <noscript>
                <button type="submit" class="continue-button" style="
                    background: #667eea;
                    color: white;
                    border: none;
                    padding: 12px 24px;
                    border-radius: 6px;
                    font-size: 16px;
                    font-weight: 600;
                    cursor: pointer;
                    margin-top: 20px;
                ">Click here to continue to payment</button>
            </noscript>
        </form>

        <div class="warning">
            <strong>⚠️ Do not close this window</strong><br>
            You will be redirected automatically in a moment.
        </div>
    </div>

    <script>
        // Auto-submit form after a brief delay to show the loading UI
        setTimeout(function() {
            document.getElementById('webxpay-form').submit();
        }, 1500);
    </script>
</body>
</html>
