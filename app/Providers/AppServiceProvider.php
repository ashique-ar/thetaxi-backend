<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use App\Http\View\Composers\NavigationComposer;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Configure Passport
        Passport::loadKeysFrom(storage_path('oauth-keys'));
        Passport::tokensExpireIn(now()->addHours(24));
        Passport::refreshTokensExpireIn(now()->addDays(30));
        Passport::personalAccessTokensExpireIn(now()->addMonths(6));

        // Define gates for API authentication
        Gate::define('api-access', function ($user) {
            return $user->isActive();
        });

        Relation::morphMap([
            'driver' => \App\Models\Driver\Driver::class,
            'customer' => \App\Models\Customer::class,
            'vehicle_owner' => \App\Models\Vehicle\VehicleOwner::class,
            'staff' => \App\Models\Staff::class,
        ]);

        // Register view composers for navigation and footer
        View::composer(['partials.header', 'partials.footer'], NavigationComposer::class);
    }
}
