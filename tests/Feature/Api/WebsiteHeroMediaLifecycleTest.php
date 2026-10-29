<?php

use App\Http\Controllers\Api\Website\WebsiteSettingController;
use App\Models\Website\WebsiteSetting;
use App\Services\WebsiteSettingsService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('loads legacy uploads through the API and persists removals without reviving them', function () {
    expect(config('database.default'))->toBe('sqlite')
        ->and(config('database.connections.sqlite.database'))->toBe(':memory:');
    (require database_path('migrations/2025_07_07_110140_create_website_settings_table.php'))->up();
    (require database_path('migrations/2025_07_07_110341_create_business_settings_table.php'))->up();
    Schema::table('website_settings', fn (Blueprint $table) => $table->uuid('company_id')->nullable());
    DB::table('website_settings')->insert([
        ['id' => 'legacy-slider', 'type' => 'theme_02_slider_images', 'value' => '[{"desktop":"first.jpg","mobile":"mobile.jpg"},{"desktop":"second.jpg"}]'],
        ['id' => 'legacy-banner', 'type' => 'banner_image', 'value' => 'older.jpg'],
    ]);

    $service = Mockery::mock(WebsiteSettingsService::class)->makePartial();
    $service->shouldReceive('resolveCurrentCompanyId')->andReturn(null);
    $this->app->instance(WebsiteSettingsService::class, $service);
    $controller = $this->app->make(WebsiteSettingController::class);
    $loaded = $controller->getCategory('homepage')->getData(true)['data'];
    expect(array_column($loaded['hero_slides'], 'desktop'))->toBe(['first.jpg', 'second.jpg'])
        ->and($loaded['hero_slides'][0]['mobile'])->toBe('mobile.jpg');

    $remaining = [$loaded['hero_slides'][1]];
    foreach ([$remaining, [['type' => 'image', 'desktop' => '', 'video' => '']]] as $slides) {
        $saved = json_encode($slides);
        $request = Request::create('/api/website-settings/category/homepage', 'PUT', [
            'settings' => ['hero_slides' => $saved],
        ]);
        $request->setUserResolver(fn () => (object) ['id' => 'editor']);
        $response = WebsiteSetting::withoutEvents(fn () => $controller->updateCategory($request, 'homepage'));
        expect($response->getStatusCode())->toBe(200)
            ->and(WebsiteSetting::getValue('hero_slides'))->toBe($saved);
        $reloaded = $controller->getCategory('homepage')->getData(true)['data'];
        expect($reloaded['hero_slides'])->toBe($saved)
            ->and(array_column(get_hero_slides($reloaded), 'desktop'))->toBe(
                $slides === $remaining ? ['second.jpg'] : []
            );
    }
});
