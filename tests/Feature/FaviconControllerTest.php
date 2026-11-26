<?php

use App\Services\WebsiteSettingsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

function mockFaviconSettings(string $favicon): void
{
    $mock = Mockery::mock(WebsiteSettingsService::class)->makePartial();
    $mock->shouldReceive('getBrandingSettings')->andReturn(['brand_favicon' => $favicon]);
    $mock->shouldReceive('getSecuritySettings')->andReturn([
        'ssl_force' => false,
        'security_headers_enabled' => false,
        'rate_limiting_enabled' => false,
        'maintenance_mode' => false,
    ]);
    app()->instance(WebsiteSettingsService::class, $mock);
}

it('serves valid ico and png favicons when brand_favicon is webp', function (): void {
    Storage::fake('s3');
    Cache::flush();

    $im = imagecreatetruecolor(64, 64);
    ob_start();
    imagewebp($im);
    $webpBytes = ob_get_clean();
    imagedestroy($im);

    $path = 'media/favicons/test-favicon.webp';
    Storage::disk('s3')->put($path, $webpBytes);
    mockFaviconSettings($path);

    $ico = $this->get('/favicon.ico');
    $ico->assertOk();
    $ico->assertHeader('Content-Type', 'image/x-icon');
    expect(substr($ico->getContent(), 0, 4))->toBe("\x00\x00\x01\x00");

    $png = $this->get('/favicon.png');
    $png->assertOk();
    $png->assertHeader('Content-Type', 'image/png');
    expect(substr($png->getContent(), 0, 4))->toBe("\x89PNG");
    $dims = getimagesizefromstring($png->getContent());
    expect($dims[0])->toBe(192)->and($dims[1])->toBe(192);

    $apple = $this->get('/apple-touch-icon.png');
    $apple->assertOk();
    $apple->assertHeader('Content-Type', 'image/png');
    $appleDims = getimagesizefromstring($apple->getContent());
    expect($appleDims[0])->toBe(180)->and($appleDims[1])->toBe(180);
});

it('returns 404 for favicon routes when brand_favicon is not configured', function (): void {
    Cache::flush();
    mockFaviconSettings('');

    $this->get('/favicon.ico')->assertNotFound();
    $this->get('/favicon.png')->assertNotFound();
});
