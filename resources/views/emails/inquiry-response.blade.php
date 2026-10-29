<!doctype html>
<html lang="en">
<body style="font-family: Arial, sans-serif; color: #222; line-height: 1.5;">
    <p>Hello {{ $contactName }},</p>
    <div style="white-space: pre-wrap;">{{ $responseMessage }}</div>
    <p>Regarding inquiry {{ $inquiry->inquiry_number }}.</p>
</body>
</html>
