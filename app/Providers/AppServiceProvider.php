<?php

namespace App\Providers;

use App\Services\Courier\CourierProviderRegistry;
use App\Services\Courier\OrderAutoMatcher;
use App\Services\Shopify\ShopifyClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ShopifyClient::class);
        $this->app->singleton(CourierProviderRegistry::class);

        // Build the OrderAutoMatcher from config so its policy lives in
        // config/couriers.php, not in code.
        $this->app->singleton(OrderAutoMatcher::class, function ($app) {
            $cfg = $app['config']->get('couriers.auto_match', []);

            return new OrderAutoMatcher(
                strategies: (array) ($cfg['strategies'] ?? ['reference', 'phone']),
                overwriteManual: (bool) ($cfg['overwrite_manual'] ?? false),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
