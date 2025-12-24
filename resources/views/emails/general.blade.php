<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ config('app.name') }}</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; background-color: #f4f4f4; margin: 0; padding: 0; }
        .container { max-width: 600px; margin: 20px auto; background: #ffffff; border-radius: 8px; overflow: hidden; }
        .header { background: #c91c23; color: #ffffff; padding: 24px 20px; text-align: center; }
        .header img { max-height: 44px; margin-bottom: 10px; }
        .header h1 { margin: 0; font-size: 24px; }
        .content { padding: 28px 20px; }
        .message { background: #f8f9fa; border-left: 4px solid #c91c23; padding: 16px; }
        .footer { background: #f8f9fa; padding: 18px; text-align: center; font-size: 13px; color: #666; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <img src="{{ asset('assets/img/header-logo.png') }}" alt="TheTaxi">
        <h1>{{ config('app.name', 'TheTaxi') }}</h1>
    </div>

    <div class="content">
        <div class="message">
            {!! nl2br(e($message)) !!}
        </div>
    </div>

    <div class="footer">
        <p style="margin: 0;">Best regards,</p>
        <p style="margin: 0; font-weight: bold;">{{ config('app.name', 'TheTaxi') }} Team</p>
        <p style="margin: 10px 0 0 0; font-size: 12px;">This is an automated email. Please do not reply directly to this message.</p>
    </div>
</div>
</body>
</html>
