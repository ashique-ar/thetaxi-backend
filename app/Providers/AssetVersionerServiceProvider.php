<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Helpers\AssetVersioner;

class AssetVersionerServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton('asset-versioner', function () {
            return new AssetVersioner();
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Register global Blade helper
        if (!function_exists('assetVersion')) {
            function assetVersion($path)
            {
                return AssetVersioner::version($path);
            }
        }

        // Register Blade directive
        \Blade::directive('assetVersion', function ($path) {
            return "<?php echo \\App\\Helpers\\AssetVersioner::version($path); ?>";
        });
    }
}
