@extends('layouts.app')

@section('title', 'Test Page')

@section('content')
    <div style="padding: 50px; text-align: center;">
        <h1>Test Page - Timeout Diagnostic</h1>
        <p style="font-size: 18px; margin: 20px 0;">This is a minimal page to test if basic rendering works.</p>
        
        <div style="background: #f0f0f0; padding: 20px; margin: 20px 0; border-radius: 5px;">
            <h3>Current Time: {{ now()->format('Y-m-d H:i:s') }}</h3>
            <p>If you can see this, basic page rendering works.</p>
        </div>

        <div style="background: #e8f5e9; padding: 20px; margin: 20px 0; border-radius: 5px;">
            <h3>Database Connection Test</h3>
            <p>Testing CMS Content count...</p>
            <p style="font-weight: bold;">CMS Contents in database: {{ $cmsCount ?? 'N/A' }}</p>
        </div>

        <div style="background: #e3f2fd; padding: 20px; margin: 20px 0; border-radius: 5px;">
            <h3>Settings Test</h3>
            <p style="font-weight: bold;">Settings loaded: {{ $settingsLoaded ? 'YES' : 'NO' }}</p>
            <p>Banner heading: {{ $settings['banner_heading'] ?? 'Not loaded' }}</p>
        </div>

        <hr style="margin: 40px 0;">
        <p><a href="/" style="font-size: 16px; text-decoration: none; color: #0066cc;">← Back to Homepage</a></p>
    </div>
@endsection
