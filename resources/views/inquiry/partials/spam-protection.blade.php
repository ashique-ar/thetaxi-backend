<input type="hidden" name="_inquiry_form_token"
    value="{{ \Illuminate\Support\Facades\Crypt::encryptString((string) now()->timestamp) }}">
<div aria-hidden="true"
    style="position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden;">
    <label for="{{ $honeypotId ?? 'inquiry-company-website' }}">Company website</label>
    <input id="{{ $honeypotId ?? 'inquiry-company-website' }}" type="text" name="_inquiry_website"
        value="" tabindex="-1" autocomplete="off">
</div>
@if (config('services.turnstile.enabled'))
    <div class="cf-turnstile"
        data-sitekey="{{ config('services.turnstile.site_key') }}"
        data-action="public_inquiry"
        data-theme="auto"></div>
    @once
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    @endonce
@endif
