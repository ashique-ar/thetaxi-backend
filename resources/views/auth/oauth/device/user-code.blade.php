<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Authorize a device</title>
</head>
<body>
    <main>
        <h1>Authorize a device</h1>
        <form method="GET" action="{{ route('passport.device') }}">
            <label for="user_code">Enter the code shown on your device</label>
            <input id="user_code" name="user_code" value="{{ old('user_code') }}" required autocomplete="one-time-code">
            @error('user_code')<p role="alert">{{ $message }}</p>@enderror
            <button type="submit">Continue</button>
        </form>
    </main>
</body>
</html>
