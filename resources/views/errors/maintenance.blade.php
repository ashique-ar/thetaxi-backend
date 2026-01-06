<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Maintenance</title>
    <style>
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f5f5f5;
            color: #1f2933;
        }
        .wrap {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 32px;
        }
        .card {
            max-width: 640px;
            background: #ffffff;
            border-radius: 12px;
            padding: 32px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
            text-align: center;
        }
        h1 {
            margin: 0 0 12px;
            font-size: 28px;
        }
        p {
            margin: 0 0 16px;
            line-height: 1.6;
        }
        .contact {
            font-size: 14px;
            color: #6b7280;
        }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="card">
            <h1>We are performing maintenance</h1>
            <p>{{ $message ?? 'We are currently performing scheduled maintenance. Please check back soon.' }}</p>
            @if (!empty($settings['company_email']))
                <p class="contact">Need help? Email us at {{ $settings['company_email'] }}.</p>
            @endif
        </div>
    </div>
</body>
</html>
