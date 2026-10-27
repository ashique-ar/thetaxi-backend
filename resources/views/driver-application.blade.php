@extends('layouts.app')

@section('title', 'Driver application')

@push('styles')
<style>
.driver-onboarding {
    --do-accent: var(--primary-color1, #e31b23);
    --do-accent-strong: #c81018;
    --do-ink: #17191d;
    --do-muted: #656970;
    --do-line: #888;
    --do-soft: #f7f7f8;
    --do-radius: 12px;
    padding: clamp(36px, 6vw, 72px) 0;
    color: var(--do-ink);
    font-family: Inter, "Segoe UI", Arial, sans-serif;
}
.driver-onboarding *, .driver-onboarding *::before, .driver-onboarding *::after { box-sizing: border-box; }
.driver-onboarding__wrap { width: min(calc(100% - 48px), 1180px); margin-inline: auto; }
.driver-onboarding__intro { max-width: 740px; margin: 0 0 32px; }
.driver-onboarding__eyebrow { margin: 0 0 10px; color: var(--do-accent-strong); font-size: 12px; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; }
.driver-onboarding__intro h1 { margin: 0; font-size: clamp(30px, 4vw, 42px); font-weight: 750; letter-spacing: -.035em; line-height: 1.12; }
.driver-onboarding__intro p { margin: 14px 0 0; color: var(--do-muted); font-size: 16px; line-height: 1.6; }
.driver-onboarding__layout { display: grid; grid-template-columns: minmax(0, 1fr); align-items: start; gap: 32px; }
.driver-onboarding__layout--application { grid-template-columns: minmax(220px, 260px) minmax(0, 1fr); }
.driver-onboarding__rail { padding: 24px 20px; border: 1px solid var(--do-line); border-radius: var(--do-radius); background: var(--do-soft); }
.driver-onboarding__rail h2 { margin: 0; font-size: 17px; font-weight: 700; line-height: 1.35; }
.driver-onboarding__rail > p { margin: 6px 0 20px; color: var(--do-muted); font-size: 14px; line-height: 1.5; }
.driver-onboarding__steps { display: grid; gap: 0; margin: 0; padding: 0; list-style: none; }
.driver-onboarding__steps li { position: relative; display: grid; grid-template-columns: 32px minmax(0, 1fr); align-items: center; gap: 12px; min-height: 54px; color: var(--do-muted); font-size: 14px; font-weight: 600; }
.driver-onboarding__steps li:not(:last-child)::after { position: absolute; top: 42px; left: 15px; width: 2px; height: 22px; background: var(--do-line); content: ""; }
.driver-onboarding__step-number { display: grid; width: 32px; height: 32px; place-items: center; border: 2px solid var(--do-line); border-radius: 50%; background: #fff; color: var(--do-ink); font-size: 13px; font-weight: 700; }
.driver-onboarding__steps li[aria-current="step"] { color: var(--do-ink); }
.driver-onboarding__steps li[aria-current="step"] .driver-onboarding__step-number { border-color: var(--do-accent-strong); background: var(--do-accent-strong); color: #fff; }
.driver-onboarding__steps li.is-complete .driver-onboarding__step-number { border-color: var(--do-accent-strong); background: #fff; color: var(--do-accent-strong); }
.driver-onboarding__steps li.is-complete:not(:last-child)::after { background: var(--do-accent-strong); }
.driver-onboarding__needs { margin-top: 24px; padding-top: 20px; border-top: 1px solid var(--do-line); }
.driver-onboarding__needs h3 { margin: 0 0 10px; font-size: 14px; font-weight: 700; }
.driver-onboarding__needs ul { margin: 0; padding-left: 20px; color: var(--do-muted); font-size: 13px; line-height: 1.6; }
.driver-onboarding__panel { min-width: 0; padding: clamp(20px, 3.5vw, 40px); border: 1px solid var(--do-line); border-radius: var(--do-radius); background: #fff; }
.driver-onboarding__panel-heading { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 24px; }
.driver-onboarding__panel-heading h2 { margin: 0; font-size: 23px; font-weight: 700; letter-spacing: -.02em; line-height: 1.25; }
.driver-onboarding__panel-heading p { margin: 8px 0 0; color: var(--do-muted); font-size: 14px; line-height: 1.55; }
.driver-onboarding__step-count { flex: 0 0 auto; padding: 6px 10px; border: 1px solid var(--do-line); border-radius: 999px; color: var(--do-ink); font-size: 12px; font-weight: 700; }
.driver-onboarding__mobile-progress { display: none; }
.driver-onboarding__fields { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 20px 16px; }
.driver-onboarding__field { min-width: 0; }
.driver-onboarding fieldset { min-width: 0; margin: 0; padding: 0; border: 0; }
.driver-onboarding__field--wide { grid-column: 1 / -1; }
.driver-onboarding__field label, .driver-onboarding__field legend { display: block; margin: 0 0 7px; color: var(--do-ink); font-size: 14px; font-weight: 650; line-height: 1.4; }
.driver-onboarding__field input:not([type="checkbox"]):not([type="file"]), .driver-onboarding__field select { display: block; width: 100%; min-width: 0; max-width: 100%; min-height: 48px; padding: 10px 12px; border: 1px solid var(--do-line); border-radius: 8px; background-color: #fff; color: var(--do-ink); font: inherit; font-size: 16px; }
.driver-onboarding__field input[type="file"] { display: block; width: 100%; min-width: 0; max-width: 100%; padding: 10px; border: 1px dashed var(--do-line); border-radius: 8px; background: var(--do-soft); color: var(--do-ink); font: inherit; font-size: 14px; }
.driver-onboarding__field input:focus-visible, .driver-onboarding__field select:focus-visible, .driver-onboarding__button:focus-visible, .driver-onboarding__review button:focus-visible { outline: 3px solid var(--do-accent-strong); outline-offset: 3px; }
.driver-onboarding__help { display: block; margin-top: 6px; color: var(--do-muted); font-size: 13px; line-height: 1.45; }
.driver-onboarding__check { display: flex; align-items: flex-start; gap: 10px; padding: 12px 14px; border: 1px solid var(--do-line); border-radius: 8px; color: var(--do-ink); font-size: 14px; font-weight: 600; line-height: 1.4; }
.driver-onboarding__check input { width: 20px; height: 20px; margin: 0; accent-color: var(--do-accent-strong); }
.driver-onboarding__message { margin: 0 0 20px; padding: 12px 14px; border: 1px solid var(--do-line); border-left: 4px solid var(--do-accent-strong); border-radius: 8px; background: var(--do-soft); color: var(--do-ink); font-size: 14px; line-height: 1.5; }
.driver-onboarding__message[hidden] { display: none; }
.driver-onboarding__message.is-error { border-left-color: #a40000; }
.driver-onboarding__message.is-success { border-left-color: #176b3a; }
.driver-onboarding__actions { display: flex; justify-content: space-between; gap: 12px; margin-top: 28px; padding-top: 20px; border-top: 1px solid var(--do-line); }
.driver-onboarding__actions--end { justify-content: flex-end; }
.driver-onboarding__button { display: inline-flex; min-height: 48px; align-items: center; justify-content: center; gap: 8px; padding: 11px 20px; border: 1px solid var(--do-accent); border-radius: 8px; background: var(--do-accent); color: #fff; cursor: pointer; font: inherit; font-size: 15px; font-weight: 700; text-align: center; transition: background-color .16s ease, border-color .16s ease; }
.driver-onboarding__button:hover:not(:disabled) { border-color: var(--do-accent-strong); background: var(--do-accent-strong); }
.driver-onboarding__button:disabled { cursor: wait; opacity: .7; }
.driver-onboarding__button--secondary { border-color: var(--do-line); background: #fff; color: var(--do-ink); }
.driver-onboarding__button--secondary:hover:not(:disabled) { border-color: var(--do-ink); background: var(--do-soft); }
.driver-onboarding__otp-row { display: grid; grid-template-columns: minmax(0, 1fr) auto; align-items: end; gap: 12px; }
.driver-onboarding__otp-code { max-width: 220px; }
.driver-onboarding__doc-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
.driver-onboarding__doc { min-width: 0; padding: 16px; border: 1px solid var(--do-line); border-radius: 10px; background: #fff; }
.driver-onboarding__doc-heading { display: flex; justify-content: space-between; gap: 8px; margin-bottom: 12px; }
.driver-onboarding__doc-heading h3 { margin: 0; font-size: 15px; font-weight: 700; line-height: 1.4; }
.driver-onboarding__doc-status { color: var(--do-muted); font-size: 12px; font-weight: 650; text-align: right; }
.driver-onboarding__doc .driver-onboarding__field + .driver-onboarding__field { margin-top: 12px; }
.driver-onboarding__review { display: grid; gap: 12px; }
.driver-onboarding__review-group { padding: 16px; border: 1px solid var(--do-line); border-radius: 10px; }
.driver-onboarding__review-group h3 { display: flex; justify-content: space-between; gap: 12px; margin: 0 0 12px; font-size: 15px; font-weight: 700; }
.driver-onboarding__review-group h3 button { padding: 0; border: 0; background: none; color: var(--do-accent-strong); cursor: pointer; font: inherit; font-size: 13px; font-weight: 700; text-decoration: underline; }
.driver-onboarding__review-list { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px 20px; margin: 0; }
.driver-onboarding__review-list div { min-width: 0; }
.driver-onboarding__review-list dt { color: var(--do-muted); font-size: 12px; }
.driver-onboarding__review-list dd { overflow-wrap: anywhere; margin: 2px 0 0; color: var(--do-ink); font-size: 14px; font-weight: 600; }
.driver-onboarding__success { padding: 32px 20px; text-align: center; }
.driver-onboarding__success-mark { display: grid; width: 56px; height: 56px; place-items: center; margin: 0 auto 16px; border: 2px solid #176b3a; border-radius: 50%; color: #176b3a; font-size: 28px; font-weight: 800; }
.driver-onboarding__success h2 { margin: 0; font-size: 26px; }
.driver-onboarding__success p { max-width: 480px; margin: 10px auto 0; color: var(--do-muted); line-height: 1.6; }
.driver-onboarding [hidden] { display: none !important; }
@media (max-width: 900px) {
    .driver-onboarding__layout { grid-template-columns: minmax(0, 1fr); gap: 18px; }
    .driver-onboarding__rail { padding: 16px; }
    .driver-onboarding__rail h2, .driver-onboarding__rail > p, .driver-onboarding__needs { display: none; }
    .driver-onboarding__steps { grid-template-columns: repeat(5, minmax(0, 1fr)); }
    .driver-onboarding__steps li { display: flex; min-height: 0; flex-direction: column; gap: 7px; font-size: 11px; text-align: center; }
    .driver-onboarding__steps li:not(:last-child)::after { top: 15px; left: calc(50% + 20px); width: calc(100% - 40px); height: 2px; }
    .driver-onboarding__steps li span:last-child { max-width: 100%; }
}
@media (max-width: 560px) {
    .driver-onboarding { padding: 28px 0 40px; }
    .driver-onboarding__wrap { width: calc(100% - 32px); }
    .driver-onboarding__intro { margin-bottom: 20px; }
    .driver-onboarding__intro h1 { font-size: 30px; }
    .driver-onboarding__intro p { margin-top: 10px; font-size: 15px; }
    .driver-onboarding__rail { padding: 12px 10px; }
    .driver-onboarding__steps { display: none; }
    .driver-onboarding__mobile-progress { display: block; }
    .driver-onboarding__mobile-progress-top { display: flex; justify-content: space-between; gap: 12px; margin-bottom: 8px; color: var(--do-ink); font-size: 13px; font-weight: 700; }
    .driver-onboarding__progress-track { height: 6px; overflow: hidden; border-radius: 999px; background: #888; }
    .driver-onboarding__progress-fill { width: 0; height: 100%; border-radius: inherit; background: var(--do-accent-strong); transition: width .18s ease; }
    .driver-onboarding__panel { padding: 20px 16px; }
    .driver-onboarding__panel-heading { margin-bottom: 20px; }
    .driver-onboarding__panel-heading h2 { font-size: 21px; }
    .driver-onboarding__step-count { display: none; }
    .driver-onboarding__fields, .driver-onboarding__doc-grid, .driver-onboarding__review-list { grid-template-columns: minmax(0, 1fr); }
    .driver-onboarding__field--wide { grid-column: auto; }
    .driver-onboarding__otp-row { grid-template-columns: minmax(0, 1fr); }
    .driver-onboarding__otp-code { max-width: none; }
    .driver-onboarding__actions { position: sticky; bottom: 0; margin-inline: -16px; margin-bottom: -20px; padding: 14px 16px calc(16px + env(safe-area-inset-bottom)); border-top: 1px solid var(--do-line); background: #fff; }
    .driver-onboarding__actions .driver-onboarding__button { flex: 1; }
}
@media (prefers-reduced-motion: reduce) {
    .driver-onboarding__button, .driver-onboarding__progress-fill { transition: none; }
}
</style>
@endpush

@section('content')
<section class="driver-onboarding" aria-labelledby="driver-onboarding-title">
    <div class="driver-onboarding__wrap">
        <header class="driver-onboarding__intro">
            <p class="driver-onboarding__eyebrow">Driver application</p>
            <h1 id="driver-onboarding-title">Apply to become a driver</h1>
            <p>Verify your mobile number, then complete your personal, address, vehicle, and document details.</p>
        </header>

        <div class="driver-onboarding__layout">
            <aside class="driver-onboarding__rail" id="driver-progress" aria-label="Application progress" hidden>
                <h2>Your application</h2>
                <p>Complete each step, then review your information before sending it.</p>
                <ol class="driver-onboarding__steps">
                    <li data-progress-step="0"><span class="driver-onboarding__step-number">1</span><span>Personal details</span></li>
                    <li data-progress-step="1"><span class="driver-onboarding__step-number">2</span><span>Address</span></li>
                    <li data-progress-step="2"><span class="driver-onboarding__step-number">3</span><span>Vehicle</span></li>
                    <li data-progress-step="3"><span class="driver-onboarding__step-number">4</span><span>Documents</span></li>
                    <li data-progress-step="4"><span class="driver-onboarding__step-number">5</span><span>Review</span></li>
                </ol>
                <div class="driver-onboarding__mobile-progress" aria-hidden="true">
                    <div class="driver-onboarding__mobile-progress-top"><span id="mobile-step-name">Personal details</span><span id="mobile-step-count">Step 1 of 5</span></div>
                    <div class="driver-onboarding__progress-track"><div class="driver-onboarding__progress-fill" id="mobile-progress-fill"></div></div>
                </div>
                <div class="driver-onboarding__needs">
                    <h3>Have these ready</h3>
                    <ul><li>Your driver and vehicle details</li><li>Photos or scans of the required documents</li><li>Expiry dates for documents that need them</li></ul>
                </div>
            </aside>

            <div class="driver-onboarding__panel">
                <div id="driver-application-message" class="driver-onboarding__message" role="status" aria-live="polite" hidden></div>

                <section id="driver-otp-panel" aria-labelledby="driver-otp-title">
                    <div class="driver-onboarding__panel-heading">
                        <div><h2 id="driver-otp-title">Verify your mobile number</h2><p>We’ll text a six-digit code to confirm this number.</p></div>
                    </div>
                    <form id="driver-otp-form" novalidate>
                        <div class="driver-onboarding__fields">
                            <div class="driver-onboarding__field driver-onboarding__field--wide">
                                <label for="mobile">Mobile number</label>
                                <input id="mobile" name="mobile" type="tel" autocomplete="tel" inputmode="tel" maxlength="30" placeholder="Include your country code" required>
                                <span class="driver-onboarding__help">Use the number where you can receive SMS messages.</span>
                            </div>
                            <div class="driver-onboarding__field driver-onboarding__field--wide" id="otp-field" hidden>
                                <label for="otp">Verification code</label>
                                <div class="driver-onboarding__otp-row">
                                    <input class="driver-onboarding__otp-code" id="otp" name="otp" type="text" inputmode="numeric" pattern="[0-9]{6}" autocomplete="one-time-code" maxlength="6" aria-describedby="otp-help" required>
                                    <button class="driver-onboarding__button driver-onboarding__button--secondary" type="button" id="send-otp">Resend code</button>
                                </div>
                                <span class="driver-onboarding__help" id="otp-help">Enter the six-digit code we sent to your mobile.</span>
                            </div>
                        </div>
                        <div class="driver-onboarding__actions driver-onboarding__actions--end">
                            <button class="driver-onboarding__button" id="verify-mobile" type="submit">Send verification code</button>
                        </div>
                    </form>
                </section>

                <form id="driver-application-form" novalidate hidden>
                    <div class="driver-onboarding__panel-heading">
                        <div><h2 id="active-step-title" tabindex="-1">Personal details</h2><p id="active-step-description">Enter your name and identity details as they appear on your documents.</p></div>
                        <span class="driver-onboarding__step-count" id="step-count">Step 1 of 5</span>
                    </div>

                    <fieldset class="wizard-step driver-onboarding__fields" data-step="0" aria-labelledby="active-step-title">
                        <div class="driver-onboarding__field"><label for="first-name">First name</label><input id="first-name" name="first_name" autocomplete="given-name" maxlength="100" required></div>
                        <div class="driver-onboarding__field"><label for="last-name">Last name</label><input id="last-name" name="last_name" autocomplete="family-name" maxlength="100" required></div>
                        <div class="driver-onboarding__field"><label for="email">Email address</label><input id="email" name="email" type="email" autocomplete="email" maxlength="255" required></div>
                        <div class="driver-onboarding__field"><label for="nic">NIC</label><input id="nic" name="nic" autocomplete="off" pattern="(?:[0-9]{9}[vVxX]|[0-9]{12})" aria-describedby="nic-help" required><span class="driver-onboarding__help" id="nic-help">Enter 9 digits followed by V or X, or 12 digits.</span></div>
                    </fieldset>

                    <fieldset class="wizard-step driver-onboarding__fields" data-step="1" aria-labelledby="active-step-title" hidden>
                        <div class="driver-onboarding__field driver-onboarding__field--wide"><label for="address">Address</label><input id="address" name="address" autocomplete="street-address" maxlength="500" required></div>
                        <div class="driver-onboarding__field"><label for="country">Country</label><select id="country" name="country_id" autocomplete="country-name" required><option value="">Choose a country</option></select></div>
                        <div class="driver-onboarding__field"><label for="state">State or province</label><select id="state" name="state_id" autocomplete="address-level1" required disabled><option value="">Choose a country first</option></select></div>
                        <div class="driver-onboarding__field"><label for="city">City</label><input id="city" name="city" autocomplete="address-level2" maxlength="100" required></div>
                        <div class="driver-onboarding__field"><label for="postal-code">Postal code <span class="driver-onboarding__help" style="display:inline">(optional)</span></label><input id="postal-code" name="postal_code" autocomplete="postal-code" maxlength="20"></div>
                    </fieldset>

                    <fieldset class="wizard-step driver-onboarding__fields" data-step="2" aria-labelledby="active-step-title" hidden>
                        <div class="driver-onboarding__field"><label for="make">Vehicle make</label><select id="make" name="make_id" required><option value="">Choose a make</option></select></div>
                        <div class="driver-onboarding__field" id="other-make-field" hidden><label for="other-make">Other make</label><input id="other-make" name="other_make" maxlength="255"></div>
                        <div class="driver-onboarding__field"><label for="model">Vehicle model</label><select id="model" name="model_id" required disabled><option value="">Choose a make first</option></select></div>
                        <div class="driver-onboarding__field" id="other-model-field" hidden><label for="other-model">Other model</label><input id="other-model" name="other_model" maxlength="255"></div>
                        <div class="driver-onboarding__field"><label for="model-year">Model year</label><input id="model-year" name="model_year" type="number" inputmode="numeric" min="1950" max="{{ now()->year + 1 }}" required></div>
                        <div class="driver-onboarding__field"><label for="color">Color</label><input id="color" name="color" maxlength="50" required></div>
                        <div class="driver-onboarding__field"><label for="registration-year">Registration year</label><input id="registration-year" name="registration_year" type="number" inputmode="numeric" min="1950" max="{{ now()->year + 1 }}" required></div>
                        <div class="driver-onboarding__field"><label for="license-plate">License plate</label><input id="license-plate" name="license_plate" autocomplete="off" maxlength="30" required></div>
                        <div class="driver-onboarding__field driver-onboarding__field--wide"><label class="driver-onboarding__check" for="is-owner"><input id="is-owner" name="is_owner" type="checkbox" value="1"><span>I own this vehicle</span></label></div>
                    </fieldset>

                    <fieldset class="wizard-step" data-step="3" aria-labelledby="active-step-title" hidden>
                        <p class="driver-onboarding__help" style="margin:0 0 16px">Upload each required document as a JPG, PNG, or PDF. Maximum file size: 10 MB.</p>
                        <div class="driver-onboarding__doc-grid" id="driver-documents"></div>
                    </fieldset>

                    <fieldset class="wizard-step" data-step="4" aria-labelledby="active-step-title" hidden>
                        <p class="driver-onboarding__help" style="margin:0 0 16px">Check the details below before submitting your application.</p>
                        <div class="driver-onboarding__review" id="review-summary"></div>
                    </fieldset>

                    <div class="driver-onboarding__actions" id="wizard-actions">
                        <button class="driver-onboarding__button driver-onboarding__button--secondary" id="previous-step" type="button" hidden>Back</button>
                        <button class="driver-onboarding__button" id="next-step" type="button">Continue</button>
                        <button class="driver-onboarding__button" id="submit-application" type="submit" hidden>Submit application</button>
                    </div>
                </form>

                <section id="application-success" class="driver-onboarding__success" aria-labelledby="success-title" hidden>
                    <div class="driver-onboarding__success-mark" aria-hidden="true">✓</div>
                    <h2 id="success-title" tabindex="-1">Application submitted</h2>
                    <p id="success-message">Your application has been sent for staff review.</p>
                </section>
            </div>
        </div>
    </div>
</section>

<script>
(() => {
    const api = @json(url('/api/driver'));
    const otpPanel = document.querySelector('#driver-otp-panel');
    const otpForm = document.querySelector('#driver-otp-form');
    const applicationForm = document.querySelector('#driver-application-form');
    const progress = document.querySelector('#driver-progress');
    const message = document.querySelector('#driver-application-message');
    const steps = [
        { title: 'Personal details', description: 'Enter your name and identity details as they appear on your documents.' },
        { title: 'Address', description: 'Tell us where you live so we can complete your application.' },
        { title: 'Vehicle details', description: 'Add the vehicle you will use as a driver.' },
        { title: 'Required documents', description: 'Upload the documents needed to review your application.' },
        { title: 'Review your application', description: 'Check your details and documents before submitting.' },
    ];
    const documents = [
        { type: 'driver_photo', label: 'Driver photograph' },
        { type: 'driver_license_front', label: 'Driving licence — front', numberLabel: 'Driving licence number', expiryLabel: 'Driving licence expiry date' },
        { type: 'driver_license_back', label: 'Driving licence — back' },
        { type: 'nic_front', label: 'NIC — front' },
        { type: 'nic_back', label: 'NIC — back' },
        { type: 'vehicle_insurance', label: 'Vehicle insurance', numberLabel: 'Insurance policy number', expiryLabel: 'Insurance policy expiry date' },
        { type: 'vehicle_revenue_license', label: 'Vehicle revenue licence', numberLabel: 'Revenue licence number', expiryLabel: 'Revenue licence expiry date' },
        { type: 'vehicle_registration', label: 'Vehicle registration' },
    ];
    let token = '';
    let activeStep = 0;
    let uploadedDocuments = new Set();
    let savedDocuments = new Map();

    const showMessage = (text, type = 'status') => {
        message.textContent = text;
        message.hidden = false;
        message.className = `driver-onboarding__message${type === 'error' ? ' is-error' : type === 'success' ? ' is-success' : ''}`;
        message.setAttribute('role', type === 'error' ? 'alert' : 'status');
    };
    const clearMessage = () => { message.hidden = true; message.textContent = ''; message.className = 'driver-onboarding__message'; };
    const request = async (path, options = {}) => {
        const isFormData = options.body instanceof FormData;
        const response = await fetch(`${api}/${path}`, {
            ...options,
            headers: { Accept: 'application/json', ...(isFormData ? {} : { 'Content-Type': 'application/json' }), ...(token ? { Authorization: `Bearer ${token}` } : {}), ...options.headers },
        });
        const data = await response.json();
        if (!response.ok) throw new Error(Object.values(data.errors || {}).flat().join(' ') || data.message || 'We could not save that step. Check your details and try again.');
        return data.data;
    };
    const setBusy = (button, busy, busyText, idleText) => {
        button.disabled = busy;
        button.textContent = busy ? busyText : idleText;
    };
    const setOptions = (select, rows, placeholder) => {
        select.replaceChildren(new Option(placeholder, ''));
        rows.forEach(row => select.add(new Option(row.name, row.id)));
    };
    const setValue = (name, value) => {
        const field = applicationForm.elements.namedItem(name);
        if (field && 'value' in field && value != null) field.value = value;
    };
    const loadReferences = async () => {
        const [countries, makes] = await Promise.all([request('onboarding/countries'), request('onboarding/makes')]);
        setOptions(document.querySelector('#country'), countries, 'Choose a country');
        setOptions(document.querySelector('#make'), makes, 'Choose a make');
        document.querySelector('#make').add(new Option('Other', 'other'));
    };
    const setOtherVehicleFields = (other) => {
        document.querySelector('#other-make-field').hidden = !other;
        document.querySelector('#other-model-field').hidden = !other;
        document.querySelector('#model').hidden = other;
        document.querySelector('#other-make').required = other;
        document.querySelector('#other-model').required = other;
        document.querySelector('#model').required = !other;
        if (other) document.querySelector('#model').value = '';
    };
    const populateApplication = async (application) => {
        const payload = application?.payload || {};
        const identity = payload.identity || {};
        const address = payload.address || {};
        const vehicle = payload.vehicle || {};
        Object.entries(identity).forEach(([key, value]) => setValue(key, value));
        Object.entries(address).forEach(([key, value]) => setValue(key, value));
        if (address.country_id) {
            document.querySelector('#country').value = address.country_id;
            const states = await request(`onboarding/countries/${address.country_id}/states`);
            setOptions(document.querySelector('#state'), states, 'Choose a state or province');
            document.querySelector('#state').disabled = false;
            setValue('state_id', address.state_id);
        }
        Object.entries(vehicle).forEach(([key, value]) => setValue(key, value));
        if (vehicle.other_make) {
            document.querySelector('#make').value = 'other';
            setOtherVehicleFields(true);
        } else if (vehicle.make_id) {
            document.querySelector('#make').value = vehicle.make_id;
            const models = await request(`onboarding/makes/${vehicle.make_id}/models`);
            setOptions(document.querySelector('#model'), models, 'Choose a model');
            document.querySelector('#model').disabled = false;
            setValue('model_id', vehicle.model_id);
        }
        document.querySelector('#is-owner').checked = Boolean(vehicle.is_owner);
        savedDocuments = new Map((application?.documents || []).map(document => [document.document_type, document]));
        uploadedDocuments = new Set([...savedDocuments]
            .filter(([, document]) => document.status === 'pending')
            .map(([type]) => type));
        renderDocuments();
    };
    const renderDocuments = () => {
        const root = document.querySelector('#driver-documents');
        root.replaceChildren();
        for (const doc of documents) {
            const card = document.createElement('div');
            card.className = 'driver-onboarding__doc';
            const heading = document.createElement('div');
            heading.className = 'driver-onboarding__doc-heading';
            const title = document.createElement('h3');
            title.textContent = doc.label;
            const status = document.createElement('span');
            status.className = 'driver-onboarding__doc-status';
            status.dataset.docStatus = doc.type;
            status.setAttribute('aria-live', 'polite');
            status.textContent = uploadedDocuments.has(doc.type) ? 'Already on file' : 'Required';
            heading.append(title, status);
            card.append(heading);

            const fileField = document.createElement('div');
            fileField.className = 'driver-onboarding__field';
            const fileLabel = document.createElement('label');
            fileLabel.htmlFor = `document-${doc.type}`;
            fileLabel.textContent = uploadedDocuments.has(doc.type) ? 'Replace file (optional)' : 'Choose file';
            const input = document.createElement('input');
            input.id = `document-${doc.type}`;
            input.type = 'file';
            input.accept = '.jpg,.jpeg,.png,.pdf';
            input.dataset.document = doc.type;
            input.required = !uploadedDocuments.has(doc.type);
            const help = document.createElement('span');
            help.className = 'driver-onboarding__help';
            help.id = `document-help-${doc.type}`;
            help.dataset.fileName = doc.type;
            help.textContent = 'JPG, PNG, or PDF · Up to 10 MB';
            input.setAttribute('aria-describedby', help.id);
            input.addEventListener('change', () => {
                const file = input.files?.[0];
                if (file && file.size > 10 * 1024 * 1024) {
                    input.value = '';
                    help.textContent = 'That file is over 10 MB. Choose a smaller file.';
                    showMessage(`${doc.label}: choose a file that is 10 MB or smaller.`, 'error');
                    return;
                }
                help.textContent = file ? `Selected: ${file.name}` : 'JPG, PNG, or PDF · Up to 10 MB';
                clearMessage();
            });
            fileField.append(fileLabel, input, help);
            card.append(fileField);

            if (doc.numberLabel) {
                const numberField = document.createElement('div');
                numberField.className = 'driver-onboarding__field';
                const numberLabel = document.createElement('label');
                numberLabel.htmlFor = `number-${doc.type}`;
                numberLabel.textContent = doc.numberLabel;
                const numberInput = document.createElement('input');
                numberInput.id = `number-${doc.type}`;
                numberInput.dataset.number = doc.type;
                numberInput.maxLength = 100;
                numberInput.required = true;
                numberInput.value = savedDocuments.get(doc.type)?.document_number || '';
                numberInput.addEventListener('input', () => {
                    if (uploadedDocuments.has(doc.type) && numberInput.value !== (savedDocuments.get(doc.type)?.document_number || '')) {
                        input.required = true;
                        fileLabel.textContent = 'Choose replacement file';
                        status.textContent = 'Replace file to update';
                    }
                });
                numberField.append(numberLabel, numberInput);
                card.append(numberField);
            }
            if (doc.expiryLabel) {
                const expiryField = document.createElement('div');
                expiryField.className = 'driver-onboarding__field';
                const expiryLabel = document.createElement('label');
                expiryLabel.htmlFor = `expiry-${doc.type}`;
                expiryLabel.textContent = doc.expiryLabel;
                const expiryInput = document.createElement('input');
                expiryInput.id = `expiry-${doc.type}`;
                expiryInput.type = 'date';
                const tomorrow = new Date();
                tomorrow.setDate(tomorrow.getDate() + 1);
                expiryInput.min = `${tomorrow.getFullYear()}-${String(tomorrow.getMonth() + 1).padStart(2, '0')}-${String(tomorrow.getDate()).padStart(2, '0')}`;
                expiryInput.dataset.expiry = doc.type;
                expiryInput.required = true;
                expiryInput.value = savedDocuments.get(doc.type)?.expiry_date || '';
                expiryInput.addEventListener('change', () => {
                    if (uploadedDocuments.has(doc.type) && expiryInput.value !== (savedDocuments.get(doc.type)?.expiry_date || '')) {
                        input.required = true;
                        fileLabel.textContent = 'Choose replacement file';
                        status.textContent = 'Replace file to update';
                    }
                });
                expiryField.append(expiryLabel, expiryInput);
                card.append(expiryField);
            }
            root.append(card);
        }
    };
    const renderReview = () => {
        const get = name => applicationForm.elements.namedItem(name)?.value?.trim() || '—';
        const make = get('make_id') === 'other' ? get('other_make') : document.querySelector('#make').selectedOptions[0]?.textContent || '—';
        const model = get('make_id') === 'other' ? get('other_model') : document.querySelector('#model').selectedOptions[0]?.textContent || '—';
        const groups = [
            { title: 'Personal details', step: 0, items: [['Name', `${get('first_name')} ${get('last_name')}`], ['Email', get('email')], ['NIC', get('nic')], ['Mobile', document.querySelector('#mobile').value.trim()]] },
            { title: 'Address', step: 1, items: [['Address', get('address')], ['Country', document.querySelector('#country').selectedOptions[0]?.textContent || '—'], ['State or province', document.querySelector('#state').selectedOptions[0]?.textContent || '—'], ['City', get('city')], ['Postal code', get('postal_code')]] },
            { title: 'Vehicle', step: 2, items: [['Make and model', `${make} ${model}`], ['Model year', get('model_year')], ['Color', get('color')], ['Registration year', get('registration_year')], ['License plate', get('license_plate')], ['Owner', document.querySelector('#is-owner').checked ? 'Yes' : 'No']] },
            { title: 'Documents', step: 3, items: documents.map(doc => [doc.label, uploadedDocuments.has(doc.type) ? 'On file' : document.querySelector(`[data-document="${doc.type}"]`)?.files?.[0]?.name || '—']) },
        ];
        const root = document.querySelector('#review-summary');
        root.replaceChildren();
        for (const group of groups) {
            const section = document.createElement('section');
            section.className = 'driver-onboarding__review-group';
            const heading = document.createElement('h3');
            heading.append(document.createTextNode(group.title));
            const edit = document.createElement('button');
            edit.type = 'button';
            edit.dataset.editStep = group.step;
            edit.textContent = 'Edit';
            heading.append(edit);
            const list = document.createElement('dl');
            list.className = 'driver-onboarding__review-list';
            for (const [label, value] of group.items) {
                const item = document.createElement('div');
                const term = document.createElement('dt'); term.textContent = label;
                const detail = document.createElement('dd'); detail.textContent = value;
                item.append(term, detail); list.append(item);
            }
            section.append(heading, list); root.append(section);
        }
    };
    const setStep = index => {
        activeStep = index;
        clearMessage();
        document.querySelectorAll('.wizard-step').forEach((fieldset, step) => { fieldset.hidden = step !== index; });
        document.querySelectorAll('[data-progress-step]').forEach((item, step) => {
            item.toggleAttribute('aria-current', step === index);
            if (step === index) item.setAttribute('aria-current', 'step');
            item.classList.toggle('is-complete', step < index);
        });
        document.querySelector('#active-step-title').textContent = steps[index].title;
        document.querySelector('#active-step-description').textContent = steps[index].description;
        document.querySelector('#step-count').textContent = `Step ${index + 1} of ${steps.length}`;
        document.querySelector('#mobile-step-name').textContent = steps[index].title;
        document.querySelector('#mobile-step-count').textContent = `Step ${index + 1} of ${steps.length}`;
        document.querySelector('#mobile-progress-fill').style.width = `${((index + 1) / steps.length) * 100}%`;
        document.querySelector('#previous-step').hidden = index === 0;
        document.querySelector('#next-step').hidden = index === steps.length - 1;
        document.querySelector('#submit-application').hidden = index !== steps.length - 1;
        if (index === steps.length - 1) renderReview();
        document.querySelector('#active-step-title').focus();
    };
    const saveStep = async index => {
        const value = name => applicationForm.elements.namedItem(name).value.trim();
        if (index === 0) return request('onboarding/steps/1', { method: 'PATCH', body: JSON.stringify({ first_name: value('first_name'), last_name: value('last_name'), email: value('email'), nic: value('nic') }) });
        if (index === 1) return request('onboarding/steps/3', { method: 'PATCH', body: JSON.stringify({ address: value('address'), country_id: value('country_id'), state_id: value('state_id'), city: value('city'), postal_code: value('postal_code') }) });
        if (index === 2) {
            const other = value('make_id') === 'other';
            return request('onboarding/steps/4', { method: 'PATCH', body: JSON.stringify({
                make_id: other ? null : value('make_id'), other_make: other ? value('other_make') : null,
                model_id: other ? null : value('model_id'), other_model: other ? value('other_model') : null,
                model_year: value('model_year'), color: value('color'), registration_year: value('registration_year'), license_plate: value('license_plate'), is_owner: document.querySelector('#is-owner').checked,
            }) });
        }
    };
    const uploadDocuments = async () => {
        let completed = uploadedDocuments.size;
        for (const doc of documents) {
            const fileInput = applicationForm.querySelector(`[data-document="${doc.type}"]`);
            if (!fileInput.files?.[0]) continue;
            showMessage(`Uploading document ${completed + 1} of ${documents.length}…`);
            const body = new FormData();
            body.append('document_type', doc.type);
            body.append('file', fileInput.files[0]);
            const number = applicationForm.querySelector(`[data-number="${doc.type}"]`);
            const expiry = applicationForm.querySelector(`[data-expiry="${doc.type}"]`);
            if (number) body.append('document_number', number.value.trim());
            if (expiry) body.append('expiry_date', expiry.value);
            await request('onboarding/documents', { method: 'POST', body });
            uploadedDocuments.add(doc.type);
            completed += 1;
            fileInput.value = '';
            document.querySelector(`[data-doc-status="${doc.type}"]`).textContent = 'Uploaded';
            fileInput.required = false;
            fileInput.previousElementSibling.textContent = 'Replace file (optional)';
            document.querySelector(`[data-file-name="${doc.type}"]`).textContent = 'Uploaded. Choose a replacement only if needed.';
        }
    };

    const sendCode = async button => {
        const mobile = document.querySelector('#mobile');
        if (!mobile.reportValidity()) return;
        clearMessage();
        const oldText = button.textContent;
        setBusy(button, true, 'Sending code…', oldText);
        try {
            await request('auth/request-otp', { method: 'POST', body: JSON.stringify({ mobile: mobile.value.trim() }) });
            document.querySelector('#otp-field').hidden = false;
            document.querySelector('#verify-mobile').textContent = 'Verify number';
            document.querySelector('#otp').focus();
            showMessage('Code sent. Check your messages and enter it above.');
        } catch (error) { showMessage(error.message, 'error'); }
        finally { setBusy(button, false, '', button.id === 'verify-mobile' ? (document.querySelector('#otp-field').hidden ? 'Send verification code' : 'Verify number') : 'Resend code'); }
    };

    document.querySelector('#send-otp').addEventListener('click', event => sendCode(event.currentTarget));
    otpForm.addEventListener('submit', async event => {
        event.preventDefault();
        const mobile = document.querySelector('#mobile');
        const otp = document.querySelector('#otp');
        if (!mobile.reportValidity()) return;
        if (document.querySelector('#otp-field').hidden) return sendCode(document.querySelector('#verify-mobile'));
        if (!otp.reportValidity()) return;
        const verifyButton = document.querySelector('#verify-mobile');
        setBusy(verifyButton, true, 'Verifying…', 'Verify number');
        clearMessage();
        try {
            const result = await request('auth/verify-otp', { method: 'POST', body: JSON.stringify({ mobile: mobile.value.trim(), otp: otp.value.trim() }) });
            if (result.flow !== 'registration') throw new Error('This number already has a driver account. Sign in through the driver app.');
            token = result.onboarding_token;
            if (result.application?.status && !['draft', 'changes_requested'].includes(result.application.status)) {
                otpPanel.hidden = true;
                progress.hidden = true;
                applicationForm.hidden = true;
                document.querySelector('#application-success').hidden = false;
                document.querySelector('#success-title').textContent = result.application.status === 'submitted' ? 'Application already submitted' : `Application ${result.application.status.replaceAll('_', ' ')}`;
                document.querySelector('#success-message').textContent = result.application.status === 'submitted'
                    ? 'Your application is already with staff for review.'
                    : 'This application cannot be edited here. Use the driver app for the next step.';
                document.querySelector('#success-title').focus();
                return;
            }
            await loadReferences();
            await populateApplication(result.application);
            otpPanel.hidden = true;
            progress.hidden = false;
            progress.parentElement.classList.add('driver-onboarding__layout--application');
            applicationForm.hidden = false;
            setStep(0);
            showMessage('Your number is verified. Your progress is saved as you continue.');
        } catch (error) { showMessage(error.message, 'error'); }
        finally { setBusy(verifyButton, false, '', document.querySelector('#otp-field').hidden ? 'Send verification code' : 'Verify number'); }
    });

    document.querySelector('#country').addEventListener('change', async event => {
        const state = document.querySelector('#state');
        state.disabled = true;
        if (!event.target.value) { state.replaceChildren(new Option('Choose a country first', '')); clearMessage(); return; }
        state.setAttribute('aria-busy', 'true');
        state.replaceChildren(new Option('Loading…', ''));
        showMessage('Loading state or province options…');
        try {
            const rows = await request(`onboarding/countries/${event.target.value}/states`);
            setOptions(state, rows, 'Choose a state or province');
            state.disabled = false;
            clearMessage();
        } catch (error) { state.replaceChildren(new Option('Could not load states', '')); showMessage(error.message, 'error'); }
        finally { state.removeAttribute('aria-busy'); }
    });
    document.querySelector('#make').addEventListener('change', async event => {
        const other = event.target.value === 'other';
        setOtherVehicleFields(other);
        const model = document.querySelector('#model');
        if (other || !event.target.value) { model.disabled = true; clearMessage(); return; }
        model.disabled = true;
        model.setAttribute('aria-busy', 'true');
        model.replaceChildren(new Option('Loading…', ''));
        showMessage('Loading vehicle models…');
        try { setOptions(model, await request(`onboarding/makes/${event.target.value}/models`), 'Choose a model'); model.disabled = false; clearMessage(); }
        catch (error) { model.replaceChildren(new Option('Could not load models', '')); showMessage(error.message, 'error'); }
        finally { model.removeAttribute('aria-busy'); }
    });
    document.querySelector('#previous-step').addEventListener('click', () => setStep(Math.max(0, activeStep - 1)));
    document.querySelector('#next-step').addEventListener('click', async event => {
        const button = event.currentTarget;
        const current = document.querySelector(`.wizard-step[data-step="${activeStep}"]`);
        const invalid = [...current.querySelectorAll('input, select')].find(field => !field.checkValidity());
        if (invalid) { invalid.reportValidity(); return; }
        clearMessage();
        setBusy(button, true, activeStep === 3 ? 'Uploading documents…' : 'Saving…', 'Continue');
        try {
            if (activeStep < 3) await saveStep(activeStep);
            if (activeStep === 3) await uploadDocuments();
            setStep(activeStep + 1);
        } catch (error) { showMessage(error.message, 'error'); }
        finally { setBusy(button, false, '', 'Continue'); }
    });
    document.querySelector('#review-summary').addEventListener('click', event => {
        const button = event.target.closest('[data-edit-step]');
        if (button) setStep(Number(button.dataset.editStep));
    });
    applicationForm.addEventListener('submit', async event => {
        event.preventDefault();
        if (activeStep !== 4) { document.querySelector('#next-step').click(); return; }
        const button = document.querySelector('#submit-application');
        setBusy(button, true, 'Submitting…', 'Submit application');
        clearMessage();
        try {
            await request('onboarding/submit', { method: 'POST', body: JSON.stringify({}) });
            applicationForm.hidden = true;
            progress.hidden = true;
            document.querySelector('#application-success').hidden = false;
            document.querySelector('#success-title').focus();
        } catch (error) { showMessage(error.message, 'error'); }
        finally { setBusy(button, false, '', 'Submit application'); }
    });
})();
</script>
@endsection
