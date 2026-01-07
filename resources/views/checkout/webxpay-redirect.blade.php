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
    </style>
</head>
<body>
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
                <button type="submit" style="
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
