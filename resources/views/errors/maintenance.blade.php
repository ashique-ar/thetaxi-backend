<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Maintenance</title>
    @if (($theme ?? 'default') === 'theme-03')
        <style>
            :root {
                color-scheme: light;
                font-family: "Segoe UI", Arial, sans-serif;
                --paper: #f5f0e7;
                --stone: #e8e0d3;
                --ink: #1d211f;
                --muted: #66706a;
                --accent: #8c3f32;
            }
            * { box-sizing: border-box; }
            body {
                min-width: 320px;
                margin: 0;
                background: var(--paper);
                color: var(--ink);
            }
            .t3-maintenance {
                display: grid;
                grid-template-columns: minmax(0, 1.15fr) minmax(320px, 0.85fr);
                min-height: 100vh;
            }
            .t3-maintenance__message {
                display: flex;
                flex-direction: column;
                justify-content: center;
                padding: clamp(48px, 9vw, 150px);
            }
            .t3-maintenance__eyebrow {
                color: var(--accent);
                font-size: 12px;
                font-weight: 700;
                letter-spacing: .16em;
                text-transform: uppercase;
            }
            h1 {
                max-width: 760px;
                margin: 18px 0 28px;
                font-family: Georgia, "Times New Roman", serif;
                font-size: clamp(48px, 8vw, 112px);
                font-weight: 500;
                letter-spacing: -.05em;
                line-height: .86;
            }
            p {
                max-width: 620px;
                margin: 0;
                color: var(--muted);
                font-size: clamp(16px, 1.6vw, 20px);
                line-height: 1.7;
            }
            .t3-maintenance__contact {
                padding-top: 32px;
                margin-top: 38px;
                border-top: 1px solid rgb(29 33 31 / 18%);
                font-size: 14px;
            }
            .t3-maintenance__contact a {
                color: var(--ink);
                font-weight: 700;
                text-underline-offset: 4px;
            }
            .t3-maintenance__signal {
                position: relative;
                display: grid;
                place-items: center;
                overflow: hidden;
                background: var(--ink);
                color: #fffdf8;
            }
            .t3-maintenance__signal::before,
            .t3-maintenance__signal::after {
                position: absolute;
                width: min(34vw, 480px);
                aspect-ratio: 1;
                border: 1px solid rgb(255 253 248 / 16%);
                content: "";
                transform: rotate(45deg);
            }
            .t3-maintenance__signal::after {
                width: min(21vw, 300px);
                border-color: rgb(255 253 248 / 28%);
            }
            .t3-maintenance__signal span {
                position: relative;
                z-index: 1;
                display: grid;
                place-items: center;
                width: 96px;
                height: 96px;
                border: 1px solid rgb(255 253 248 / 42%);
                color: #d69b70;
                font-family: Georgia, "Times New Roman", serif;
                font-size: 42px;
            }
            @media (max-width: 760px) {
                .t3-maintenance { grid-template-columns: 1fr; }
                .t3-maintenance__message { padding: 64px 24px; }
                .t3-maintenance__signal { min-height: 230px; }
                .t3-maintenance__signal::before { width: 250px; }
                .t3-maintenance__signal::after { width: 150px; }
            }
        </style>
    @elseif (($theme ?? 'default') === 'theme-04')
        <style>
            :root { color-scheme: light; font-family: "Segoe UI", Arial, sans-serif; --red: #d71920; --text: #17191d; --muted: #656970; --line: #e3e4e7; }
            * { box-sizing: border-box; }
            body { min-width: 320px; margin: 0; background: #f7f7f8; color: var(--text); }
            .t4-maintenance { display: grid; min-height: 100vh; padding: 32px; place-items: center; }
            .t4-maintenance__card { width: min(100%, 820px); padding: clamp(42px, 8vw, 90px); border: 1px solid var(--line); border-top: 5px solid var(--red); border-radius: 14px; background: #fff; box-shadow: 0 20px 65px rgb(20 24 31 / 10%); text-align: center; }
            .t4-maintenance__card span { color: var(--red); font-size: 11px; font-weight: 800; letter-spacing: .16em; text-transform: uppercase; }
            .t4-maintenance__card h1 { margin: 18px 0 22px; font-family: Georgia, "Times New Roman", serif; font-size: clamp(46px, 8vw, 88px); font-weight: 500; letter-spacing: -.04em; line-height: .92; }
            .t4-maintenance__card p { max-width: 620px; margin: 0 auto; color: var(--muted); font-size: 17px; line-height: 1.7; }
            .t4-maintenance__card .contact { margin-top: 28px; padding-top: 24px; border-top: 1px solid var(--line); font-size: 14px; }
            .t4-maintenance__card a { color: var(--red); font-weight: 700; text-underline-offset: 4px; }
            @media (max-width: 600px) { .t4-maintenance { padding: 16px; } .t4-maintenance__card { padding: 44px 22px; } }
        </style>
    @else
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
    @endif
</head>
<body>
    @if (($theme ?? 'default') === 'theme-03')
        <main class="t3-maintenance">
            <section class="t3-maintenance__message">
                <span class="t3-maintenance__eyebrow">A brief pause</span>
                <h1>Preparing the road ahead.</h1>
                <p>{{ $message ?? 'We are currently performing scheduled maintenance. Please check back soon.' }}</p>
                @if (!empty($settings['company_email']))
                    <p class="t3-maintenance__contact">Need help with an existing journey? <a href="mailto:{{ $settings['company_email'] }}">{{ $settings['company_email'] }}</a></p>
                @endif
            </section>
            <aside class="t3-maintenance__signal" aria-hidden="true"><span>03</span></aside>
        </main>
    @elseif (($theme ?? 'default') === 'theme-04')
        <main class="t4-maintenance">
            <section class="t4-maintenance__card">
                <span>Service update</span>
                <h1>Preparing your next journey.</h1>
                <p>{{ $message ?? 'We are currently performing scheduled maintenance. Please check back soon.' }}</p>
                @if (!empty($settings['company_email']))
                    <p class="contact">Need help with an existing booking? <a href="mailto:{{ $settings['company_email'] }}">{{ $settings['company_email'] }}</a></p>
                @endif
            </section>
        </main>
    @else
        <div class="wrap">
            <div class="card">
                <h1>We are performing maintenance</h1>
                <p>{{ $message ?? 'We are currently performing scheduled maintenance. Please check back soon.' }}</p>
                @if (!empty($settings['company_email']))
                    <p class="contact">Need help? Email us at {{ $settings['company_email'] }}.</p>
                @endif
            </div>
        </div>
    @endif
</body>
</html>
