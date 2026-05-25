<p>Hi,</p>
<p>The scheduled sitemap ping failed for the following endpoint.</p>
<ul>
    <li><strong>Endpoint:</strong> {{ $details['endpoint'] ?? 'n/a' }}</li>
    <li><strong>Tried URL:</strong> {{ $details['tried_url'] ?? 'n/a' }}</li>
    <li><strong>Status:</strong> {{ $details['status'] ?? 'n/a' }}</li>
    <li><strong>Sitemap:</strong> {{ $details['sitemap'] ?? 'n/a' }}</li>
    <li><strong>Generated at:</strong> {{ $details['generated_at'] ?? now() }}</li>
</ul>

<p>Please check the ping endpoint and Google/Bing Search Console for errors. If you'd like, you can enable Search
    Console API submission in the future for more reliable submissions.</p>

<p>Regards,<br>{{ $settings['brand_name'] ?? $settings['site_name'] ?? 'Company' }} system</p>
